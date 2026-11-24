<?php

namespace Tests\Feature\Analysis;

use App\Domain\Analysis\AnalysisResultV1;
use App\Domain\Analysis\EndpointAnalyzer;
use App\Domain\Sources\SnapshotReader;
use App\Domain\Workspaces\WorkspaceRole;
use App\Models\ApiEndpoint;
use App\Models\Project;
use App\Models\RouteScan;
use App\Models\SourceSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AnalyzesFixtures;
use Tests\Concerns\BuildsSnapshots;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

class EndpointAnalyzerTest extends TestCase
{
    use AnalyzesFixtures, BuildsSnapshots, RefreshDatabase, WorksWithWorkspaces;

    private const CONTROLLER = 'Fixture\App\Http\Controllers\OrderController';

    private SourceSnapshot $snapshot;

    private RouteScan $scan;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('quarantine', ['throw' => true]);
        Storage::fake('snapshots', ['throw' => true]);
        $project = Project::factory()->create();
        $this->snapshot = $this->snapshotFrom($this->fixtureApp([
            'routes/api.php' => "<?php\nRoute::apiResource('orders', OrderController::class);\n",
        ]), $project, $this->memberOf(WorkspaceRole::Developer, $project->workspace));
        $this->scan = RouteScan::factory()->create(['source_snapshot_id' => $this->snapshot->id]);
    }

    /** @return array<string, mixed> */
    private function analyze(string $action, string $uri = '/api/orders', array $middleware = ['api'], array $extra = []): array
    {
        $endpoint = ApiEndpoint::factory()->create([
            'route_scan_id' => $this->scan->id, 'controller' => self::CONTROLLER, 'action' => $action,
            'uri' => $uri, 'middleware' => $middleware, 'source_path' => 'routes/api.php', 'source_line' => 2,
            ...$extra,
        ]);

        return app(EndpointAnalyzer::class)->analyze($this->snapshot, $endpoint, '.');
    }

    /** @return array<string, array<string, mixed>> */
    private static function fields(array $result): array
    {
        return collect($result['request']['fields'])->keyBy(fn ($f) => $f['location'].':'.$f['name'])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private static function statuses(array $result): array
    {
        return collect($result['responses'])->keyBy('status')->all();
    }

    public function test_envelope_has_every_v1_key_and_evidence_resolves_to_snapshot_lines(): void
    {
        $result = $this->analyze('store');

        foreach (['schema_version', 'snapshot_id', 'endpoint_id', 'analyzer_version', 'request', 'responses', 'dependencies', 'evidence', 'unresolved', 'auth'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame('1.0', $result['schema_version']);
        $this->assertSame($this->snapshot->id, $result['snapshot_id']);

        // Every evidence entry points at a real file of this snapshot, by its stored hash, inside its line count.
        $files = $this->snapshot->files()->get()->keyBy('path');
        foreach ($result['evidence'] as $ev) {
            $file = $files[$ev['path']] ?? $this->fail("evidence path {$ev['path']} is not in the snapshot");
            $this->assertSame($file->sha256, $ev['sha256']);
            $lines = substr_count((string) app(SnapshotReader::class)->read($this->snapshot, $ev['path']), "\n") + 1;
            $this->assertLessThanOrEqual($lines, $ev['lines'][1], $ev['path']);
            $this->assertLessThanOrEqual($ev['lines'][1], $ev['lines'][0]);
        }

        // Facts only reference evidence that exists.
        $ids = array_column($result['evidence'], 'id');
        foreach ([...$result['request']['fields'], ...$result['responses']] as $fact) {
            $this->assertEmpty(array_diff($fact['evidence'], $ids));
        }
    }

    public function test_form_request_fields_auth_and_resource_response(): void
    {
        $result = $this->analyze('store');
        $fields = self::fields($result);

        $this->assertSame('required', $fields['body:customer_id']['required'] ?? null, json_encode(array_keys($fields)));
        $this->assertSame('required', $result['auth']['status']);
        $this->assertSame('Bearer <token>', $result['request']['headers'][0]['value']);
        $this->assertContains(['kind' => 'form_request', 'class' => 'Fixture\App\Http\Requests\StoreOrderRequest'], $result['dependencies']);

        $responses = self::statuses($result);
        $this->assertSame('resource', $responses[201]['kind']);
        $this->assertSame('verified', $responses[201]['status_confidence']);
        $this->assertSame(['id', 'status', 'currency', 'lines'], array_column($responses[201]['body']['fields'], 'name'));
        $this->assertSame('inferred', $responses[422]['status_confidence']);
        $this->assertSame('inferred', $responses[401]['status_confidence']);
        $this->assertSame('completed', $result['status'], json_encode($result['unresolved']));
    }

    public function test_controller_middleware_respects_only_and_except(): void
    {
        $index = $this->analyze('index');
        $this->assertSame('not_required', $index['auth']['status'], 'auth:sanctum is ->except(index), and every other middleware was read');
        $this->assertArrayNotHasKey(401, self::statuses($index));

        $destroy = $this->analyze('destroy', '/api/orders/{order}', extra: ['methods' => ['DELETE'], 'route_identity' => hash('sha256', 'del')]);
        $this->assertSame('required', $destroy['auth']['status']);
        $this->assertContains('can:manage-orders', array_column($destroy['auth']['signals'], 'middleware'));
        $this->assertSame('verified', self::statuses($destroy)[204]['status_confidence']);
        $this->assertArrayHasKey(403, self::statuses($destroy));
    }

    public function test_query_and_input_reads_and_resource_collection(): void
    {
        $result = $this->analyze('index');
        $fields = self::fields($result);

        $this->assertSame('verified', $fields['query:per_page']['location_status']);
        $this->assertSame('inferred', $fields['query_or_body:search']['location_status']);
        // A read is not a rule: requiredness is never invented from it.
        $this->assertSame('unknown', $fields['query:per_page']['required']);
        $this->assertSame('resource_collection', $result['responses'][0]['kind']);
    }

    public function test_path_params_and_route_model_binding(): void
    {
        $result = $this->analyze('show', '/api/orders/{order}');
        $order = self::fields($result)['path:order'];

        $this->assertSame('required', $order['required']);
        $this->assertSame('verified', $order['status']);
        $this->assertSame('Fixture\App\Models\Order', $order['binds']);
        $this->assertSame('inferred', self::statuses($result)[404]['status_confidence']);
    }

    public function test_a_status_the_code_states_replaces_the_inferred_one(): void
    {
        $result = $this->analyze('cancel', '/api/orders/{order}');
        $notFound = array_values(array_filter($result['responses'], fn ($r) => $r['status'] === 404));

        $this->assertCount(1, $notFound);
        $this->assertSame('verified', $notFound[0]['status_confidence']);
    }

    public function test_inline_validate_with_built_rules_and_literal_json(): void
    {
        $result = $this->analyze('update', '/api/orders/{order}');
        $fields = self::fields($result);

        $this->assertSame('optional', $fields['body:note']['required']);
        $this->assertTrue($fields['body:note']['nullable']);
        $this->assertTrue($fields['body:priority']['sometimes']);
        $this->assertSame(['1', '5'], $fields['body:priority']['constraints']['between']);
        $this->assertSame('X-Lock', collect($result['request']['headers'])->firstWhere('name', 'X-Lock')['name'] ?? null);

        $responses = self::statuses($result);
        $this->assertSame('verified', $responses[200]['status_confidence']);
        $this->assertSame(['name' => 'message', 'type' => 'string', 'value' => 'Updated'], $responses[200]['body']['fields'][0]);
        $this->assertSame('verified', $responses[423]['status_confidence']);
        $this->assertSame('inferred', $responses[422]['status_confidence']);
    }

    public function test_validator_make_reports_what_the_code_returns_not_422(): void
    {
        $result = $this->analyze('legacy');
        $responses = self::statuses($result);

        $this->assertSame('required', self::fields($result)['body:phone']['required']);
        $this->assertArrayHasKey(403, $responses);
        $this->assertArrayNotHasKey(422, $responses, 'Validator::make does not throw; the app answers 403 itself');
        $this->assertSame('verified', self::fields($result)['body:receipt']['location_status']);
    }

    public function test_rules_from_a_controller_method_and_unrelated_arrays_are_not_rules(): void
    {
        $result = $this->analyze('otp');
        $fields = self::fields($result);

        $this->assertSame('required', $fields['body:phone']['required']);
        $this->assertSame(['sms', 'email'], $fields['body:channel']['constraints']['in']);
        $this->assertSame([], $result['unresolved'], 'the $headers array is not a rules array');
        $this->assertSame('completed', $result['status']);
    }

    public function test_dynamic_code_is_partial_with_unknowns_never_guessed(): void
    {
        $result = $this->analyze('dynamic');

        $this->assertSame('partial', $result['status']);
        $this->assertNotEmpty($result['unresolved']);
        $this->assertStringContainsString('by a computed name', implode(' ', $result['unresolved']), '$request->{$request->field} is a runtime read');
        $own = array_values(array_filter($result['responses'], fn ($r) => $r['kind'] !== 'framework'));
        $this->assertSame('unknown', $own[0]['status_confidence']);
        $this->assertNull($own[0]['body']);
    }

    public function test_delegated_form_request_rules_and_custom_validation_envelope_are_read(): void
    {
        $result = $this->analyze('delegated');
        $fields = self::fields($result);

        $this->assertSame('required', $fields['body:email']['required']);
        $this->assertSame('conditional', $fields['body:guest_id']['required']);
        $this->assertSame(['Nullable', 'Required'], array_column($fields['body:guest_id']['rules'], 'rule'));
        $this->assertSame([], $result['unresolved']);

        $validation = self::statuses($result)[422];
        $this->assertSame('verified', $validation['status_confidence']);
        $this->assertSame('validation_error', $validation['kind']);
        $body = collect($validation['body']['fields'])->keyBy('name');
        $this->assertSame('unprocessable_entity_422', $body['identical_code']['value']);
        $this->assertTrue($body['content']['value_known']);
        $this->assertNull($body['content']['value']);
        $this->assertSame(['code', 'message'], array_column($body['errors']['items'], 'name'));
    }

    public function test_malformed_php_gives_a_partial_valid_result_not_an_exception(): void
    {
        $project = Project::factory()->create();
        $snapshot = $this->snapshotFrom($this->fixtureApp([
            'app/Http/Controllers/BrokenController.php' => "<?php\nnamespace Fixture\\App\\Http\\Controllers;\n\nclass BrokenController\n{\n    public function index()\n    {\n        return response()->json(['ok' => true], 200)\n    }\n\n    public function other( {\n}\n",
        ]), $project, $this->memberOf(WorkspaceRole::Developer, $project->workspace));
        $endpoint = ApiEndpoint::factory()->create([
            'route_scan_id' => RouteScan::factory()->create(['source_snapshot_id' => $snapshot->id])->id,
            'controller' => 'Fixture\App\Http\Controllers\BrokenController', 'action' => 'index',
        ]);

        $result = app(EndpointAnalyzer::class)->analyze($snapshot, $endpoint, '.');

        $this->assertSame('partial', $result['status']);
        $this->assertNotEmpty($result['unresolved']);
        $this->assertSame([], AnalysisResultV1::problems($result));
    }

    public function test_missing_action_or_controller_is_partial_with_a_diagnostic_and_no_response(): void
    {
        $missing = $this->analyze('nope');
        $this->assertSame('partial', $missing['status']);
        $this->assertStringContainsString('nope was not found', implode(' ', $missing['unresolved']));
        $this->assertSame([], array_filter($missing['responses'], fn ($r) => $r['kind'] !== 'framework'));

        $closure = $this->analyze('index', extra: ['handler_type' => 'closure', 'controller' => null, 'action' => null]);
        $this->assertSame('partial', $closure['status']);
        $this->assertSame([], $closure['responses']);
    }
}
