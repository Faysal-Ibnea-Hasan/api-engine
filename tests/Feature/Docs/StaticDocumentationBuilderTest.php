<?php

namespace Tests\Feature\Docs;

use App\Domain\Docs\StaticDocumentationBuilder;
use Tests\TestCase;

class StaticDocumentationBuilderTest extends TestCase
{
    private const ENDPOINT = [
        'methods' => ['GET', 'HEAD'], 'uri' => '/api/orders/{order}', 'domain' => null, 'name' => 'orders.show',
        'handler' => 'App\Http\Controllers\OrderController@show', 'group' => 'api', 'middleware' => ['api', 'auth:sanctum'],
    ];

    /** A result as analyzers before laravel-ast-v6 stored it: no `contract`. */
    private function legacyResult(array $override = []): array
    {
        return array_replace_recursive([
            'schema_version' => '1.0', 'snapshot_id' => 's1', 'endpoint_id' => 'e1', 'analyzer_version' => 'laravel-ast-v5',
            'request' => [
                'headers' => [['name' => 'Authorization', 'value' => 'Bearer <token>', 'required' => 'required', 'status' => 'inferred', 'reason' => 'checked by auth:sanctum', 'evidence' => ['ev-1']]],
                'fields' => [
                    ['name' => 'order', 'location' => 'path', 'required' => 'required', 'nullable' => false, 'types' => ['integer'], 'formats' => [], 'rules' => [], 'status' => 'verified', 'binds' => 'App\Models\Order', 'evidence' => ['ev-1']],
                    ['name' => 'include', 'location' => 'query', 'required' => 'optional', 'nullable' => false, 'types' => ['string'], 'formats' => [], 'rules' => [['rule' => 'In', 'params' => ['lines', 'customer'], 'status' => 'verified']], 'status' => 'verified', 'evidence' => ['ev-2']],
                ],
            ],
            'responses' => [
                ['status' => 200, 'status_confidence' => 'verified', 'kind' => 'resource', 'body' => ['fields' => [['name' => 'id', 'type' => 'integer', 'value' => null]]], 'reason' => null, 'evidence' => ['ev-2']],
                ['status' => 404, 'status_confidence' => 'inferred', 'kind' => 'framework', 'body' => null, 'reason' => 'route-model binding', 'evidence' => []],
            ],
            'auth' => ['status' => 'required', 'reason' => 'auth:sanctum verifies the token', 'signals' => []],
            'dependencies' => [],
            'evidence' => [
                ['id' => 'ev-1', 'path' => 'routes/api.php', 'sha256' => str_repeat('a', 64), 'symbol' => null, 'lines' => [5, 5], 'kind' => 'route'],
                ['id' => 'ev-2', 'path' => 'app/Http/Controllers/OrderController.php', 'sha256' => str_repeat('b', 64), 'symbol' => 'OrderController::show', 'lines' => [20, 24], 'kind' => 'action'],
            ],
            'unresolved' => [],
        ], $override);
    }

    public function test_the_same_input_gives_an_identical_document_and_checksum(): void
    {
        $builder = new StaticDocumentationBuilder;
        $result = $this->legacyResult();

        $this->assertSame($builder->build($result, 'completed', self::ENDPOINT), $builder->build($result, 'completed', self::ENDPOINT));

        // A database may hand JSON objects back with their keys reordered; the checksum doesn't change.
        $reordered = array_reverse($result, true);
        $reordered['auth'] = array_reverse($result['auth'], true);
        $this->assertSame(StaticDocumentationBuilder::checksum($result), StaticDocumentationBuilder::checksum($reordered));
        $this->assertNotSame(StaticDocumentationBuilder::checksum($result), StaticDocumentationBuilder::checksum($this->legacyResult(['analyzer_version' => 'x'])));
    }

