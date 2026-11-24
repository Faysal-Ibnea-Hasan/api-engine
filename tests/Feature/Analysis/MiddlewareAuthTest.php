<?php

namespace Tests\Feature\Analysis;

use App\Domain\Analysis\AnalysisResultV1;
use App\Domain\Analysis\EndpointAnalyzer;
use App\Domain\Workspaces\WorkspaceRole;
use App\Models\ApiEndpoint;
use App\Models\Project;
use App\Models\RouteScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AnalyzesFixtures;
use Tests\Concerns\BuildsSnapshots;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

/**
 * Authentication is decided from the middleware's own code, path by path. These fixtures
 * reproduce the shapes that matter, including the ones a name- or "401 appears"-based check
 * gets wrong: a token looked up on only some branches, an always-true guard check, optional auth.
 */
class MiddlewareAuthTest extends TestCase
{
    use AnalyzesFixtures, BuildsSnapshots, RefreshDatabase, WorksWithWorkspaces;

    private const M = 'Fixture\\App\\Http\\Middleware\\';

    private const BOOTSTRAP = <<<'PHP'
        <?php
        use Fixture\App\Http\Middleware\AbortIfGuest;
        use Fixture\App\Http\Middleware\Activation503;
        use Fixture\App\Http\Middleware\AppAuthenticate;
        use Fixture\App\Http\Middleware\BranchOnlyLookup;
        use Fixture\App\Http\Middleware\GuestOrToken;
        use Fixture\App\Http\Middleware\HelperToken403;
        use Fixture\App\Http\Middleware\ModuleHeaderGate;
        use Fixture\App\Http\Middleware\Noop;
        use Fixture\App\Http\Middleware\OpaqueGate;
        use Fixture\App\Http\Middleware\OptionalUser;
        use Fixture\App\Http\Middleware\PublicBypass;
        use Fixture\App\Http\Middleware\SecretPair;
        use Fixture\App\Http\Middleware\Throttle429;
        use Fixture\App\Http\Middleware\TokenLookup;
        use Fixture\App\Http\Middleware\ValidatorExists;
        use Illuminate\Foundation\Application;
        use Illuminate\Foundation\Configuration\Middleware;

