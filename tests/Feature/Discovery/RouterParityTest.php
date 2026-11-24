<?php

namespace Tests\Feature\Discovery;

use App\Domain\Discovery\ParsedFile;
use App\Domain\Discovery\RouteContext;
use App\Domain\Discovery\RouteEvaluator;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The static analyzer must agree with Laravel's real router on our own trusted fixtures.
 * (Only test fixtures are ever executed, here, to get the ground truth; uploads never are.)
 */
class RouterParityTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function corpora(): iterable
    {
        yield 'everyday routes' => ['tests/Fixtures/routes/corpus-api.php'];
        yield 'edge cases' => ['tests/Fixtures/routes/corpus-edge.php'];
    }

    /** @return array<string, array<string, mixed>> keyed by "METHODS URI" */
    private function laravel(string $fixture): array
    {
        $router = new Router(new Dispatcher($this->app), $this->app);
        $original = Route::getFacadeRoot();
        Route::swap($router);

        try {
            $router->middleware('api')->prefix('api')->group(base_path($fixture));
        } finally {
            Route::swap($original);
        }

        $out = [];
        foreach ($router->getRoutes()->getRoutes() as $route) {
            /** @var LaravelRoute $route */
            $uses = $route->getAction('uses');
            // Laravel 13 added the HTTP QUERY verb to Route::any(); the apps we analyze may predate it.
            $methods = array_values(array_diff($route->methods(), ['QUERY']));
            $out[implode('|', $methods).' /'.ltrim($route->uri(), '/')] = [
                'name' => $route->getName(),
                'domain' => $route->getDomain(),
                'handler' => is_string($uses) ? ltrim($uses, '\\') : 'closure',
                'middleware' => array_values(array_diff($route->middleware(), $route->excludedMiddleware())),
            ];
        }

        ksort($out);

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    private function static(string $fixture): array
    {
        $path = $fixture;
        $evaluator = new RouteEvaluator(fn (string $p) => ParsedFile::parse($p, (string) file_get_contents(base_path($p)), '.'), '.');
        $evaluator->evaluateFile($path, new RouteContext(group: 'api', prefix: 'api', middleware: ['api']));

        $out = [];
        foreach ($evaluator->routes as $r) {
            $handler = match ($r['handler_type']) {
                'controller', 'invokable' => $r['controller'].'@'.$r['action'],
                'view' => 'Illuminate\Routing\ViewController@__invoke',
                'redirect' => 'Illuminate\Routing\RedirectController@__invoke',
                default => 'closure',
            };
            // Laravel lists only the last registration of an identical route; so do we after finalize().
            $out[implode('|', $r['methods']).' '.$r['uri']] = [
                'name' => $r['name'],
                'domain' => $r['domain'],
                'handler' => $handler,
                'middleware' => array_values(array_diff($r['middleware'], $r['without_middleware'])),
            ];
        }

        ksort($out);

        return $out;
    }

    #[DataProvider('corpora')]
    public function test_static_discovery_matches_laravels_router(string $fixture)
    {
        $expected = $this->laravel($fixture);
        $actual = $this->static($fixture);

        $this->assertSame(array_keys($expected), array_keys($actual), 'same methods + URIs');

        foreach ($expected as $key => $route) {
            $this->assertSame($route, $actual[$key], $key);
        }
    }
}
