<?php

namespace Tests\Feature\Analysis;

use App\Domain\Analysis\AnalysisResultV1;
use App\Domain\Analysis\EndpointAnalyses;
use App\Domain\Analysis\EndpointAnalyzer;
use App\Domain\Docs\StaticDocumentationBuilder;
use App\Domain\Projects\ProjectDeleter;
use App\Domain\Sources\RunStatus;
use App\Domain\Workspaces\WorkspaceRole;
use App\Models\AiProvider;
use App\Models\ApiDependency;
use App\Models\ApiEndpoint;
use App\Models\DocumentationVersion;
use App\Models\EndpointAnalysis;
use App\Models\Project;
use App\Models\RouteScan;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Models\WorkflowExplanation;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Concerns\AnalyzesFixtures;
use Tests\Concerns\BuildsSnapshots;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

/**
 * P10: the advanced profile's workflow graph, read statically from tests/Fixtures/workflow
 * (never loaded or run).
 */
class WorkflowGraphTest extends TestCase
{
    use AnalyzesFixtures, BuildsSnapshots, RefreshDatabase, WorksWithWorkspaces;

    private const CONTROLLER = 'WorkflowFixture\App\Http\Controllers\OrderController';

    private Project $project;

    private User $developer;

    private SourceSnapshot $snapshot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        config(['features.project_wizard' => true, 'features.source_snapshots' => true, 'features.framework_detection' => true, 'features.route_discovery' => true, 'features.endpoint_analysis' => true, 'features.advanced_analysis' => true, 'features.ai_settings' => true, 'app.debug' => false]);
        $this->withoutVite();
        Storage::fake('quarantine', ['throw' => true]);
        Storage::fake('snapshots', ['throw' => true]);

        $routes = "<?php\nuse ".self::CONTROLLER.";\nuse Illuminate\\Support\\Facades\\Route;\n\n"
            ."Route::post('orders', [OrderController::class, 'store']);\n"
            ."Route::get('orders/{id}', [OrderController::class, 'show']);\n";
        foreach (['charge', 'conditionalFirst', 'unconditionalFirst', 'onlyConditional', 'recurse', 'deep'] as $action) {
            $routes .= "Route::get('workflow/{$action}', [OrderController::class, '{$action}']);\n";
        }
        $files = [
            'composer.json' => json_encode(['require' => ['laravel/framework' => '^11.0'], 'autoload' => ['psr-4' => ['WorkflowFixture\\App\\' => 'app/']]]),
            'composer.lock' => json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v11.30.0']]]),
            'artisan' => "<?php\n",
            'bootstrap/app.php' => "<?php\nreturn Application::configure(basePath: dirname(__DIR__))->withRouting(api: __DIR__.'/../routes/api.php')->create();\n",
            'routes/api.php' => $routes,
        ];
        foreach (File::allFiles(base_path('tests/Fixtures/workflow/app')) as $file) {
            $files['app/'.$file->getRelativePathname()] = $file->getContents();
        }
        $this->project = Project::factory()->create();
        $this->developer = $this->memberOf(WorkspaceRole::Developer, $this->project->workspace);
        $this->snapshot = $this->snapshotFrom($files, $this->project, $this->developer);

