<?php

namespace App\Domain\Docs;

/**
 * The Markdown form of a (presented) documentation document. Pure: it renders only what it is given,
 * so a document stripped for QA/Viewer gives Markdown without source locations.
 */
final class DocumentationMarkdown
{
    private const NOTICES = [
        'partial' => 'Partial: some parts could not be read statically; see Unknowns. Nothing was guessed.',
        'legacy_result' => 'Built from an older analysis without full response bodies. Analyze again for full response bodies.',
        'budget_reached' => 'The analysis hit its work budget, so some responses may be missing.',
        'auth_unknown' => "Authentication: can't tell from the code.",
    ];

    /** @param  array<string, mixed>  $doc */
    public static function render(array $doc): string
    {
        $o = $doc['overview'];
        $out = ['# '.implode(' ', $o['methods']).' '.($o['domain'] ?? '').$o['uri'], ''];
        foreach ($doc['notices'] as $n) {
            $out[] = '> **Note:** '.(self::NOTICES[$n] ?? $n);
        }

        $out[] = "\n## Overview\n";
        $out[] = '- Route name: '.($o['name'] ?? 'unnamed');
        $out[] = '- Handler: `'.($o['handler'] ?? '-').'`';
        $out[] = '- Authentication: '.$o['auth']['status'].($o['auth']['reason'] ? ' ('.$o['auth']['reason'].')' : '');
        $out[] = '- Middleware: '.($o['middleware'] === [] ? 'none found' : '`'.implode('`, `', $o['middleware']).'`');

        $out[] = "\n## Request\n";
        foreach (['path' => 'Path', 'query' => 'Query', 'body' => 'Body', 'query_or_body' => 'Query or body'] as $key => $label) {
            foreach ($doc['request'][$key] as $f) {
                $out[] = "- {$label} `{$f['name']}`: {$f['required']}".($f['types'] === [] ? '' : ', '.implode('|', $f['types'])).($f['status'] === 'verified' ? '' : " ({$f['status']})");
            }
        }
        if (($doc['request']['payload'] ?? null) !== null) {
            $out[] = "\nPayload ({$doc['request']['payload']['location']}), example values:\n";
            $out[] = self::json($doc['request']['payload']['example']);
        }

        $out[] = "\n## Headers\n";
        $out = [...$out, ...array_map(fn (array $h) => "- `{$h['name']}: {$h['value']}` ({$h['required']}, {$h['status']})", $doc['headers'])];
        $doc['headers'] === [] && $out[] = 'None found.';

        $out[] = "\n## Validation\n";
        foreach ($doc['validation'] as $v) {
            $out[] = "- `{$v['name']}`: ".implode(' | ', array_map(fn (array $r) => $r['rule'].($r['status'] === 'verified' ? '' : " ({$r['status']})"), $v['rules']));
        }
        $doc['validation'] === [] && $out[] = 'No rules found.';

        $out[] = "\n## cURL\n";
        $out[] = "```bash\n{$doc['curl']['command']}\n```";

        foreach (['success' => 'Responses', 'errors' => 'Errors'] as $key => $title) {
            $out[] = "\n## {$title}\n";
            if ($doc['responses'] !== null) {
                foreach ($doc['responses'][$key] as $r) {
                    $out[] = '### '.($r['status'] ?? 'Computed status').' '.implode('; ', $r['triggers'])."\n";
                    $out[] = $r['kind'] === 'empty' ? 'Empty body.' : self::json($r['example']);
                    $marked = array_filter($r['notes'], fn (array $n) => in_array($n['source'], ['example', 'unread'], true));
                    $out = [...$out, ...array_map(fn (array $n) => "- `{$n['path']}` is ".($n['source'] === 'example' ? 'an example value' : 'a typed placeholder').(isset($n['note']) ? ": {$n['note']}" : ''), $marked)];
                }
            } else {
                foreach ($doc['legacy_responses'] as $r) {
                    $isError = $r['status'] !== null && $r['status'] >= 400;
                    $isError === ($key === 'errors') && $out[] = '- '.($r['status'] ?? 'Computed status')." ({$r['status_confidence']})".($r['reason'] ? ": {$r['reason']}" : '');
                }
            }
        }

        $out[] = "\n## Unknowns\n";
        $out = [...$out, ...array_map(fn (string $u) => "- {$u}", $doc['unknowns'])];
        $doc['unknowns'] === [] && $out[] = 'Nothing was left unknown.';

        return implode("\n", $out)."\n";
    }

    private static function json(mixed $value): string
    {
        return "```json\n".json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n```";
    }
}
