<?php

namespace Tests\Feature\Discovery;

use App\Domain\Workspaces\WorkspaceRole;
use App\Models\ApiEndpoint;
use App\Models\Project;
use App\Models\RouteScan;
use App\Models\SourceSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsSnapshots;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

class ApiExplorerTest extends TestCase
{
    use BuildsSnapshots, RefreshDatabase, WorksWithWorkspaces;

    private Project $project;

    private User $developer;

    private SourceSnapshot $snapshot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        config(['features.project_wizard' => true, 'features.source_snapshots' => true, 'features.framework_detection' => true, 'features.route_discovery' => true, 'app.debug' => false]);
        $this->withoutVite();
        Storage::fake('quarantine', ['throw' => true]);
        Storage::fake('snapshots', ['throw' => true]);

        $this->project = Project::factory()->create();
        $this->developer = $this->memberOf(WorkspaceRole::Developer, $this->project->workspace);

        $files = $this->laravel11();
        $lines = ['<?php', 'use App\\Http\\Controllers\\OrderController;', 'use Illuminate\\Support\\Facades\\Route;'];
        for ($i = 0; $i < 60; $i++) {
            $lines[] = "Route::get('/items_{$i}', [OrderController::class, 'show']);";
        }
        $lines[] = "Route::middleware('auth:sanctum')->post('/orders', [OrderController::class, 'store'])->name('orders.store');";
        $lines[] = "Route::get('/odd%path', fn () => 1);";
        $files['routes/api.php'] = implode("\n", $lines)."\n";
        $this->snapshot = $this->snapshotFrom($files, $this->project, $this->developer);
    }

    private function explorer(string $query = '')
    {
        return $this->get("/projects/{$this->project->id}/endpoints{$query}");
    }

    public function test_the_explorer_lists_api_routes_with_filters_and_pages()
    {
        $this->actingAs($this->developer);

        $this->explorer()->assertOk()->assertInertia(fn ($page) => $page
            ->component('projects/endpoints')
            ->where('scan.status', 'completed')
            ->where('scan.endpointCount', 63)
            ->where('endpoints.total', 62, false) // api group by default: 62, web "/" hidden
            ->has('endpoints.data', 50)
            ->where('endpoints.last_page', 2));

        $this->explorer('?group=all')->assertInertia(fn ($page) => $page->where('endpoints.total', 63));
        $this->explorer('?method=POST')->assertInertia(fn ($page) => $page->has('endpoints.data', 1)->where('endpoints.data.0.auth', 'required'));
        $this->explorer('?auth=required')->assertInertia(fn ($page) => $page->has('endpoints.data', 1));
        $this->explorer('?resolution=unresolved')->assertInertia(fn ($page) => $page->has('endpoints.data', 1)->where('endpoints.data.0.handlerType', 'closure'));
        $this->explorer('?q=orders.store')->assertInertia(fn ($page) => $page->has('endpoints.data', 1));
        // LIKE wildcards in the search are taken literally.
        $this->explorer('?q='.urlencode('items_1'))->assertInertia(fn ($page) => $page->has('endpoints.data', 11));
        $this->explorer('?q='.urlencode('%'))->assertInertia(fn ($page) => $page->has('endpoints.data', 1)->where('endpoints.data.0.uri', '/api/odd%path'));
    }

    public function test_source_locations_are_only_for_roles_with_source_access()
    {
        $endpoint = ApiEndpoint::query()->where('uri', '/api/orders')->firstOrFail();

        $this->actingAs($this->developer)->get("/projects/{$this->project->id}/endpoints/{$endpoint->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('endpoint.source.path', 'routes/api.php')->where('isCurrent', true)
                ->where('endpoint.source.expression', "Route::middleware('auth:sanctum')->post('/orders', [OrderController::class, 'store'])->name('orders.store')"));

        $qa = $this->memberOf(WorkspaceRole::QA, $this->project->workspace);
        $this->actingAs($qa)->get("/projects/{$this->project->id}/endpoints/{$endpoint->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('endpoint.source', null)->where('endpoint.controller', 'App\Http\Controllers\OrderController'));
        $this->explorer()->assertInertia(fn ($page) => $page->where('endpoints.data.0.source', null));
        $this->postJson("/projects/{$this->project->id}/snapshots/{$this->snapshot->id}/scans")->assertForbidden();
    }

    public function test_other_tenants_and_other_projects_get_a_plain_404()
    {
        $endpoint = ApiEndpoint::query()->firstOrFail();
        $scan = RouteScan::query()->firstOrFail();
        $missing = (string) Str::uuid();

        $this->actingAs($this->memberOf(WorkspaceRole::Owner));
        $this->assertSame(
            $this->getJson("/projects/{$this->project->id}/endpoints/{$missing}")->assertNotFound()->json(),
            $this->getJson("/projects/{$this->project->id}/endpoints/{$endpoint->id}")->assertNotFound()->json(),
        );
        $this->getJson("/projects/{$this->project->id}/scans/{$scan->id}")->assertNotFound();
        $this->getJson("/projects/{$this->project->id}/endpoints")->assertNotFound();

        // Same workspace, different project: the endpoint isn't reachable through it.
        $other = Project::factory()->for($this->project->workspace)->create();
        $this->actingAs($this->developer)->getJson("/projects/{$other->id}/endpoints/{$endpoint->id}")->assertNotFound();
    }

    public function test_the_flag_hides_the_explorer()
    {
        config(['features.route_discovery' => false]);

        $this->actingAs($this->developer)->explorer()->assertNotFound();
        $this->get("/projects/{$this->project->id}")->assertInertia(fn ($page) => $page->where('endpoints', null));
    }

    public function test_the_project_page_summarizes_the_current_scan()
    {
        $this->actingAs($this->developer)->get("/projects/{$this->project->id}")->assertInertia(fn ($page) => $page
            ->where('endpoints.scan.endpointCount', 63)
            ->where('endpoints.scan.unresolvedCount', 2)); // /api/odd%path and the web / route are closures
    }
}
