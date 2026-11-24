<?php

namespace Tests\Feature\GitHub;

use App\Domain\Analysis\EndpointAnalyses;
use App\Domain\Docs\StaticDocumentation;
use App\Domain\Sources\LatestSnapshot;
use App\Domain\Workspaces\WorkspaceRole;
use App\Models\ApiEndpoint;
use App\Models\FrameworkDetection;
use App\Models\Project;
use App\Models\RouteScan;
use App\Models\SourceFile;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Concerns\BuildsSnapshots;
use Tests\Concerns\FakesGitHub;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

/**
 * P09 checkpoint M3: the same app imported by ZIP upload and from GitHub yields the same
 * snapshot contract and the same downstream results (QUEUE_CONNECTION=sync runs it all).
 */
class ZipGitParityTest extends TestCase
{
    use BuildsSnapshots, FakesGitHub, RefreshDatabase, WorksWithWorkspaces;

    private User $developer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        config([
            'features.project_wizard' => true, 'features.source_snapshots' => true, 'features.zip_import' => true,
            'features.framework_detection' => true, 'features.route_discovery' => true, 'features.endpoint_analysis' => true,
            'features.static_docs' => true, 'features.git_import' => true,
        ]);
        $this->withoutVite();
        Storage::fake('quarantine', ['throw' => true]);
        Storage::fake('snapshots', ['throw' => true]);
        Sleep::fake();
        $this->configureGitHubApp();
    }

    /** @return array<string, string> */
    private function app(): array
    {
        $files = $this->laravel11("api: __DIR__.'/../routes/api.php', web: __DIR__.'/../routes/web.php'");
        $files['routes/api.php'] = "<?php\n\nuse App\\Http\\Controllers\\OrderController;\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::middleware('auth:sanctum')->group(function () {\n    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');\n    Route::post('/orders', [OrderController::class, 'store']);\n});\nRoute::get('/health', fn () => 'ok');\n";
        $files['app/Http/Controllers/OrderController.php'] = "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse Illuminate\\Http\\Request;\n\nclass OrderController\n{\n    public function index() { return response()->json(['data' => []]); }\n\n    public function store(Request \$request)\n    {\n        \$request->validate(['sku' => 'required|string|max:20', 'qty' => 'required|integer|min:1']);\n\n        return response()->json(['created' => true], 201);\n    }\n}\n";
        $files['.env'] = "APP_KEY=PARITY-SECRET\n";
        $files['vendor/laravel/framework/x.php'] = "<?php\n";

        return $files;
    }

    private function viaZipUpload(Project $project): SourceSnapshot
    {
        $bytes = $this->zipBytes($this->app()); // the repository's files at the archive root, as a user zips them
        $this->actingAs($this->developer);
        $id = $this->postJson("/projects/{$project->id}/uploads", ['total_bytes' => strlen($bytes), 'file_name' => 'app.zip'])->assertCreated()->json('id');
        $this->call('PUT', "/projects/{$project->id}/uploads/{$id}/chunk?offset=0", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $bytes)->assertOk();
        $this->postJson("/projects/{$project->id}/uploads/{$id}/complete")->assertOk();

        return LatestSnapshot::of($project) ?? $this->fail('ZIP import did not finish');
    }

    private function viaGitHub(Project $project): SourceSnapshot
    {
        $this->linkedRepository($project);
        $this->fakeGitHub($this->app()); // served inside GitHub's "acme-api-<sha7>/" wrapper folder
        $this->actingAs($this->developer)->post("/projects/{$project->id}/github/imports", ['ref_type' => 'branch', 'ref' => 'main'])->assertRedirect();

        return LatestSnapshot::of($project) ?? $this->fail('GitHub import did not finish');
    }

    /** Everything downstream, minus the IDs that differ by construction. */
    private function results(SourceSnapshot $snapshot): array
    {
        $scan = RouteScan::query()->where('source_snapshot_id', $snapshot->id)->sole();
        $endpoints = ApiEndpoint::query()->where('route_scan_id', $scan->id)->orderBy('route_identity')->get();
        $analyses = app(EndpointAnalyses::class);
        $docs = app(StaticDocumentation::class);
        $strip = function (mixed $value) use (&$strip): mixed {
            return is_array($value) ? array_map($strip, array_diff_key($value, array_flip(['snapshot_id', 'endpoint_id', 'analysis_id', 'id']))) : $value;
        };

        return [
            'sha256' => $snapshot->sha256,
            'files' => SourceFile::query()->where('source_snapshot_id', $snapshot->id)->orderBy('path')->get(['path', 'sha256', 'bytes', 'excluded_reason'])->toArray(),
            'root' => FrameworkDetection::query()->where('source_snapshot_id', $snapshot->id)->sole()->selected_root,
            'scan' => $scan->status->value,
            'endpoints' => $endpoints->map(fn (ApiEndpoint $e) => [$e->route_identity, $e->methods, $e->uri, $e->domain, $e->name, $e->handler_type, $e->controller, $e->action, $e->middleware, $e->auth, $e->resolution, $e->source_path, $e->source_line])->all(),
            'contracts' => $endpoints->map(function (ApiEndpoint $e) use ($analyses, $docs, $strip) {
                $analysis = $analyses->start($this->developer, $e)->refresh();
                $doc = $docs->generate($this->developer, $analysis);

                return [$analysis->status->value, $strip($analysis->result), $strip($doc->document)];
            })->all(),
        ];
    }

    public function test_zip_upload_and_github_import_yield_the_same_snapshot_and_downstream_results()
    {
        $workspace = Workspace::factory()->create();
        $this->developer = $this->memberOf(WorkspaceRole::Developer, $workspace);
        // Two projects: within one project the second import would simply dedupe to the first snapshot.
        $zipProject = Project::factory()->for($workspace)->create();
        $gitProject = Project::factory()->for($workspace)->create();

        $zip = $this->results($this->viaZipUpload($zipProject));
        $git = $this->results($this->viaGitHub($gitProject));

        $this->assertCount(4, $zip['endpoints']); // 3 API routes and the web '/'
        $this->assertContains('routes/api.php', array_column($zip['endpoints'], 11));
        $this->assertSame('.', $zip['root']);
        $this->assertContains('completed', array_column($zip['contracts'], 0));
        $this->assertSame($zip, $git);
    }
}
