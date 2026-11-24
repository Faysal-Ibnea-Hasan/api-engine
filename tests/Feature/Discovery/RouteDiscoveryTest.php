<?php

namespace Tests\Feature\Discovery;

use App\Domain\Discovery\RouteScans;
use App\Domain\Framework\FrameworkDetections;
use App\Domain\Sources\RunStatus;
use App\Domain\Workspaces\WorkspaceRole;
use App\Models\ApiEndpoint;
use App\Models\Project;
use App\Models\RouteScan;
use App\Models\ScanFinding;
use App\Models\SourceSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSnapshots;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

class RouteDiscoveryTest extends TestCase
{
    use BuildsSnapshots, RefreshDatabase, WorksWithWorkspaces;

    private Project $project;

    private User $developer;

    private string $sentinel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        config(['features.project_wizard' => true, 'features.source_snapshots' => true, 'features.framework_detection' => true, 'features.route_discovery' => true]);
        Storage::fake('quarantine', ['throw' => true]);
        Storage::fake('snapshots', ['throw' => true]);
        $this->sentinel = sys_get_temp_dir().'/api-engine-routes-sentinel';
        @unlink($this->sentinel);

        $this->project = Project::factory()->create();
        $this->developer = $this->memberOf(WorkspaceRole::Developer, $this->project->workspace);
    }

    private function scanOf(SourceSnapshot $snapshot): RouteScan
    {
        return RouteScan::query()->where('source_snapshot_id', $snapshot->id)->latest('created_at')->firstOrFail();
    }

    /** @return array<string, ApiEndpoint> keyed by "METHODS URI" */
    private function endpoints(RouteScan $scan): array
    {
        return $scan->endpoints()->get()->keyBy(fn (ApiEndpoint $e) => implode('|', $e->methods).' '.$e->uri)->all();
    }

    /** @return list<string> */
    private function findingCodes(RouteScan $scan): array
    {
        return ScanFinding::query()->where('route_scan_id', $scan->id)->pluck('code')->all();
    }

    public function test_a_laravel_11_app_is_scanned_automatically_after_import_without_running_anything()
    {
        $files = $this->laravel11("api: __DIR__.'/../routes/api.php', web: __DIR__.'/../routes/web.php', apiPrefix: 'v2', then: function () {}");
        $files['routes/api.php'] = <<<PHP
        <?php
        file_put_contents('{$this->sentinel}', 'ran');
        use App\\Http\\Controllers\\OrderController;
        use Illuminate\\Support\\Facades\\Route;

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
            Route::post('/orders', OrderController::class);
        });
        Route::get('/health', fn () => 'ok');
        Route::get(\$dynamicUri, [OrderController::class, 'show']);
        if (config('app.beta')) {
            Route::get('/beta', [OrderController::class, 'beta']);
        }
        require __DIR__.'/admin.php';
        require __DIR__.'/../../outside.php';
        PHP;
        $files['routes/admin.php'] = "<?php\nuse Illuminate\\Support\\Facades\\Route;\nRoute::prefix('admin')->group(fn () => Route::get('stats', [\\App\\Http\\Controllers\\StatsController::class, 'index']));\n";
        $files['routes/broken.php'] = '<?php Route::get(';
        $files['routes/legacy.php'] = "<?php\n";

        $scan = $this->scanOf($this->snapshotFrom($files, $this->project, $this->developer));
        $endpoints = $this->endpoints($scan);

        $this->assertSame(RunStatus::Completed, $scan->status);
        $this->assertFileDoesNotExist($this->sentinel, 'a route file was executed');

        $orders = $endpoints['GET|HEAD /v2/orders'];
        $this->assertSame('App\Http\Controllers\OrderController', $orders->controller);
        $this->assertSame('index', $orders->action);
        $this->assertSame('orders.index', $orders->name);
        $this->assertSame(['api', 'auth:sanctum'], $orders->middleware);
        $this->assertSame('required', $orders->auth);
        $this->assertSame('api', $orders->route_group);
        $this->assertSame('resolved', $orders->resolution);
        $this->assertSame('routes/api.php', $orders->source_path);

        $this->assertSame('invokable', $endpoints['POST /v2/orders']->handler_type);
        $this->assertSame('__invoke', $endpoints['POST /v2/orders']->action);
        $this->assertSame('unresolved', $endpoints['GET|HEAD /v2/health']->resolution);
        $this->assertSame('unknown', $endpoints['GET|HEAD /v2/health']->auth, 'never "no auth"');
        $this->assertSame('partial', $endpoints['GET|HEAD /v2/beta']->resolution);
        $this->assertArrayHasKey('GET|HEAD /v2/admin/stats', $endpoints, 'require of a sibling route file is followed');
        $this->assertSame('web', $endpoints['GET|HEAD /']->route_group);

        $codes = $this->findingCodes($scan);
        foreach (['dynamic_uri', 'unresolved_include', 'custom_registration', 'unreferenced_route_file'] as $code) {
            $this->assertContains($code, $codes);
        }
    }

    public function test_a_parse_error_in_one_file_gives_a_partial_scan_that_keeps_the_other_files()
    {
        $files = $this->laravel11();
        $files['routes/api.php'] = '<?php Route::get(';

        $scan = $this->scanOf($this->snapshotFrom($files, $this->project, $this->developer));

        $this->assertSame(RunStatus::Partial, $scan->status);
        $this->assertContains('parse_error', $this->findingCodes($scan));
        $this->assertArrayHasKey('GET|HEAD /', $this->endpoints($scan));
    }

    public function test_a_laravel_10_route_service_provider_with_a_namespace_is_followed()
    {
        $files = [
            'composer.json' => json_encode(['require' => ['laravel/framework' => '^10.0']]),
            'artisan' => '<?php',
            'bootstrap/app.php' => "<?php\n\$app = new Illuminate\\Foundation\\Application(dirname(__DIR__));\nreturn \$app;\n",
            'app/Providers/RouteServiceProvider.php' => <<<'PHP'
            <?php
            namespace App\Providers;
            use Illuminate\Support\Facades\Route;
            class RouteServiceProvider extends ServiceProvider {
                protected $namespace = 'App\Http\Controllers';
                public function boot(): void {
                    $this->routes(function () {
                        Route::middleware('api')->prefix('api')->namespace($this->namespace)->group(base_path('routes/api.php'));
                        Route::middleware('web')->group(base_path('routes/web.php'));
                    });
                }
            }
            PHP,
            'routes/api.php' => "<?php\nRoute::get('users', 'UserController@index');\nRoute::get('raw', 'Legacy\\RawController@show');\n",
            'routes/web.php' => "<?php\nRoute::get('/', 'HomeController@index');\n",
        ];

        $endpoints = $this->endpoints($this->scanOf($this->snapshotFrom($files, $this->project, $this->developer)));

        $this->assertSame('App\Http\Controllers\UserController', $endpoints['GET|HEAD /api/users']->controller);
        $this->assertSame('resolved', $endpoints['GET|HEAD /api/users']->resolution);
        $this->assertSame('App\Http\Controllers\Legacy\RawController', $endpoints['GET|HEAD /api/raw']->controller);
        // The web group was given no namespace, so the class can't be pinned down (and isn't guessed).
        $this->assertSame('HomeController', $endpoints['GET|HEAD /']->controller);
        $this->assertSame('partial', $endpoints['GET|HEAD /']->resolution);
    }

    public function test_an_unqualified_controller_string_without_a_namespace_is_partial_not_guessed()
    {
        $files = $this->laravel11();
        $files['routes/api.php'] = "<?php\nRoute::get('users', 'UserController@index');\n";

        $users = $this->endpoints($this->scanOf($this->snapshotFrom($files, $this->project, $this->developer)))['GET|HEAD /api/users'];

        $this->assertSame('UserController', $users->controller);
        $this->assertSame('partial', $users->resolution);
        $this->assertStringContainsString('no namespace', implode(' ', $users->reasons));
    }

    public function test_module_routes_use_the_modules_provider_or_noted_defaults()
    {
        $files = $this->laravel11();
        $files['routes/api.php'] = "<?php\n";
        $files['Modules/Blog/routes/api.php'] = "<?php\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get('posts', [\\Modules\\Blog\\Http\\PostController::class, 'index'])->name('posts.index');\n";
        $files['Modules/Shop/app/Providers/RouteServiceProvider.php'] = "<?php\nnamespace Modules\\Shop\\Providers;\nuse Illuminate\\Support\\Facades\\Route;\nclass RouteServiceProvider {\n public function boot(): void {\n  Route::prefix('shop-api')->middleware('api')->group(module_path('Shop', '/routes/api.php'));\n }\n}\n";
        $files['Modules/Shop/routes/api.php'] = "<?php\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get('carts', [\\Modules\\Shop\\Http\\CartController::class, 'index']);\n";

        $scan = $this->scanOf($this->snapshotFrom($files, $this->project, $this->developer));
        $endpoints = $this->endpoints($scan);

        $this->assertSame('module:Blog', $endpoints['GET|HEAD /api/posts']->route_group);
        $this->assertSame('api.posts.index', $endpoints['GET|HEAD /api/posts']->name);
        $this->assertSame('Shop', $endpoints['GET|HEAD /shop-api/carts']->module);
        $this->assertContains('module_defaults', $this->findingCodes($scan));
    }

    public function test_rescanning_is_idempotent_and_identities_are_stable_across_snapshots()
    {
        $files = $this->laravel11();
        $files['routes/api.php'] = "<?php\nRoute::get('users', [\\App\\Http\\Controllers\\UserController::class, 'index']);\n";
        $snapshot = $this->snapshotFrom($files, $this->project, $this->developer);
        $scan = $this->scanOf($snapshot);

        $again = app(RouteScans::class)->start($this->developer, app(FrameworkDetections::class)->current($snapshot));
        $this->assertSame($scan->id, $again->id);
        $this->assertSame(1, RouteScan::query()->count());
        $this->assertSame(2, ApiEndpoint::query()->count());

        $files['README.md'] = 'changed';
        $other = $this->scanOf($this->snapshotFrom($files, $this->project, $this->developer));
        $this->assertNotSame($scan->id, $other->id);
        $this->assertSame(
            ApiEndpoint::query()->where('route_scan_id', $scan->id)->where('uri', '/api/users')->value('route_identity'),
            ApiEndpoint::query()->where('route_scan_id', $other->id)->where('uri', '/api/users')->value('route_identity'),
        );
    }

    public function test_a_failed_scan_is_retried_without_touching_a_completed_one()
    {
        $files = $this->laravel11();
        $files['routes/api.php'] = "<?php\nRoute::get('users', [\\App\\Http\\Controllers\\UserController::class, 'index']);\n";
        $scan = $this->scanOf($this->snapshotFrom($files, $this->project, $this->developer));
        $detection = app(FrameworkDetections::class)->current($scan->snapshot);

        app(RouteScans::class)->fail($scan->id, 'internal');
        $this->assertSame(RunStatus::Completed, $scan->fresh()->status, 'completed scans are final');

        RouteScan::query()->whereKey($scan->id)->update(['status' => 'failed']);
        app(RouteScans::class)->start($this->developer, $detection);
        $this->assertSame(RunStatus::Completed, $scan->fresh()->status, 'the failed scan was re-run');
        $this->assertSame(2, ApiEndpoint::query()->where('route_scan_id', $scan->id)->count(), 'no duplicates');
    }

    public function test_with_routing_health_using_and_missing_files_behave_like_laravel()
    {
        $files = $this->laravel11("api: __DIR__.'/../routes/api.php', web: __DIR__.'/../routes/missing.php', health: '/up'");
        $files['routes/api.php'] = "<?php\nRoute::get('ping', [\\App\\Http\\Controllers\\PingController::class, 'show']);\n";
        $scan = $this->scanOf($this->snapshotFrom($files, $this->project, $this->developer));

        $this->assertSame('health', $this->endpoints($scan)['GET|HEAD /up']->handler_type);
        $this->assertArrayHasKey('GET|HEAD /api/ping', $this->endpoints($scan));
        $this->assertNotContains('missing_route_file', $this->findingCodes($scan), 'Laravel skips a missing listed file silently');

        $using = $this->laravel11("api: __DIR__.'/../routes/api.php', using: function () {}");
        $using['routes/api.php'] = $files['routes/api.php'];
        $other = Project::factory()->for($this->project->workspace)->create();
        $scan = $this->scanOf($this->snapshotFrom($using, $other, $this->developer));

        $this->assertArrayNotHasKey('GET|HEAD /api/ping', $this->endpoints($scan), 'using: replaces api:/web:');
        $this->assertContains('custom_registration', $this->findingCodes($scan));
    }

    public function test_hostile_route_files_cannot_exhaust_the_scan()
    {
        $files = $this->laravel11();
        // Fan-out: each file includes the next ten times.
        $files['routes/api.php'] = "<?php\nrequire __DIR__.'/huge.php';\n".str_repeat("require __DIR__.'/f1.php';\n", 10);
        for ($i = 1; $i <= 8; $i++) {
            $next = $i + 1;
            $files["routes/f{$i}.php"] = "<?php\nRoute::get('r{$i}', [\\App\\X::class, 'y']);\n".str_repeat("require __DIR__.'/f{$next}.php';\n", 10);
        }
        $files['routes/f9.php'] = "<?php\n";
        // Duplicate use alias (a resolver error) and a huge literal.
        $files['routes/web.php'] = "<?php\nuse A\\B as X;\nuse C\\D as X;\nRoute::get('/', [X::class, 'index']);\n";
        $files['routes/huge.php'] = '<?php $x = ['.str_repeat('1,', 200000).'];';

        $started = microtime(true);
        $scan = $this->scanOf($this->snapshotFrom($files, $this->project, $this->developer));

        $this->assertLessThan(20, microtime(true) - $started);
        $this->assertContains($scan->status->value, ['completed', 'partial']);
        $this->assertArrayHasKey('GET|HEAD /api/r8', $this->endpoints($scan), 'each file is still evaluated once');
        $this->assertContains('parse_error', $this->findingCodes($scan), 'the duplicate alias is a finding, not a crash');
        $this->assertContains('file_too_large', $this->findingCodes($scan), 'a huge included file is skipped before parsing');
    }

    public function test_viewers_also_get_no_source_details()
    {
        $files = $this->laravel11();
        $files['routes/api.php'] = "<?php\nRoute::get('users', [\\App\\Http\\Controllers\\UserController::class, 'index']);\n";
        $this->snapshotFrom($files, $this->project, $this->developer);
        $endpoint = ApiEndpoint::query()->where('uri', '/api/users')->firstOrFail();

        $this->withoutVite()->actingAs($this->memberOf(WorkspaceRole::Viewer, $this->project->workspace))
            ->get("/projects/{$this->project->id}/endpoints/{$endpoint->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('endpoint.source', null));
    }

    /** The StackFood layout: withRouting(health:) only; routes live in RouteServiceProvider, via closure variables and a config domain. */
    public function test_a_route_service_provider_next_to_with_routing_with_closure_variables_and_a_runtime_domain()
    {
        $files = $this->laravel11("health: '/up'");
        unset($files['routes/web.php']);
        $files['bootstrap/providers.php'] = "<?php\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n    App\\Providers\\RouteServiceProvider::class,\n];\n";
        $files['app/Providers/RouteServiceProvider.php'] = <<<'PHP'
        <?php
        namespace App\Providers;
        use Illuminate\Support\Facades\Route;
        class RouteServiceProvider extends ServiceProvider {
            protected $namespace = 'App\Http\Controllers';
            public function boot(): void {
                $this->routes(function () {
                    $registerHostRoutes = function () {
                        Route::prefix('admin')->middleware('web')->namespace($this->namespace)->group(base_path('routes/admin.php'));
                    };
                    $registerApiRoutes = function () {
                        Route::prefix('api/v1')->middleware('api')->namespace($this->namespace)->group(base_path('routes/api/v1/api.php'));
                    };
                    $registerApiRoutes();
                    $hostDomain = config('app.host_domain');
                    if ($hostDomain) {
                        Route::domain($hostDomain)->group($registerHostRoutes);
                    } else {
                        $registerHostRoutes();
                    }
                });
            }
        }
        PHP;
        $files['routes/api/v1/api.php'] = "<?php\nRoute::get('zones', 'Api\\V1\\ZoneController@index');\n";
        $files['routes/admin.php'] = "<?php\nRoute::get('dashboard', 'Admin\\DashboardController@index')->name('admin.dashboard');\n";
        $files['__MACOSX/app/._RouteServiceProvider.php'] = "\0\x05\x16\x07";

        $scan = $this->scanOf($this->snapshotFrom($files, $this->project, $this->developer));
        $endpoints = $this->endpoints($scan);

        $zones = $endpoints['GET|HEAD /api/v1/zones'];
        $this->assertSame('App\Http\Controllers\Api\V1\ZoneController', $zones->controller);
        $this->assertSame('resolved', $zones->resolution, 'API routes are registered unconditionally');

        $dashboard = $endpoints['GET|HEAD /admin/dashboard'];
        $this->assertSame('App\Http\Controllers\Admin\DashboardController', $dashboard->controller);
        // Registered in both the if (with a config domain) and the else branch: always present, domain is a note.
        $this->assertSame('resolved', $dashboard->resolution);
        $this->assertSame(['depending on configuration, this route may be limited to one domain'], $dashboard->reasons);
        $this->assertArrayHasKey('GET|HEAD /up', $endpoints);
        $this->assertNotContains('dynamic_group_attribute', $this->findingCodes($scan));
        $this->assertNotContains('unreferenced_route_file', $this->findingCodes($scan));
        $this->assertNotContains('overridden', $this->findingCodes($scan), 'one definition reached through both if/else branches is not an override');
    }

    public function test_a_module_provider_with_a_local_namespace_variable_used_inside_a_closure()
    {
        $files = $this->laravel11();
        $files['Modules/Builder/app/Providers/RouteServiceProvider.php'] = <<<'PHP'
        <?php
        namespace Modules\Builder\Providers;
        use Illuminate\Support\Facades\Route;
        class RouteServiceProvider {
            protected string $moduleNamespace = 'Modules\Builder\Http\Controllers';
            public function map(): void { $this->mapAdminRoutes(); }
            protected function mapAdminRoutes(): void {
                $moduleNamespace = $this->moduleNamespace;
                $register = function () use ($moduleNamespace) {
                    Route::middleware(['web', 'admin'])->prefix('admin')->namespace($moduleNamespace)->group(module_path('Builder', '/routes/admin.php'));
                };
                $register();
            }
        }
        PHP;
        $files['Modules/Builder/routes/admin.php'] = "<?php\nRoute::get('builder', 'BuilderController@index');\n";

        $scan = $this->scanOf($this->snapshotFrom($files, $this->project, $this->developer));

        $this->assertSame('Modules\Builder\Http\Controllers\BuilderController', $this->endpoints($scan)['GET|HEAD /admin/builder']->controller);
        $this->assertNotContains('dynamic_group_attribute', $this->findingCodes($scan));
    }

    public function test_branch_coverage_decides_whether_a_route_is_conditional()
    {
        $files = $this->laravel11();
        $files['routes/api.php'] = <<<'PHP'
        <?php
        use Illuminate\Support\Facades\Route;
        $both = fn () => Route::get('both', [\App\X::class, 'a']);
        if (config('a')) { $both(); } else { $both(); }

        if (config('b')) { Route::get('only-if', [\App\X::class, 'b']); }

        $three = fn () => Route::get('three', [\App\X::class, 'c']);
        if (config('c')) { $three(); } elseif (config('d')) { $three(); }

        $nested = fn () => Route::get('nested', [\App\X::class, 'd']);
        if (config('e')) {
            if (config('f')) { $nested(); } else { $nested(); }
        } else {
            $nested();
        }

        $twoOfThree = fn () => Route::get('two-of-three', [\App\X::class, 'e']);
        if (config('g')) { $twoOfThree(); } elseif (config('h')) { $twoOfThree(); } else { Route::get('other', [\App\X::class, 'f']); }

        foreach ([1] as $i) { Route::get('loop', [\App\X::class, 'g']); }

        $domainBoth = fn () => Route::get('dom', [\App\X::class, 'h']);
        if (config('i')) { Route::domain(config('host'))->group($domainBoth); } else { Route::domain(config('host2'))->group($domainBoth); }
        PHP;

        $e = $this->endpoints($this->scanOf($this->snapshotFrom($files, $this->project, $this->developer)));
        $res = fn (string $uri) => $e["GET|HEAD /api/{$uri}"]->resolution;

        $this->assertSame('resolved', $res('both'), 'if + else both register it');
        $this->assertSame('partial', $res('only-if'), 'if without else');
        $this->assertSame('partial', $res('three'), 'if + elseif but no else');
        $this->assertSame('resolved', $res('nested'), 'every path through nested ifs registers it');
        $this->assertSame('partial', $res('two-of-three'), 'the else branch registers something else');
        $this->assertSame('partial', $res('loop'), 'loops stay conditional');
        $this->assertSame('partial', $res('dom'), 'always registered, but always on a runtime domain');
        $this->assertStringContainsString('domain', implode(' ', $e['GET|HEAD /api/dom']->reasons));
    }
}
