<?php

namespace App\Domain\Ai;

use App\Domain\Analysis\ClassIndex;
use App\Domain\Sources\SnapshotReader;
use App\Models\EndpointAnalysis;
use PhpToken;

/**
 * The user message for a workflow explanation: the stored trace, then the project's own traced
 * method bodies (comments stripped, secrets redacted), nearest the route first, within a token budget.
 * Never includes file paths or line numbers.
 */
final class WorkflowContext
{
    /** Code the project doesn't own, or calls that can't be followed: never sent. */
    private const SKIPPED_TYPES = ['library', 'unresolved'];

    /** @return array{user: string, omitted: list<string>} */
    public static function build(EndpointAnalysis $advanced, int $budgetTokens = 60000): array
    {
        $result = $advanced->result ?? [];
        $graph = $result['graph'] ?? [];
        $endpoint = $advanced->endpoint;

        $trace = [
            'route' => [
                'methods' => $endpoint?->methods,
                'uri' => $endpoint?->uri,
                'action' => $endpoint?->controller === null ? null : $endpoint->controller.'::'.$endpoint->action,
                'form_request' => array_values(array_filter($result['dependencies'] ?? [], fn ($d) => ($d['kind'] ?? null) === 'form_request'))[0]['class'] ?? null,
                // The validated fields and their rule names; rule parameters and evidence stay out.
                'fields' => array_map(fn ($f) => ['name' => $f['name'], 'rules' => array_column($f['rules'] ?? [], 'rule')], $result['request']['fields'] ?? []),
            ],
            'nodes' => array_map(fn ($n) => ['id' => $n['id'], 'type' => $n['type'], 'label' => $n['label']], $graph['nodes'] ?? []),
            'edges' => array_map(fn ($e) => ['from' => $e['from'], 'to' => $e['to'], 'kind' => $e['kind'], 'conditional' => $e['conditional'], 'async' => $e['async'], 'reason' => $e['reason']], $graph['edges'] ?? []),
            'coverage' => $graph['coverage'] ?? null,
        ];
        $user = "## Trace\n\n```json\n".json_encode($trace, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n```\n\n## Method code\n";

        $index = new ClassIndex(app(SnapshotReader::class), $advanced->snapshot, $advanced->application_root);
        $omitted = [];
        $full = false;
        foreach (self::ordered($graph) as $node) {
            $name = $node['class'].'::'.$node['method'];
            $code = $full ? null : self::methodCode($index, $node['class'], $node['method']);
            if ($code !== null) {
                preg_match_all('/`+/', $code, $runs); // a fence longer than any backtick run, so code can't close it
                $fence = str_repeat('`', max(3, 1 + max([0, ...array_map('strlen', $runs[0])])));
                $block = "\n### {$name}\n\n{$fence}php\n{$code}\n{$fence}\n";
                $full = strlen($user.$block) / 4 > $budgetTokens; // nearest first: after the first miss, everything farther is listed by name
            }
            if ($code === null || $full) {
                $omitted[] = $name;
            } else {
                $user .= $block;
            }
        }

        $user .= "\n## Not sent\n\n".($omitted === []
            ? "Every traced method was sent.\n"
            : count($omitted).(count($omitted) === 1 ? ' method was' : ' methods were')." not sent (names only):\n".implode('', array_map(fn ($n) => "- {$n}\n", $omitted)));

        return ['user' => mb_scrub($user, 'UTF-8'), 'omitted' => $omitted];
    }

    /**
     * The project's own method nodes, by BFS distance from the root, then by how many edges touch them.
     *
     * @param  array<string, mixed>  $graph
     * @return list<array{class: string, method: string}>
     */
    private static function ordered(array $graph): array
    {
        $edges = $graph['edges'] ?? [];
        $distance = [($graph['root'] ?? '') => 0];
        for ($queue = [$graph['root'] ?? '']; $queue !== [];) {
            $from = array_shift($queue);
            foreach ($edges as $e) {
                if ($e['from'] === $from && ! isset($distance[$e['to']])) {
                    $distance[$e['to']] = $distance[$from] + 1;
                    $queue[] = $e['to'];
                }
            }
        }
        $degree = fn (string $id) => count(array_filter($edges, fn ($e) => $e['from'] === $id || $e['to'] === $id));

        $nodes = array_values(array_filter($graph['nodes'] ?? [], fn ($n) => is_string($n['class'] ?? null) && is_string($n['method'] ?? null) && ! in_array($n['type'], self::SKIPPED_TYPES, true)));
        usort($nodes, fn ($a, $b) => [$distance[$a['id']] ?? PHP_INT_MAX, $degree($b['id'])] <=> [$distance[$b['id']] ?? PHP_INT_MAX, $degree($a['id'])]);

        return $nodes;
    }

    /** The method's source with comments stripped and secrets redacted; null when it can't be found. */
    private static function methodCode(ClassIndex $index, string $class, string $method): ?string
    {
        $info = $index->find($class);
        [$owner, $node] = ($info === null ? null : $index->method($info, $method)) ?? [null, null];
        if ($owner === null || $node === null || $node->getStartFilePos() < 0 || $node->getEndFilePos() < $node->getStartFilePos()) {
            return null;
        }
        $source = substr($owner->file->code, $node->getStartFilePos(), $node->getEndFilePos() - $node->getStartFilePos() + 1);

        $code = '';
        foreach (array_slice(PhpToken::tokenize('<?php '.$source), 1) as $token) {
            $code .= $token->is([T_COMMENT, T_DOC_COMMENT]) ? '' : $token->text;
        }

        return SecretRedactor::redact($code);
    }
}
