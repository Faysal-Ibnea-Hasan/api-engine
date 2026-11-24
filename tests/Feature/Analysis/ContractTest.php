<?php

namespace Tests\Feature\Analysis;

use App\Domain\Analysis\AnalysisResultV1;
use App\Domain\Analysis\EndpointAnalyzer;
use App\Domain\Workspaces\WorkspaceRole;
use App\Models\ApiEndpoint;
use App\Models\Project;
use App\Models\RouteScan;
use App\Models\SourceSnapshot;
use ContractFixture\App\Http\Controllers\ProductController;
use ContractFixture\App\Http\Middleware\EnforceEnvelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSnapshots;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

/**
 * Response contracts followed through the app's own envelope, resources, config, after-middleware,
 * FormRequest override and exception renderer. Bodies the code fully states must come out exactly;
 * the parity tests run the same trusted fixture code in Laravel and compare.
 */
class ContractTest extends TestCase
{
    use BuildsSnapshots, RefreshDatabase, WorksWithWorkspaces;

    private const CONTROLLER = 'ContractFixture\App\Http\Controllers\ProductController';

    private SourceSnapshot $snapshot;

    private RouteScan $scan;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('quarantine', ['throw' => true]);
        Storage::fake('snapshots', ['throw' => true]);

        $files = [
            'composer.json' => json_encode(['require' => ['laravel/framework' => '^11.0'], 'autoload' => ['psr-4' => ['ContractFixture\\App\\' => 'app/']]]),
            'composer.lock' => json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v11.30.0']]]),
            'artisan' => "<?php\n",
            'routes/api.php' => "<?php\n",
        ];
        foreach (File::allFiles(base_path('tests/Fixtures/contract')) as $file) {
            $files[$file->getRelativePathname()] = $file->getContents();
        }
        $project = Project::factory()->create();
        $this->snapshot = $this->snapshotFrom($files, $project, $this->memberOf(WorkspaceRole::Developer, $project->workspace));
        $this->scan = RouteScan::factory()->create(['source_snapshot_id' => $this->snapshot->id]);
    }

    /** @return array<string, mixed> */
    private function contract(string $action, string $method = 'GET'): array
    {
        $endpoint = ApiEndpoint::factory()->create([
            'route_scan_id' => $this->scan->id, 'controller' => self::CONTROLLER, 'action' => $action,
            'uri' => "/api/products/{$action}", 'methods' => [$method], 'middleware' => ['api'], 'without_middleware' => [],
            'route_identity' => hash('sha256', $action.uniqid()),
        ]);
        $result = app(EndpointAnalyzer::class)->analyze($this->snapshot, $endpoint, '.');
        $this->assertSame([], AnalysisResultV1::problems($result));

        return $result['contract'];
    }

    /** @return array<string, mixed> */
    private static function byStatus(array $responses, int $status): array
    {
        foreach ($responses as $r) {
            if ($r['status'] === $status) {
                return $r;
            }
        }
        self::fail("no {$status} response in ".json_encode(array_column($responses, 'status')));
    }

    public function test_an_envelope_with_a_resource_is_exact(): void
    {
        $ok = self::byStatus($this->contract('show')['success'], 200);

        $this->assertSame([
            'identical_code' => 'default_200',
            'message' => 'Successfully fetched',
            // BaseResource::toArray() returns [], so parent::toArray() adds nothing.
            'content' => ['id' => 7, 'name' => 'Tea', 'tags' => ['featured' => true], 'currency' => 'USD'],
            'errors' => [],
        ], $ok['example']);
        $this->assertSame([], array_values(array_filter($ok['notes'], fn ($n) => $n['source'] !== 'exact')), 'every value here is stated by the code');
        $this->assertSame([], $ok['holes']);
    }

    public function test_after_middleware_wraps_a_plain_body(): void
    {
        $ok = self::byStatus($this->contract('ping')['success'], 200);

        $this->assertSame(['identical_code' => 'default_200', 'message' => 'Successfully fetched', 'content' => ['pong' => true, 'version' => 2], 'errors' => []], $ok['example']);
        $this->assertCount(1, $this->contract('ping')['success'], 'an envelope is never wrapped twice');
    }

    public function test_array_union_keeps_the_left_message_and_the_right_status(): void
    {
        $ok = self::byStatus($this->contract('saved')['success'], 200);

        $this->assertSame('Saved', $ok['example']['message']);
        $this->assertSame('default_200', $ok['example']['identical_code']);
    }

    public function test_validation_errors_use_the_form_request_override(): void
    {
        $contract = $this->contract('store', 'POST');
        $invalid = self::byStatus($contract['errors'], 422);

        $this->assertSame(['identical_code', 'message', 'content', 'errors'], array_keys($invalid['example']));
        $this->assertSame('unprocessable_entity_422', $invalid['example']['identical_code']);
        $this->assertNull($invalid['example']['content']);
        $this->assertSame(['code', 'message'], array_keys($invalid['example']['errors'][0]));
        $this->assertSame(['the input fails validation'], $invalid['triggers']);

        $created = self::byStatus($contract['success'], 201);
        $this->assertSame('default_store_201', $created['example']['identical_code']);

        // Payload and curl from the rules
        $this->assertSame('json', $contract['payload']['location']);
        $this->assertSame(['name' => 'Example name', 'price' => 1, 'kind' => 'food'], $contract['payload']['example']);
        $this->assertStringContainsString("curl -X POST '{{base_url}}/api/products/store'", $contract['curl']);
        $this->assertStringContainsString('-d \'{"name":"Example name","price":1,"kind":"food"}\'', $contract['curl']);
    }

    public function test_exceptions_go_through_the_apps_renderer(): void
    {
        $errors = $this->contract('missing')['errors'];

        $this->assertSame(['identical_code' => 'default_404', 'message' => 'Resource not found', 'content' => null, 'errors' => [['code' => 'not-found', 'message' => 'Not found']]],
            self::byStatus($errors, 404)['example']);
        // production: APP_DEBUG off, so the generic message, never the exception's
        $this->assertSame(['identical_code' => 'default_500', 'message' => 'Internal server error', 'content' => null, 'errors' => [['code' => 'server-error', 'message' => 'Something went wrong']]],
            self::byStatus($errors, 500)['example']);
        $this->assertSame([405], array_column(array_filter($errors, fn ($r) => $r['status'] === 405), 'status'));
    }

    /** The fixture is trusted test code: run it for real and require the same JSON. */
    public function test_parity_with_laravel_running_the_same_code(): void
    {
        foreach (File::allFiles(base_path('tests/Fixtures/contract/app')) as $file) {
            require_once $file->getPathname();
        }
        config(['response' => require base_path('tests/Fixtures/contract/config/response.php')]);
        $controller = new ProductController;
        $middleware = new EnforceEnvelope;
        $request = Request::create('/api/products/show');

        foreach (['show', 'ping', 'saved'] as $action) {
            $real = $middleware->handle($request, fn () => $controller->{$action}())->getData(true);
            $static = self::byStatus($this->contract($action)['success'], 200)['example'];

            $this->assertSame($real, $static, "static contract of {$action}() must equal what Laravel sends");
        }
    }
}