        // Tracing runs only with AI (spec § 6.5a): a ready default provider, answered by a fake.
        AiProvider::factory()->create(['provider' => 'anthropic', 'is_default' => true, 'enabled' => true, 'model' => 'claude-test', 'available_models' => [['id' => 'claude-test', 'effort' => true]]]);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => "## Overview\nPlaces an order."]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]])]);
    }

    private function endpoint(string $action): ApiEndpoint
    {
        return ApiEndpoint::query()->where('project_id', $this->project->id)->where('action', $action)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function analyze(string $action, string $profile = EndpointAnalyses::ADVANCED, array $limits = []): array
    {
        $analyzer = app(EndpointAnalyzer::class);
        $analyzer->graphLimits = $limits;
        $result = $analyzer->analyze($this->snapshot, $this->endpoint($action), '.', $profile);
        $this->assertSame([], AnalysisResultV1::problems($result));

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private static function edges(array $graph, ?string $kind = null): array
    {
        return array_values(array_filter($graph['edges'], fn ($e) => $kind === null || $e['kind'] === $kind));
    }

    private static function edge(array $graph, string $from, string $to, string $kind): array
    {
        foreach ($graph['edges'] as $e) {
            if (str_ends_with($e['from'], $from) && str_ends_with($e['to'], $to) && $e['kind'] === $kind) {
                return $e;
            }
        }
        self::fail("no {$kind} edge {$from} → {$to} in ".json_encode(array_map(fn ($e) => "{$e['from']} -{$e['kind']}-> {$e['to']}", $graph['edges'])));
    }

    public function test_service_chain_maps_controller_service_model_and_queued_job_without_duplicate_edges(): void
    {
        $graph = $this->analyze('store')['graph'];

        self::edge($graph, 'route:'.$this->endpoint('store')->route_identity, 'OrderController::store', 'handled_by');
        self::edge($graph, 'OrderController::store', 'StoreOrderRequest', 'validates_with');
        $call = self::edge($graph, 'OrderController::store', 'OrderService::place', 'calls');
        $this->assertFalse($call['conditional']);
        $this->assertSame('resolved', $call['resolution']);
        self::edge($graph, 'OrderService::place', 'Models\Order', 'writes_to');
        self::edge($graph, 'OrderService::audit', 'Models\OrderItem', 'writes_to'); // a write through a relation

        $job = self::edge($graph, 'OrderService::place', 'Jobs\ProcessOrder', 'dispatches');
        $this->assertTrue($job['async'], 'ShouldQueue: runs later on a worker');
        $this->assertFalse($job['conditional']);
        $invoice = self::edge($graph, 'OrderService::place', 'Jobs\SendInvoice', 'dispatches');
        $this->assertTrue($invoice['conditional'], 'dispatched inside if (request(...))');
        $event = self::edge($graph, 'OrderService::place', 'Events\OrderPlaced', 'dispatches');
        $this->assertFalse($event['async']);
        self::edge($graph, 'OrderController::store', 'OrderResource::toArray', 'responds_with');

        // place() is called twice and memoized: one edge per (from, to, kind), each citing evidence.
        $keys = array_map(fn ($e) => $e['from'].'|'.$e['to'].'|'.$e['kind'], $graph['edges']);
        $this->assertSame(array_unique($keys), $keys);
        $types = array_column($graph['nodes'], 'type', 'id');
        foreach ($graph['edges'] as $e) {
            $this->assertNotSame([], $e['evidence'], "{$e['from']} → {$e['to']} cites no source");
            $this->assertArrayHasKey($e['from'], $types);
            $this->assertArrayHasKey($e['to'], $types);
        }
        $this->assertSame('job', $types['WorkflowFixture\App\Jobs\ProcessOrder']);
        $this->assertSame('event', $types['WorkflowFixture\App\Events\OrderPlaced']);
        $this->assertSame('model', $types['WorkflowFixture\App\Models\Order']);
        $this->assertTrue($graph['coverage']['complete']);
        $this->assertSame([], $graph['coverage']['bounds']);
    }

    public function test_resource_conditional_fields_are_not_declared_always_present(): void
    {
        $result = $this->analyze('show');
        $resource = collect($result['graph']['nodes'])->firstWhere('type', 'resource');
        $fields = array_column($resource['fields'], null, 'name');

        $this->assertSame('always', $fields['id']['presence']);
        $this->assertSame('always', $fields['total']['presence']);
        foreach (['items', 'secret', 'notes'] as $name) {
            $this->assertSame('conditional', $fields[$name]['presence'], $name);
        }
        $this->assertSame('only when the items relation is loaded', $fields['items']['condition']);
        $this->assertStringContainsString('merged in only when', $fields['notes']['condition']);

        // The contract agrees: those keys are optional in the 200 body, the others are not.
        $ok = collect($result['contract']['success'])->firstWhere('status', 200);
        $data = $ok['body']['props']['data'];
        $this->assertEqualsCanonicalizing(['items', 'secret', 'notes'], $data['optional']);
        $this->assertArrayHasKey('id', $data['props']);
    }

    public function test_interface_bindings_and_dynamic_calls_stay_unresolved_with_their_reason(): void
    {
        $graph = $this->analyze('charge')['graph'];
        $labels = array_column($graph['nodes'], 'label', 'id');
        $unresolved = self::edges($graph, 'calls');
        $byReason = collect($unresolved)->where('resolution', 'unresolved')->groupBy('reason');

        $this->assertCount(2, $byReason['container_binding']);
        $this->assertContains('Container binding resolved at runtime: WorkflowFixture\App\Contracts\PaymentGateway::charge() (no static binding found)', $labels);
        $this->assertContains('Container binding resolved at runtime: WorkflowFixture\App\Contracts\ShippingQuotes::quote() (AppServiceProvider binds it to WorkflowFixture\App\Services\FlatRateShipping; not followed, since the binding can change at runtime)', $labels);
        $dynamic = $byReason['dynamic_method']->sole();
        $this->assertSame('$this->{$action}()', $dynamic['expression']);
        $this->assertSame('Dynamic method name: the method is chosen at runtime', $labels[$dynamic['to']]);
        // An app-namespace class that isn't in the snapshot is not passed off as a library.
        $missing = $byReason['missing_class']->sole();
        $this->assertSame('Not in the uploaded source: WorkflowFixture\App\Services\MissingService::run() (its namespace belongs to the app, but no file declares it)', $labels[$missing['to']]);
        $this->assertNotContains('external', array_column($graph['nodes'], 'type'));

        // Never guessed: the implementations in the snapshot are not in the graph.
        $raw = json_encode($graph);
        $this->assertStringNotContainsString('StripeGateway', $raw);
        $this->assertStringNotContainsString('FlatRateShipping::quote', $raw);
        $this->assertFalse($graph['coverage']['complete']);
        $this->assertSame(4, $graph['coverage']['unresolved']);
        $this->assertSame(['unresolved'], array_values(array_unique(array_map(fn ($id) => collect($graph['nodes'])->firstWhere('id', $id)['type'], $byReason->flatten(1)->pluck('to')->all()))));
    }

    public function test_an_edge_is_unconditional_when_any_path_reaches_it_unconditionally_in_either_memo_order(): void
    {
        foreach (['conditionalFirst', 'unconditionalFirst'] as $action) {
            $graph = $this->analyze($action)['graph'];
            $this->assertFalse(self::edge($graph, "OrderController::{$action}", 'OrderService::ping', 'calls')['conditional'], $action);
            $this->assertFalse(self::edge($graph, 'OrderService::ping', 'Models\Order', 'reads_from')['conditional'], $action);
        }

        $graph = $this->analyze('onlyConditional')['graph'];
        $this->assertTrue(self::edge($graph, 'OrderController::onlyConditional', 'OrderService::ping', 'calls')['conditional']);
        // Unconditional inside ping(), but ping() itself only runs on that branch.
        $this->assertTrue(self::edge($graph, 'OrderService::ping', 'Models\Order', 'reads_from')['conditional']);
    }

    public function test_cycles_are_recorded_once_and_bounds_emit_partial_coverage(): void
    {
        $graph = $this->analyze('recurse')['graph'];
        self::edge($graph, 'OrderController::recurse', 'OrderService::walk', 'calls');
        self::edge($graph, 'OrderService::walk', 'OrderService::back', 'calls');
        $this->assertSame('cycle', self::edge($graph, 'OrderService::back', 'OrderService::walk', 'calls')['reason']);
        $this->assertCount(3, self::edges($graph, 'calls'));

        $deep = $this->analyze('deep')['graph'];
        $bounded = collect($deep['edges'])->firstWhere('reason', 'bound_depth');
        $this->assertNotNull($bounded, 'the chain is deeper than the depth bound');
        $this->assertSame('resolved', $bounded['resolution'], 'the target is known, just not followed further');
        $this->assertSame(['depth'], array_column($deep['coverage']['bounds'], 'code'));
        $this->assertFalse($deep['coverage']['complete']);
        $this->assertStringContainsString('Bound reached: call depth 13', $deep['coverage']['bounds'][0]['detail']);

        $small = $this->analyze('store', limits: ['maxNodes' => 4, 'maxFanout' => 2])['graph'];
        $this->assertLessThanOrEqual(4 + 2, $small['coverage']['nodes'], 'the route and FormRequest are reserved on top of the bound');
        $this->assertContains('nodes', array_column($small['coverage']['bounds'], 'code'));
        $this->assertFalse($small['coverage']['complete']);
        foreach (array_count_values(array_column(array_filter($small['edges'], fn ($e) => $e['kind'] !== 'validates_with'), 'from')) as $from => $count) {
            $this->assertLessThanOrEqual(2, $count, $from);
        }

        // Out of time before anything was recorded: still a rooted graph, honestly partial.
        $late = $this->analyze('store', limits: ['maxSeconds' => 0])['graph'];
        $this->assertEqualsCanonicalizing(['handled_by', 'validates_with'], array_column($late['edges'], 'kind'));
        $this->assertSame(['time'], array_column($late['coverage']['bounds'], 'code'));
        foreach ([$small, $late] as $bounded) {
            $this->assertContains($bounded['root'], array_column($bounded['nodes'], 'id'));
            self::edge($bounded, $bounded['root'], 'OrderController::store', 'handled_by');
            $this->assertFalse($bounded['coverage']['complete']);
        }
    }

    public function test_the_advanced_result_is_the_basic_result_plus_its_graph(): void
    {
        foreach (['store', 'show', 'charge', 'recurse', 'deep'] as $action) {
            $basic = $this->analyze($action, EndpointAnalyses::BASIC);
            $advanced = $this->analyze($action);

            $this->assertArrayNotHasKey('graph', $basic);
            $this->assertArrayHasKey('graph', $advanced);
            // Graph evidence is appended after every basic entry: the basic ids are unchanged.
            $this->assertSame($basic['evidence'], array_slice($advanced['evidence'], 0, count($basic['evidence'])), $action);
            unset($advanced['graph'], $advanced['evidence'], $basic['evidence']);
            $this->assertSame(json_encode($basic), json_encode($advanced), "{$action}: tracing changed the contract"); // as stored
        }
    }

    public function test_tracing_the_existing_analysis_fixtures_changes_none_of_their_contracts(): void
    {
        $fixtures = [
            [$this->fixtureApp(['routes/api.php' => "<?php\n"]), 'Fixture\App\Http\Controllers\\', ['OrderController' => ['index', 'store', 'show', 'update', 'legacy', 'cancel', 'destroy', 'otp', 'dynamic', 'delegated'], 'AccountController' => ['mine', 'favourite', 'personalised', 'signIn', 'guardLogin']]],
            [['composer.json' => json_encode(['require' => ['laravel/framework' => '^11.0'], 'autoload' => ['psr-4' => ['ContractFixture\\App\\' => 'app/']]]), 'artisan' => "<?php\n", 'routes/api.php' => "<?php\n"]
                + collect(File::allFiles(base_path('tests/Fixtures/contract')))->mapWithKeys(fn ($f) => [$f->getRelativePathname() => $f->getContents()])->all(),
                'ContractFixture\App\Http\Controllers\\', ['ProductController' => ['show', 'ping', 'saved', 'store', 'missing']]],
        ];
        foreach ($fixtures as [$files, $namespace, $controllers]) {
            $project = Project::factory()->create();
            $snapshot = $this->snapshotFrom($files, $project, $this->memberOf(WorkspaceRole::Developer, $project->workspace));
            $scan = RouteScan::factory()->create(['source_snapshot_id' => $snapshot->id]);
            foreach ($controllers as $controller => $actions) {
                foreach ($actions as $action) {
                    $endpoint = ApiEndpoint::factory()->create(['route_scan_id' => $scan->id, 'controller' => $namespace.$controller, 'action' => $action,
                        'uri' => "/api/{$action}/{id}", 'methods' => ['POST'], 'middleware' => ['api'], 'without_middleware' => [], 'route_identity' => hash('sha256', $controller.$action)]);
                    $basic = app(EndpointAnalyzer::class)->analyze($snapshot, $endpoint, '.');
                    $advanced = app(EndpointAnalyzer::class)->analyze($snapshot, $endpoint, '.', EndpointAnalyses::ADVANCED);
                    $this->assertSame([], AnalysisResultV1::problems($advanced), "{$controller}::{$action}");
                    $this->assertSame($basic['evidence'], array_slice($advanced['evidence'], 0, count($basic['evidence'])));
                    unset($advanced['graph'], $advanced['evidence'], $basic['evidence']);
                    $this->assertSame(json_encode($basic), json_encode($advanced), "{$controller}::{$action}");
                }
            }
        }
    }

    public function test_documentation_renders_the_same_from_basic_and_advanced_results_with_the_flag_on_or_off(): void
    {
        $builder = app(StaticDocumentationBuilder::class);
        $endpoint = $this->endpoint('store');
        $meta = ['methods' => $endpoint->methods, 'uri' => $endpoint->uri, 'domain' => $endpoint->domain, 'name' => $endpoint->name,
            'handler' => $endpoint->controller.'@'.$endpoint->action, 'group' => $endpoint->route_group, 'middleware' => $endpoint->middleware];
        $basic = $this->analyze('store', EndpointAnalyses::BASIC);
        $advanced = $this->analyze('store');
        $doc = json_encode($builder->build($basic, 'completed', $meta));
        $this->assertSame($doc, json_encode($builder->build($advanced, 'completed', $meta)));

        // A stored draft (built with the flag off) is served byte-identical once the flag is on.
        config(['features.static_docs' => true, 'features.advanced_analysis' => false]);
        $this->actingAs($this->developer)->post("/projects/{$this->project->id}/endpoints/{$endpoint->id}/analyses");
        $analysis = EndpointAnalysis::query()->sole();
        $this->post("/projects/{$this->project->id}/analyses/{$analysis->id}/documentation");
        $draft = DocumentationVersion::query()->sole();
        $page = fn () => json_encode($this->get("/projects/{$this->project->id}/documentation/{$draft->id}")->assertOk()->inertiaProps('documentation'));
        $off = $page();
        config(['features.advanced_analysis' => true]);
        $this->post("/projects/{$this->project->id}/analyses/{$analysis->id}/dependencies")->assertRedirect();
        $this->assertSame($off, $page());
        $this->assertSame($analysis->result, $analysis->fresh()->result, 'tracing never rewrites the basic analysis');
    }

    private function traceUrl(EndpointAnalysis $analysis): string
    {
        return "/projects/{$this->project->id}/analyses/{$analysis->id}/dependencies";
    }

    private function basicAnalysis(string $action = 'store'): EndpointAnalysis
    {
        $this->actingAs($this->developer)->post("/projects/{$this->project->id}/endpoints/{$this->endpoint($action)->id}/analyses")->assertRedirect();

        return EndpointAnalysis::query()->where('profile', EndpointAnalyses::BASIC)->sole();
    }

    public function test_tracing_creates_a_separate_advanced_analysis_with_normalized_dependencies(): void
    {
        $basic = $this->basicAnalysis();
        $this->getJson($this->traceUrl($basic))->assertOk()->assertJson(['advanced' => null, 'graph' => null, 'canExplain' => true]);

        $this->post($this->traceUrl($basic))->assertRedirect()->assertSessionHas('inertia.flash_data.toast', ['type' => 'info', 'message' => 'Explaining the workflow…']);
        $this->post($this->traceUrl($basic))->assertRedirect(); // a repeated click reuses the trace

        $advanced = EndpointAnalysis::query()->where('profile', EndpointAnalyses::ADVANCED)->sole();
        $this->assertNotSame($basic->analysis_key, $advanced->analysis_key);
        $this->assertSame(EndpointAnalyses::key($this->endpoint('store'), EndpointAnalyses::ADVANCED), $advanced->analysis_key);
        $this->assertSame(1, $advanced->attempts);
        $this->assertSame('completed', WorkflowExplanation::query()->where('endpoint_analysis_id', $advanced->id)->sole()->status, 'trace then AI');
        $this->assertFalse(EndpointAnalyses::isStale($advanced));
        $this->assertFalse(EndpointAnalyses::isStale($basic->fresh()));
        $this->assertSame(count($advanced->result['graph']['edges']), ApiDependency::query()->where('endpoint_analysis_id', $advanced->id)->count());
        $this->assertSame(0, ApiDependency::query()->where('endpoint_analysis_id', $basic->id)->count());
        $job = ApiDependency::query()->where('endpoint_analysis_id', $advanced->id)->where('to_type', 'job')->where('to_node', 'like', '%ProcessOrder')->sole();
        $this->assertTrue($job->async);
        $this->assertSame('dispatches', $job->kind);

        // The endpoint page keeps showing the basic analysis.
        $this->get("/projects/{$this->project->id}/endpoints/{$this->endpoint('store')->id}")
            ->assertInertia(fn ($page) => $page->where('analysis.id', $basic->id)->missing('analysis.result.graph'));

        $json = $this->getJson($this->traceUrl($basic))->assertOk()->json();
        $this->assertSame($advanced->id, $json['advanced']['id']);
        $this->assertSame('completed', $json['advanced']['status']);
        $call = collect($json['graph']['edges'])->first(fn ($e) => str_ends_with($e['to'], 'OrderService::place'));
        $this->assertSame('app/Http/Controllers/OrderController.php', $call['refs'][0]['path']);
        $this->assertSame($json, $this->getJson($this->traceUrl($advanced))->json(), 'the advanced id answers the same');

        $this->post($this->traceUrl($basic), ['again' => true])->assertRedirect();
        $this->assertSame(2, $advanced->fresh()->attempts);
        $this->assertSame(count($advanced->result['graph']['edges']), ApiDependency::query()->where('endpoint_analysis_id', $advanced->id)->count(), 'rewritten, not duplicated');
    }

    public function test_qa_and_viewers_see_the_graph_without_source_references(): void
    {
        $basic = $this->basicAnalysis('charge');
        $this->post($this->traceUrl($basic))->assertRedirect();
        $advanced = EndpointAnalysis::query()->where('profile', EndpointAnalyses::ADVANCED)->sole();

        foreach ([WorkspaceRole::QA, WorkspaceRole::Viewer] as $role) {
            $member = $this->memberOf($role, $this->project->workspace);
            foreach ([$this->traceUrl($basic), "/projects/{$this->project->id}/analyses/{$advanced->id}"] as $url) {
                $json = $this->actingAs($member)->getJson($url)->assertOk()->json();
                $raw = json_encode($json, JSON_UNESCAPED_SLASHES);
                $this->assertStringContainsString('Container binding resolved at runtime', $raw);
                foreach (['"evidence"', '"expression"', '"refs"', '.php', '$this->', 'sha256'] as $needle) {
                    $this->assertStringNotContainsString($needle, $raw, "{$role->value}: {$needle} in {$url}");
                }
                $this->assertDoesNotMatchRegularExpression('/line \d/', $raw);
            }
            $this->assertFalse($this->actingAs($member)->getJson($this->traceUrl($basic))->json('canExplain'));
            $this->actingAs($member)->post($this->traceUrl($basic))->assertForbidden();
        }
    }

    public function test_other_workspaces_get_a_uniform_404_and_the_flag_off_hides_the_routes(): void
    {
        $basic = $this->basicAnalysis();
        $elsewhere = Workspace::factory()->create();
        $stranger = $this->memberOf(WorkspaceRole::Owner, $elsewhere);
        $own = Project::factory()->for($elsewhere)->create();

        $this->actingAs($stranger)->getJson($this->traceUrl($basic))->assertNotFound();
        $this->actingAs($stranger)->post($this->traceUrl($basic))->assertNotFound();
        $this->actingAs($stranger)->getJson("/projects/{$own->id}/analyses/{$basic->id}/dependencies")->assertNotFound();
        $this->actingAs($stranger)->post("/projects/{$own->id}/analyses/{$basic->id}/dependencies")->assertNotFound();

        config(['features.advanced_analysis' => false]);
        $this->actingAs($this->developer)->getJson($this->traceUrl($basic))->assertNotFound();
        $this->actingAs($this->developer)->post($this->traceUrl($basic))->assertNotFound();
        $this->assertSame(0, EndpointAnalysis::query()->where('profile', EndpointAnalyses::ADVANCED)->count());
    }

    public function test_a_worker_without_graph_support_never_stores_an_advanced_result(): void
    {
        Queue::fake();
        $basic = $this->basicAnalysis();
        $this->post($this->traceUrl($basic))->assertRedirect();
        $advanced = EndpointAnalysis::query()->where('profile', EndpointAnalyses::ADVANCED)->sole();

        $old = $this->analyze('store', EndpointAnalyses::BASIC); // what code from before profiles existed returns
        $outdated = Mockery::mock(EndpointAnalyzer::class);
        $outdated->shouldReceive('analyze')->andReturn($old + ['status' => 'completed']);
        (new EndpointAnalyses($outdated))->process($advanced->id);

        $this->assertSame(RunStatus::Failed, $advanced->fresh()->status);
        $this->assertSame('analyzer_mismatch', $advanced->fresh()->failure_code);
        $this->assertSame(0, ApiDependency::query()->count());

        // A final advanced row without this release's graph is offered again, never served as current.
        EndpointAnalysis::query()->whereKey($advanced->id)->update(['status' => RunStatus::Completed->value, 'result' => json_encode($old)]);
        $this->assertTrue(EndpointAnalyses::isStale($advanced->fresh()));
    }

    public function test_deleting_the_project_removes_its_dependencies(): void
    {
        $basic = $this->basicAnalysis();
        $this->post($this->traceUrl($basic))->assertRedirect();
        $this->assertGreaterThan(0, ApiDependency::query()->count());
        ApiDependency::factory()->create(['endpoint_analysis_id' => $basic->id]);

        app(ProjectDeleter::class)->delete($this->memberOf(WorkspaceRole::Owner, $this->project->workspace), $this->project);

        $this->assertSame(0, ApiDependency::query()->count());
        $this->assertSame(0, EndpointAnalysis::query()->count());
    }
}
