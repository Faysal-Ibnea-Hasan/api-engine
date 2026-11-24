<?php

namespace App\Http\Controllers\Analysis;

use App\Domain\Analysis\EndpointAnalyses;
use App\Models\EndpointAnalysis;

/**
 * The only shape an analysis leaves the server in. Without source access (QA, Viewer) every
 * evidence reference (file paths, hashes, line ranges) and every source line number is removed.
 * Class and method names stay, as in the API Explorer, which shows controller@action to all roles.
 */
final class AnalysisPresenter
{
    /** "(line 35)", "(path at line 22)", "at line 29" are source locations: not for roles without source access. */
    private static function withoutLines(string $text): string
    {
        return trim((string) preg_replace(['/\s*\((?:path )?at line \d+\)|\s*\(line \d+\)/', '/\s+at line \d+/'], ['', ''], $text));
    }

    /**
     * The workflow graph. Without source access: no evidence ids, no source expressions, no line numbers.
     *
     * @param  array<string, mixed>  $graph
     * @return array<string, mixed>
     */
    public static function graph(array $graph, bool $withSource): array
    {
        if ($withSource) {
            return $graph;
        }
        $graph['edges'] = array_map(function (array $e) {
            unset($e['evidence'], $e['expression']);
            isset($e['note']) && $e['note'] = self::withoutLines((string) $e['note']);

            return $e;
        }, $graph['edges'] ?? []);
        $graph['nodes'] = array_map(function (array $n) {
            $n['label'] = self::withoutLines((string) $n['label']);
            isset($n['fields']) && $n['fields'] = array_map(fn (array $f) => array_diff_key($f, ['expression' => true]), $n['fields']);

            return $n;
        }, $graph['nodes'] ?? []);

        return $graph;
    }

    /** @return array<string, mixed> */
    public static function analysis(EndpointAnalysis $a, bool $withSource): array
    {
        $result = $a->isFinal() ? $a->result : null;

        if ($result !== null && ! $withSource) {
            unset($result['evidence']);
            $strip = fn (array $facts) => array_map(function (array $fact) {
                unset($fact['evidence']);

                return $fact;
            }, $facts);
            $result['request']['fields'] = $strip($result['request']['fields']);
            $result['request']['headers'] = $strip($result['request']['headers']);
            $result['responses'] = $strip($result['responses']);
            $result['auth']['signals'] = $strip($result['auth']['signals']);
            $result['unresolved'] = array_map(fn (string $u) => self::withoutLines($u), $result['unresolved']);
            isset($result['auth']['reason']) && $result['auth']['reason'] = self::withoutLines((string) $result['auth']['reason']);
            $result['auth']['signals'] = array_map(function (array $s) {
                isset($s['note']) && $s['note'] = self::withoutLines((string) $s['note']);

                return $s;
            }, $result['auth']['signals']);
            isset($result['graph']) && $result['graph'] = self::graph($result['graph'], false);
        }

        return [
            'id' => $a->id,
            'status' => $a->status->value,
            'active' => $a->status->isActive(),
            'analyzerVersion' => $a->analyzer_version,
            'schemaVersion' => $a->schema_version,
            'snapshotId' => $a->source_snapshot_id,
            'fieldCount' => $a->field_count,
            'responseCount' => $a->response_count,
            'unresolvedCount' => $a->unresolved_count,
            'failureCode' => $a->failure_code,
            // Written by a worker running an older analyzer: shown, but offered for re-analysis.
            'stale' => EndpointAnalyses::isStale($a),
            'finishedAt' => $a->finished_at?->toIso8601String(),
            'result' => $result,
        ];
    }
}