        return Application::configure(basePath: dirname(__DIR__))
            ->withRouting(api: __DIR__.'/../routes/api.php')
            ->withMiddleware(function (Middleware $middleware) {
                %EXTRA%
                $middleware->alias([
                    'auth' => AppAuthenticate::class,
                    'token.lookup' => TokenLookup::class,
                    'branch.lookup' => BranchOnlyLookup::class,
                    'guest.or.token' => GuestOrToken::class,
                    'validator.exists' => ValidatorExists::class,
                    'secret.pair' => SecretPair::class,
                    'noop' => Noop::class,
                    'throttle429' => Throttle429::class,
                    'activation' => Activation503::class,
                    'optional.user' => OptionalUser::class,
                    'public.bypass' => PublicBypass::class,
                    'abort.guest' => AbortIfGuest::class,
                    'opaque.gate' => OpaqueGate::class,
                    'module.header' => ModuleHeaderGate::class,
                    'helper.token' => HelperToken403::class,
                ]);
            })
            ->create();
        PHP;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('quarantine', ['throw' => true]);
        Storage::fake('snapshots', ['throw' => true]);
    }

    /**
     * @param  list<string>  $middleware
     * @param  array<string, string>  $files
     * @param  list<string>|null  $without
     * @return array<string, mixed>
     */
    private function analyze(array $middleware, array $files = [], string $extra = '', ?array $without = []): array
    {
        $project = Project::factory()->create();
        $snapshot = $this->snapshotFrom($this->fixtureApp([
            'bootstrap/app.php' => str_replace('%EXTRA%', $extra, self::BOOTSTRAP),
            ...$files,
        ]), $project, $this->memberOf(WorkspaceRole::Developer, $project->workspace));

        $endpoint = ApiEndpoint::factory()->create([
            'route_scan_id' => RouteScan::factory()->create(['source_snapshot_id' => $snapshot->id])->id,
            'controller' => 'Fixture\App\Http\Controllers\OrderController', 'action' => 'index', 'uri' => '/api/orders',
            'middleware' => $middleware, 'without_middleware' => $without, 'source_path' => 'routes/api.php', 'source_line' => 1,
        ]);

        $result = app(EndpointAnalyzer::class)->analyze($snapshot, $endpoint, '.');
        $this->assertSame([], AnalysisResultV1::problems($result));

        return $result;
    }

    /** @return array<string, mixed> */
    private static function signal(array $result, string $middleware): array
    {
        foreach ($result['auth']['signals'] as $s) {
            if ($s['middleware'] === $middleware) {
                return $s;
            }
        }
        self::fail("no signal for {$middleware}: ".json_encode(array_column($result['auth']['signals'], 'middleware')));
    }

    /** @return array<string, array{string, string, string}> alias => [middleware verdict, endpoint auth status] */
    public static function shapes(): array
    {
        return [
            'token looked up on every path' => ['token.lookup', 'authenticates', 'required'],
            'token looked up only on some branches' => ['branch.lookup', 'presence_only', 'required_unverified'],
            'any Authorization header, or a guest_id' => ['guest.or.token', 'guest_or_user', 'guest_or_user'],
            'validator exists: on a token field' => ['validator.exists', 'authenticates', 'required'],
            'key + secret via hash_equals, rejected by a helper' => ['secret.pair', 'authenticates', 'required'],
            'does nothing' => ['noop', 'none', 'not_required'],
            'only rate limits (429)' => ['throttle429', 'none', 'not_required'],
            'only an activation check (503)' => ['activation:shop', 'none', 'not_required'],
            'optional auth: guests pass' => ['optional.user', 'optional', 'not_required'],
            'checks the user except under a URL prefix' => ['public.bypass', 'conditional', 'unknown'],
            'abort_if without a user' => ['abort.guest', 'authenticates', 'required'],
            'subclass of Laravel Authenticate' => ['auth:api', 'authenticates', 'required'],
            '401 with no detected token check' => ['opaque.gate', 'none', 'not_required'],
            'alias registered nowhere' => ['mystery', 'unknown', 'not_required'],
            'a non-credential header looked up (moduleId)' => ['module.header', 'none', 'not_required'],
            'token checked inside an unreadable helper, 403' => ['helper.token', 'unknown', 'unknown'],
        ];
    }

    #[DataProvider('shapes')]
    public function test_each_middleware_shape_gets_the_right_verdict(string $alias, string $verdict, string $status): void
    {
        $result = $this->analyze(['api', $alias]);

        $this->assertSame($verdict, self::signal($result, $alias)['verdict'], json_encode(self::signal($result, $alias)));
        $this->assertSame($status, $result['auth']['status'], $result['auth']['reason']);
    }

    public function test_credentials_become_headers_and_fields(): void
    {
        $secret = $this->analyze(['api', 'secret.pair']);
        $headers = collect($secret['request']['headers'])->keyBy('name');
        $this->assertSame('Bearer <token>', $headers['Authorization']['value']);
        $this->assertSame('required', $headers['X-Api-Secret']['required']);
        $this->assertSame('verified', $headers['X-Api-Secret']['status']);
        $this->assertSame([401], array_column(array_filter($secret['responses'], fn ($r) => $r['status'] === 401), 'status'));

        $courier = $this->analyze(['api', 'validator.exists']);
        $token = collect($courier['request']['fields'])->firstWhere('name', 'token');
        $this->assertSame('required', $token['required']);
        $this->assertSame('middleware', $token['source']);

        $guest = $this->analyze(['api', 'guest.or.token']);
        $this->assertSame('conditional', collect($guest['request']['headers'])->firstWhere('name', 'Authorization')['required']);
        $this->assertSame('conditional', collect($guest['request']['fields'])->firstWhere('name', 'guest_id')['required']);
    }

    public function test_laravel_authenticate_uses_the_guard_driver_from_config(): void
    {
        $result = $this->analyze(['api', 'auth:api']);

        $this->assertStringContainsString('passport', implode(' ', self::signal($result, 'auth:api')['credentials']));
        $this->assertSame('Bearer <token>', collect($result['request']['headers'])->firstWhere('name', 'Authorization')['value']);
    }

    public function test_global_middleware_applies_to_every_route(): void
    {
        $result = $this->analyze(['api'], extra: '$middleware->append(TokenLookup::class);');

        $this->assertSame('required', $result['auth']['status']);
        $this->assertSame('global', self::signal($result, self::M.'TokenLookup')['from']);
    }

    public function test_group_overrides_are_read(): void
    {
        $this->assertSame('not_required', $this->analyze(['api'], extra: "\$middleware->group('api', [Noop::class]);")['auth']['status']);
        $this->assertSame('required', $this->analyze(['api'], extra: "\$middleware->appendToGroup('api', 'token.lookup');")['auth']['status']);
        $this->assertSame('required', $this->analyze(['api'], extra: '$middleware->api(append: [TokenLookup::class]);')['auth']['status']);
        $this->assertSame('unknown', $this->analyze(['api'], extra: "\$middleware->group('api', \$this->apiStack());")['auth']['status'], 'a group built at runtime is not "not required"');
    }

    public function test_without_middleware_removes_by_resolved_class_like_laravel(): void
    {
        $removed = $this->analyze(['api', 'auth:api'], without: [self::M.'AppAuthenticate']);
        $this->assertSame('not_required', $removed['auth']['status']);

        $legacy = $this->analyze(['api', 'token.lookup'], without: null);
        $this->assertSame('required', $legacy['auth']['status'], 'an older scan without the list still analyzes when the route has no withoutMiddleware');
    }

    public function test_laravel_10_kernel_aliases_and_groups(): void
    {
        $kernel = <<<'PHP'
            <?php
            namespace Fixture\App\Http;

            use Illuminate\Foundation\Http\Kernel as HttpKernel;

            class Kernel extends HttpKernel
            {
                protected $middleware = [\Illuminate\Http\Middleware\HandleCors::class];

                protected $middlewareGroups = [
                    'api' => [\Illuminate\Routing\Middleware\SubstituteBindings::class],
                ];

                protected $middlewareAliases = [
                    'customer.api' => \Fixture\App\Http\Middleware\TokenLookup::class,
                ];
            }
            PHP;

        $result = $this->analyze(['api', 'customer.api'], ['app/Http/Kernel.php' => $kernel, 'bootstrap/app.php' => "<?php\n\$app = new Illuminate\\Foundation\\Application(dirname(__DIR__));\nreturn \$app;\n"]);

        $this->assertSame('required', $result['auth']['status']);
    }

    public function test_module_provider_aliases(): void
    {
        $provider = <<<'PHP'
            <?php
            namespace Modules\Shop\Providers;

            use Illuminate\Routing\Router;
            use Illuminate\Support\ServiceProvider;

            class ShopServiceProvider extends ServiceProvider
            {
                public function boot(Router $router): void
                {
                    $router->aliasMiddleware('shop.auth', \Fixture\App\Http\Middleware\ValidatorExists::class);
                }
            }
            PHP;

        $result = $this->analyze(['api', 'shop.auth'], ['Modules/Shop/Providers/ShopServiceProvider.php' => $provider]);

        $this->assertSame('required', $result['auth']['status']);
    }

    public function test_the_action_itself_can_require_a_user(): void
    {
        $this->assertSame('required', $this->analyzeAction('mine')['auth']['status'], 'returns 401 up front unless auth(api)->user()');
        $this->assertSame('unknown', $this->analyzeAction('favourite')['auth']['status'], 'refuses guests only when saving');
        $this->assertSame('not_required', $this->analyzeAction('personalised')['auth']['status'], 'reads the user if present, never refuses');
        $this->assertSame('not_required', $this->analyzeAction('signIn')['auth']['status'], 'a login form checks a password, not an existing sign-in');
        $this->assertSame('not_required', $this->analyzeAction('guardLogin')['auth']['status'], 'attempt() signs the caller in; checks after it are about that account');
    }

    /** @return array<string, mixed> */
    private function analyzeAction(string $action): array
    {
        $project = Project::factory()->create();
        $snapshot = $this->snapshotFrom($this->fixtureApp(['bootstrap/app.php' => str_replace('%EXTRA%', '', self::BOOTSTRAP)]), $project, $this->memberOf(WorkspaceRole::Developer, $project->workspace));
        $endpoint = ApiEndpoint::factory()->create([
            'route_scan_id' => RouteScan::factory()->create(['source_snapshot_id' => $snapshot->id])->id,
            'controller' => 'Fixture\App\Http\Controllers\AccountController', 'action' => $action, 'uri' => '/api/'.$action,
            'middleware' => ['api'], 'without_middleware' => [], 'source_path' => 'routes/api.php', 'source_line' => 1,
        ]);

        return app(EndpointAnalyzer::class)->analyze($snapshot, $endpoint, '.');
    }

    public function test_one_authenticating_middleware_is_enough_even_next_to_unreadable_ones(): void
    {
        $result = $this->analyze(['api', 'mystery', 'token.lookup']);

        $this->assertSame('required', $result['auth']['status']);
    }

    public function test_a_controller_middleware_scoped_away_from_the_action_does_not_count(): void
    {
        // OrderController: auth:sanctum ->except('index')
        $result = $this->analyze(['api']);

        $this->assertSame('not_required', $result['auth']['status'], $result['auth']['reason']);
    }
}
