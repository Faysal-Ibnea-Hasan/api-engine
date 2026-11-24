<?php

namespace App\Domain\Docs;

/**
 * Turns one validated AnalysisResultV1 (plus the endpoint facts discovery recorded) into a documentation
 * document. Pure and deterministic: no database, network, AI settings or uploaded source; the same input
 * always gives the same output, so nothing time- or user-dependent goes into the document.
 */
final class StaticDocumentationBuilder
{
    public const VERSION = 'static-docs-v2'; // v2: no evidence in documentation

    public const SCHEMA_VERSION = '1.0';

    /** Header values copied as typed in the code; any other value could be a literal secret, so it becomes a placeholder. */
    private const PLAIN_HEADERS = ['accept', 'content-type', 'accept-language', 'x-requested-with'];

    /**
     * @param  array<string, mixed>  $result  validated AnalysisResultV1
     * @param  array{methods: list<string>, uri: string, domain: string|null, name: string|null, handler: string|null, group: string, middleware: list<string>}  $endpoint
     * @return array<string, mixed>
     */
    public function build(array $result, string $analysisStatus, array $endpoint): array
    {
        $contract = is_array($result['contract'] ?? null) ? $result['contract'] : null;
        $fields = $result['request']['fields'];
        $headers = array_map(fn (array $h) => [
            'name' => (string) $h['name'],
            'value' => self::headerValue($h),
            'required' => $h['required'] ?? 'unknown',
            'status' => $h['status'],
            'reason' => $h['reason'] ?? null,
        ], $result['request']['headers']);
        $authStatus = (string) $result['auth']['status'];

        $unknowns = array_map('strval', $result['unresolved']);
        foreach ([...($contract['success'] ?? []), ...($contract['errors'] ?? [])] as $r) {
            foreach ($r['holes'] ?? [] as $hole) {
                $unknowns[] = ($r['status'] ?? 'Computed status').' response, '.$hole;
            }
        }

        $notices = array_values(array_filter([
            ($analysisStatus === 'partial' || $result['unresolved'] !== []) ? 'partial' : null,
            $contract === null ? 'legacy_result' : null,
            ($contract['budget_reached'] ?? false) === true ? 'budget_reached' : null,
            in_array($authStatus, ['unknown', 'not_detected'], true) ? 'auth_unknown' : null,
        ]));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'builder_version' => self::VERSION,
            'analyzer_version' => (string) $result['analyzer_version'],
            'analysis_status' => $analysisStatus,
            'notices' => $notices,
            'overview' => [
                ...$endpoint,
                'auth' => ['status' => $authStatus, 'reason' => $result['auth']['reason'] ?? null],
                'dependencies' => $result['dependencies'],
            ],
            'request' => [
                'path' => self::fields($fields, 'path'),
                'query' => self::fields($fields, 'query'),
                'body' => self::fields($fields, 'body'),
                'query_or_body' => self::fields($fields, 'query_or_body'),
                'payload' => $contract['payload'] ?? null,
            ],
            'headers' => $headers,
            'validation' => array_values(array_map(fn (array $f) => [
                'name' => (string) $f['name'],
                'location' => $f['location'],
                'required' => $f['required'],
                'nullable' => (bool) ($f['nullable'] ?? false),
                'rules' => array_map(fn (array $r) => array_filter([
                    'rule' => $r['rule'].(($r['params'] ?? []) === [] ? '' : ':'.implode(',', $r['params'])),
                    'status' => $r['status'],
                    'note' => $r['note'] ?? null,
                ], fn ($v) => $v !== null), $f['rules'] ?? []),
                'status' => $f['status'],
            ], array_filter($fields, fn (array $f) => ($f['rules'] ?? []) !== []))),
            'curl' => $contract !== null
                ? ['command' => (string) $contract['curl'], 'source' => 'contract']
                : ['command' => self::curl($endpoint, $fields, $headers), 'source' => 'built'],
            'responses' => $contract === null ? null : [
                'success' => self::contractResponses($contract['success']),
                'errors' => self::contractResponses($contract['errors']),
            ],
            // Older analyzers (before laravel-ast-v6) only know statuses and top-level field names.
            'legacy_responses' => $contract !== null ? [] : array_map(fn (array $r) => [
                'status' => $r['status'] ?? null,
                'status_confidence' => $r['status_confidence'],
                'kind' => $r['kind'] ?? 'unknown',
                'resource' => $r['resource'] ?? null,
                'body' => $r['body'] ?? null,
                'reason' => $r['reason'] ?? null,
            ], $result['responses']),
            'unknowns' => array_values(array_unique($unknowns)),
        ];
    }

    /** sha256 of $value as canonical JSON (object keys sorted at every depth), stable across DB round trips. */
    public static function checksum(mixed $value): string
    {
        return hash('sha256', (string) json_encode(self::canonical($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** Single-quoted for POSIX shells: a ' inside becomes '\''. */
    public static function shellQuote(string $text): string
    {
        return "'".str_replace("'", "'\\''", $text)."'";
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        array_is_list($value) || ksort($value, SORT_STRING);

        return $value;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private static function fields(array $fields, string $location): array
    {
        return array_values(array_map(fn (array $f) => [
            'name' => (string) $f['name'],
            'required' => $f['required'],
            'nullable' => (bool) ($f['nullable'] ?? false),
            'types' => $f['types'] ?? [],
            'formats' => $f['formats'] ?? [],
            'status' => $f['status'],
            'binds' => $f['binds'] ?? null,
        ], array_filter($fields, fn (array $f) => $f['location'] === $location)));
    }

    /**
     * @param  list<array<string, mixed>>  $responses
     * @return list<array<string, mixed>>
     */
    private static function contractResponses(array $responses): array
    {
        // The internal shape tree (`body`) is dropped: example + notes carry the same facts for readers.
        return array_map(fn (array $r) => [
            'status' => $r['status'] ?? null,
            'status_source' => $r['status_source'] ?? 'unread',
            'kind' => $r['kind'] ?? 'json',
            'triggers' => $r['triggers'] ?? [],
            'example' => $r['example'] ?? null,
            'notes' => $r['notes'] ?? [],
            'holes' => $r['holes'] ?? [],
        ], $responses);
    }

    /** @param  array<string, mixed>  $header */
    private static function headerValue(array $header): string
    {
        $name = (string) $header['name'];
        $value = is_string($header['value'] ?? null) ? $header['value'] : null;

        return match (true) {
            $value !== null && preg_match('/^(?:\w+ )?<[^<>]+>$/', $value) === 1 => $value,
            strcasecmp($name, 'Authorization') === 0 => 'Bearer <token>',
            $value !== null && $value !== '' && in_array(strtolower($name), self::PLAIN_HEADERS, true) => $value,
            default => '<'.strtolower($name).'>',
        };
    }

    /**
     * A placeholder-only curl for results without a contract: {{base_url}}, <token>, <type> values.
     *
     * @param  array{methods: list<string>, uri: string}  $endpoint
     * @param  list<array<string, mixed>>  $fields
     * @param  array<array{name: string, value: string}>  $headers
     */
    private static function curl(array $endpoint, array $fields, array $headers): string
    {
        $method = array_values(array_diff($endpoint['methods'], ['HEAD']))[0] ?? 'GET';
        $readsQuery = in_array($method, ['GET', 'DELETE'], true);
        $placeholder = fn (array $f) => '<'.(($f['types'] ?? [])[0] ?? 'value').'>';
        // Nested names (items.*.id) need a structure the old result doesn't describe; only top-level ones are sent.
        $topLevel = array_filter($fields, fn (array $f) => ! str_contains((string) $f['name'], '.'));

        $query = [];
        $body = [];
        foreach ($topLevel as $f) {
            $location = $f['location'] === 'query_or_body' ? ($readsQuery ? 'query' : 'body') : $f['location'];
            $location === 'query' && $query[] = $f['name'].'='.$placeholder($f);
            $location === 'body' && $body[(string) $f['name']] = $f;
        }

        $url = '{{base_url}}'.preg_replace('/\{(\w+)\??\}/', '<$1>', $endpoint['uri']).($query === [] ? '' : '?'.implode('&', $query));
        $lines = ['curl -X '.$method.' '.self::shellQuote($url), '-H '.self::shellQuote('Accept: application/json')];
        foreach ($headers as $h) {
            strcasecmp($h['name'], 'Accept') === 0 || $lines[] = '-H '.self::shellQuote($h['name'].': '.$h['value']);
        }

        $multipart = array_filter($body, fn (array $f) => in_array('file', $f['types'] ?? [], true)) !== [];
        if ($multipart) {
            foreach ($body as $name => $f) {
                $lines[] = '-F '.self::shellQuote($name.'='.(in_array('file', $f['types'] ?? [], true) ? '@/path/to/file' : $placeholder($f)));
            }
        } elseif ($body !== []) {
            $lines[] = '-H '.self::shellQuote('Content-Type: application/json');
            $lines[] = '-d '.self::shellQuote((string) json_encode(array_map($placeholder, $body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return implode(" \\\n  ", $lines);
    }
}