    public function test_an_old_result_without_contract_gives_honest_docs_and_a_placeholder_curl(): void
    {
        $doc = (new StaticDocumentationBuilder)->build($this->legacyResult(), 'completed', self::ENDPOINT);

        $this->assertSame(['legacy_result'], $doc['notices']);
        $this->assertNull($doc['responses']);
        $this->assertSame([200, 404], array_column($doc['legacy_responses'], 'status'));
        $this->assertSame('order', $doc['request']['path'][0]['name']);
        $this->assertSame([['rule' => 'In:lines,customer', 'status' => 'verified']], $doc['validation'][0]['rules']);
        $this->assertSame('built', $doc['curl']['source']);
        $this->assertSame(
            "curl -X GET '{{base_url}}/api/orders/<order>?include=<string>' \\\n  -H 'Accept: application/json' \\\n  -H 'Authorization: Bearer <token>'",
            $doc['curl']['command'],
        );
        $this->assertStringNotContainsString('"evidence"', (string) json_encode($doc), 'documentation carries no evidence');
    }

    public function test_a_partial_analysis_is_flagged_and_its_unknowns_listed(): void
    {
        $doc = (new StaticDocumentationBuilder)->build(
            $this->legacyResult(['unresolved' => ['rules come from a variable (line 35)'], 'auth' => ['status' => 'unknown']]),
            'partial', self::ENDPOINT,
        );

        $this->assertSame(['partial', 'legacy_result', 'auth_unknown'], $doc['notices']);
        $this->assertSame(['rules come from a variable (line 35)'], $doc['unknowns']);
    }

    public function test_curl_quotes_shell_text_and_never_copies_literal_credentials(): void
    {
        $result = $this->legacyResult();
        $result['request']['headers'][] = ['name' => 'X-Api-Key', 'value' => 'sk_live_123', 'required' => 'required', 'status' => 'verified', 'reason' => null, 'evidence' => []];
        $result['request']['fields'][] = ['name' => "o'brien", 'location' => 'body', 'required' => 'required', 'nullable' => false, 'types' => ['string'], 'formats' => [], 'rules' => [], 'status' => 'verified', 'evidence' => []];
        $endpoint = ['methods' => ['POST'], 'uri' => "/api/it's"] + self::ENDPOINT;

        $curl = (new StaticDocumentationBuilder)->build($result, 'completed', $endpoint)['curl']['command'];

        $this->assertStringContainsString("'{{base_url}}/api/it'\\''s?include=<string>'", $curl);
        $this->assertStringContainsString("-d '{\"o'\\''brien\":\"<string>\"}'", $curl);
        $this->assertStringContainsString("-H 'X-Api-Key: <x-api-key>'", $curl);
        $this->assertStringNotContainsString('sk_live', $curl);

        // A POSIX shell reads the quoted words back exactly.
        $words = shell_exec('printf "%s\n" '.str_replace(" \\\n  ", ' ', substr($curl, 5)));
        $this->assertIsString($words);
        $this->assertContains("{{base_url}}/api/it's?include=<string>", explode("\n", $words));
        $this->assertContains('{"o\'brien":"<string>"}', explode("\n", $words));
    }

    public function test_a_contract_result_keeps_its_curl_and_drops_the_internal_shape_tree(): void
    {
        $result = $this->legacyResult(['analyzer_version' => 'laravel-ast-v6', 'contract' => [
            'curl' => "curl -X GET '{{base_url}}/api/orders/1'",
            'payload' => ['location' => 'query', 'example' => [], 'notes' => []],
            'success' => [['status' => 200, 'status_source' => 'exact', 'kind' => 'json', 'triggers' => ['the action returns this'], 'body' => ['k' => 'object'], 'example' => ['id' => 1], 'notes' => [['path' => '$.id', 'source' => 'example', 'types' => ['integer']]], 'holes' => ['$.name: not read']]],
            'errors' => [],
            'budget_reached' => true,
        ]]);

        $doc = (new StaticDocumentationBuilder)->build($result, 'completed', self::ENDPOINT);

        $this->assertSame(['budget_reached'], $doc['notices']);
        $this->assertSame(['command' => "curl -X GET '{{base_url}}/api/orders/1'", 'source' => 'contract'], $doc['curl']);
        $this->assertArrayNotHasKey('body', $doc['responses']['success'][0]);
        $this->assertSame('example', $doc['responses']['success'][0]['notes'][0]['source'], 'example values stay marked');
        $this->assertSame([], $doc['legacy_responses']);
        $this->assertSame(['200 response, $.name: not read'], $doc['unknowns']);
    }
}
