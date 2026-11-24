<?php

namespace App\Domain\Discovery;

use App\Domain\Sources\SnapshotReader;
use App\Models\SourceSnapshot;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;

/**
 * Finds a Laravel application's route files the way its bootstrap would register them,
 * by reading (never running) bootstrap/app.php, RouteServiceProvider and module providers,
 * then hands each file to RouteEvaluator with the right prefix and middleware group.
 */
final class RouteDiscoverer
{
    public const VERSION = 'route-analyzer-v4'; // v2: provider next to withRouting, closure vars, runtime domains; v3: local vars, same-source dupes; v4: if/else branch coverage

    /**
     * Route files bigger than this are skipped with a finding. An AST costs ~500x its source
     * size for literal-heavy code, so this keeps a crafted file far from the worker's memory limit.
     */
    private const MAX_FILE_BYTES = 262144;

    /** All route-related files read in one scan, together. */
    private const MAX_TOTAL_BYTES = 2097152;

    private const MAX_FINDINGS = 2000;

    public function __construct(private readonly SnapshotReader $reader) {}

    /** @return array{routes: list<array<string, mixed>>, findings: list<array<string, mixed>>, files: list<string>} */
    public function discover(SourceSnapshot $snapshot, string $root): array
    {
        $parsed = [];
        $files = [];
        $budget = self::MAX_TOTAL_BYTES;
        $skipped = [];
        $loader = function (string $path) use ($snapshot, $root, &$parsed, &$files, &$budget, &$skipped): ?ParsedFile {
            if (! SnapshotPaths::within($path, $root)) {
                return null;
            }
            if (array_key_exists($path, $parsed)) {
                return $parsed[$path];
            }

            $code = $this->reader->read($snapshot, $path);
            if ($code === null) {
                return $parsed[$path] = null;
            }
            if (strlen($code) > self::MAX_FILE_BYTES || strlen($code) > $budget) {
                $skipped[] = $path;

                return $parsed[$path] = null;
            }
            $budget -= strlen($code);
            $code = mb_scrub($code, 'UTF-8'); // stored text must be valid UTF-8

            $files[$path] = true;

            return $parsed[$path] = ParsedFile::parse($path, $code, $root);
        };

        $evaluator = new RouteEvaluator($loader, $root);
        $at = fn (string $relative) => SnapshotPaths::join($root, $relative);

        // Laravel 11+ still boots a RouteServiceProvider that the app registers, alongside withRouting().
        $bootstrap = $this->fromBootstrap($at('bootstrap/app.php'), $loader, $evaluator, $root);
        $provider = $this->providerRegistered($at, $loader, $bootstrap)
            && $this->fromProvider($at('app/Providers/RouteServiceProvider.php'), $loader, $evaluator, new RouteContext);
        $found = $bootstrap || $provider;

        if (! $found) {
            $this->defaults($root, $at, $evaluator, null);
            $evaluator->findings[] = $this->note('info', 'assumed_defaults', 'No route registration was found in bootstrap/app.php or RouteServiceProvider, so the Laravel defaults were assumed: routes/api.php under /api with the "api" middleware group, and routes/web.php with "web".');
        }

        $this->modules($snapshot, $root, $loader, $evaluator);

        $routes = $this->finalize($evaluator->routes);
        $findings = $evaluator->findings;

        foreach (array_unique($skipped) as $path) {
            $findings[] = $this->note('warning', 'file_too_large', 'This route file is too large to analyze safely, so it was skipped.', $path);
        }

        foreach ($this->reader->paths($snapshot, $at('routes'), like: '%.php') as $routeFile) {
            if (SnapshotPaths::dirname($routeFile) === $at('routes') && ! isset($files[$routeFile])
                && ! in_array(basename($routeFile), ['console.php', 'channels.php'], true)) {
                $findings[] = $this->note('info', 'unreferenced_route_file', 'This route file is not registered anywhere the analyzer could see; it may be loaded at runtime.', $routeFile);
            }
        }

        $findings = [...$findings, ...$routes['findings']];
        if (count($findings) > self::MAX_FINDINGS) {
            $omitted = count($findings) - self::MAX_FINDINGS;
            $findings = [...array_slice($findings, 0, self::MAX_FINDINGS), $this->note('info', 'findings_truncated', "{$omitted} more findings were not stored.")];
        }

        return ['routes' => $routes['routes'], 'findings' => $findings, 'files' => array_keys($files)];
    }

    /** Laravel 11+: ->withRouting(web: …, api: …, apiPrefix: …, then: …, using: …) */
    private function fromBootstrap(string $path, \Closure $loader, RouteEvaluator $evaluator, string $root): bool
    {
        $file = $loader($path);
        if ($file === null) {
            return false;
        }

        $call = (new NodeFinder)->findFirst($file->stmts, fn (Node $n) => $n instanceof Expr\MethodCall && $n->name instanceof Node\Identifier && strtolower($n->name->toString()) === 'withrouting');
        if (! $call instanceof Expr\MethodCall) {
            return false;
        }

        $resolver = $file->resolver();
        $named = [];
        foreach ($call->args as $arg) {
            if ($arg instanceof Node\Arg && $arg->name !== null) {
                $named[$arg->name->toString()] = $arg->value;
            }
        }

        if (isset($named['using'])) {
            // Laravel ignores web:/api: entirely when using: is given.
            $evaluator->findings[] = $this->note('warning', 'custom_registration', 'bootstrap/app.php registers routes in a using: callback, which static analysis does not follow, so routes may be missing from this list.', $path, $call->getStartLine());

            return true;
        }

        if (isset($named['then'])) {
            $evaluator->findings[] = $this->note('warning', 'custom_registration', 'bootstrap/app.php registers extra routes in a then: callback, which static analysis does not follow. Routes registered there are missing from this list.', $path, $call->getStartLine());
        }

        $health = isset($named['health']) ? $resolver->resolve($named['health']) : null;
        if (is_string($health)) {
            $evaluator->addFrameworkRoute(['GET', 'HEAD'], $health, 'health', $path, $call->getStartLine());
        }

        $apiPrefix = isset($named['apiPrefix']) ? $resolver->resolve($named['apiPrefix']) : 'api';
        $apiPrefix = is_string($apiPrefix) ? $apiPrefix : 'api';

        foreach (['api' => new RouteContext(group: 'api', prefix: $apiPrefix, middleware: ['api']), 'web' => new RouteContext(group: 'web', middleware: ['web'])] as $key => $context) {
            if (! isset($named[$key])) {
                continue;
            }

            $value = $resolver->resolve($named[$key]);
            foreach (is_array($value) ? $value : [$value] as $routeFile) {
                $normalized = is_string($routeFile) ? SnapshotPaths::normalize($routeFile) : null;

                if ($normalized === null || ! SnapshotPaths::within($normalized, $root)) {
                    $evaluator->findings[] = $this->note('warning', 'unresolved_include', "The {$key} route file in withRouting() is not a fixed path inside the application.", $path, $call->getStartLine());

                    continue;
                }

                // Laravel skips a listed file that doesn't exist, silently.
                $loader($normalized) !== null && $evaluator->evaluateFile($normalized, $context);
            }
        }

        return true;
    }

    /**
     * Laravel ≤10 registers it in config/app.php; 11+ only if bootstrap/providers.php lists it.
     */
    private function providerRegistered(\Closure $at, \Closure $loader, bool $hasWithRouting): bool
    {
        $providers = $loader($at('bootstrap/providers.php'));

        if ($providers === null) {
            return true; // pre-11 layout: providers come from config/app.php
        }

        $listed = (new NodeFinder)->findFirst($providers->stmts, fn (Node $n) => $n instanceof Expr\ClassConstFetch
            && $n->class instanceof Node\Name
            && str_ends_with(strtolower(ltrim($n->class->toString(), '\\')), 'routeserviceprovider'));

        return $listed !== null || ! $hasWithRouting;
    }

    /** Laravel ≤10 (and module providers): Route::prefix('api')->middleware('api')->group(base_path(…)) chains. */
    private function fromProvider(string $path, \Closure $loader, RouteEvaluator $evaluator, RouteContext $context): bool
    {
        $file = $loader($path);
        if ($file === null) {
            return false;
        }

        $before = count($evaluator->routes);
        $evaluator->evaluateProvider($file, $context);

        return count($evaluator->routes) > $before;
    }

    private function defaults(string $root, \Closure $at, RouteEvaluator $evaluator, ?string $module): void
    {
        $evaluator->evaluateFile($at('routes/api.php'), new RouteContext(group: 'api', prefix: 'api', middleware: ['api'], module: $module));
        $evaluator->evaluateFile($at('routes/web.php'), new RouteContext(group: 'web', middleware: ['web'], module: $module));

        // The missing-file findings are noise when we were only guessing.
        $evaluator->findings = array_values(array_filter($evaluator->findings, fn ($f) => $f['code'] !== 'missing_route_file'));
    }

    /** nwidart/laravel-modules: each module's own RouteServiceProvider, or its defaults (noted as assumed). */
    private function modules(SourceSnapshot $snapshot, string $root, \Closure $loader, RouteEvaluator $evaluator): void
    {
        $modulesDir = SnapshotPaths::join($root, 'Modules');
        $names = [];
        foreach ($this->reader->paths($snapshot, $modulesDir) as $path) {
            $rest = substr($path, strlen($modulesDir) + 1);
            str_contains($rest, '/') && $names[explode('/', $rest)[0]] = true;
        }

        foreach (array_keys($names) as $name) {
            $base = "{$modulesDir}/{$name}";
            $context = new RouteContext(group: "module:{$name}", module: $name);
            $before = count($evaluator->routes);

            foreach (["{$base}/app/Providers/RouteServiceProvider.php", "{$base}/Providers/RouteServiceProvider.php"] as $provider) {
                if ($this->fromProvider($provider, $loader, $evaluator, $context)) {
                    break;
                }
            }

            if (count($evaluator->routes) === $before) {
                $found = false;
                foreach (['routes', 'Routes'] as $dir) {
                    foreach (['api.php' => new RouteContext(group: "module:{$name}", prefix: 'api', namePrefix: 'api.', middleware: ['api'], module: $name), 'web.php' => new RouteContext(group: "module:{$name}", middleware: ['web'], module: $name)] as $routeFile => $moduleContext) {
                        if ($loader("{$base}/{$dir}/{$routeFile}") !== null) {
                            $evaluator->evaluateFile("{$base}/{$dir}/{$routeFile}", $moduleContext);
                            $found = true;
                        }
                    }
                }

                $found && $evaluator->findings[] = $this->note('info', 'module_defaults', "Module {$name} has no RouteServiceProvider the analyzer could read, so nwidart's defaults were assumed (/api prefix and \"api.\" names for api.php). Check them against the module.", $base);
            }
        }
    }

    /**
     * Effective middleware, identity, and overrides: a later identical registration wins per HTTP method, like Laravel.
     *
     * @param  list<array<string, mixed>>  $routes
     * @return array{routes: list<array<string, mixed>>, findings: list<array<string, mixed>>}
     */
    private function finalize(array $routes): array
    {
        $byKey = [];
        $findings = [];

        foreach ($this->mergeBranches($routes) as $route) {
            $route['middleware'] = array_values(array_unique(array_diff($route['middleware'], $route['without_middleware'])));
            // Kept: Laravel also removes by resolved class (alias vs class name), which endpoint analysis checks.
            $route['without_middleware'] = array_values(array_unique($route['without_middleware']));
            $route['group'] = $route['module'] !== null ? 'module:'.$route['module'] : (in_array('api', $route['middleware'], true) ? 'api' : (in_array('web', $route['middleware'], true) ? 'web' : $route['group']));
            $route['auth'] = self::auth($route['middleware']);

            $methods = $route['methods'];
            sort($methods);
            $route['route_identity'] = hash('sha256', implode('|', $methods)."\n".($route['domain'] ?? '')."\n".$route['uri']);

            foreach ($route['methods'] as $method) {
                $key = $method.' '.($route['domain'] ?? '').$route['uri'];
                if (isset($byKey[$key]) && $byKey[$key]['identity'] !== $route['route_identity']) {
                    $findings[] = $this->note('info', 'overridden', "{$method} {$route['uri']} is registered more than once; the later registration wins.", $route['source_path'], $route['source_line']);
                }
                $byKey[$key] = ['identity' => $route['route_identity']];
            }

            $previous = $unique[$route['route_identity']] ?? null;
            // The same definition reached twice (e.g. both branches of an if/else calling one closure) is not an override.
            if ($previous !== null && ($previous['source_path'] !== $route['source_path'] || $previous['source_line'] !== $route['source_line'])) {
                $findings[] = $this->note('info', 'overridden', implode('|', $route['methods'])." {$route['uri']} is registered twice; the later registration wins.", $route['source_path'], $route['source_line']);
            }
            $unique[$route['route_identity']] = $route; // identical registration twice: keep the later one
        }

        return ['routes' => array_values($unique ?? []), 'findings' => $findings];
    }

    /**
     * Merges every registration of one definition (same identity, same source line) and decides
     * whether it is conditional: an if-with-else whose branches all register it is no condition.
     * A domain present in some branches but not others becomes a note, not a downgrade.
     *
     * @param  list<array<string, mixed>>  $routes
     * @return list<array<string, mixed>>
     */
    private function mergeBranches(array $routes): array
    {
        $groups = [];
        foreach ($routes as $route) {
            $methods = $route['methods'];
            sort($methods);
            $groups[implode('|', $methods)."\n".$route['uri']."\n".$route['source_path'].':'.$route['source_line']][] = $route;
        }

        $merged = [];
        foreach ($groups as $registrations) {
            // Which branches of each if were covered across all registrations.
            $covered = [];
            $sizes = [];
            foreach ($registrations as $r) {
                foreach ($r['branches'] as $branch) {
                    [$a, $b, $i, $count, $kind] = explode(':', $branch);
                    $covered["{$a}:{$b}"][$i] = true;
                    $sizes["{$a}:{$b}"] = [(int) $count, $kind];
                }
            }
            $complete = array_keys(array_filter($sizes, fn ($s, $if) => $s[1] === 'else' && count($covered[$if]) === $s[0], ARRAY_FILTER_USE_BOTH));

            $always = false;
            foreach ($registrations as $r) {
                $remaining = array_filter($r['branches'], fn ($b) => ! in_array(implode(':', array_slice(explode(':', $b), 0, 2)), $complete, true));
                $always = $always || $remaining === [];
            }

            $route = end($registrations); // the later registration wins, as in Laravel
            $domains = array_unique(array_map(fn ($r) => $r['runtime_domain'], $registrations));

            if (! $always) {
                $route['resolution'] === 'resolved' && $route['resolution'] = 'partial';
                $route['reasons'][] = 'registered inside an if without a matching else, so it may not always exist';
            }

            if ($domains === [true]) {
                $route['resolution'] === 'resolved' && $route['resolution'] = 'partial';
                $route['reasons'][] = 'served on a domain that is only known at runtime (e.g. from config)';
            } elseif (count($domains) > 1) {
                // Registered with a config-driven domain in some branches and without one in others.
                $route['reasons'][] = 'depending on configuration, this route may be limited to one domain'; // a note: resolution stays resolved
            }

            unset($route['branches'], $route['runtime_domain']);
            $merged[] = $route;
        }

        return $merged;
    }

    /**
     * Three states, never "no auth": custom middleware may still enforce it.
     *
     * @param  list<string>  $middleware
     */
    public static function auth(array $middleware): string
    {
        foreach ($middleware as $m) {
            if ($m === 'auth' || str_starts_with($m, 'auth:') || str_starts_with($m, 'auth.basic')) {
                return 'required';
            }
        }

        return 'unknown';
    }

    /** @return array<string, mixed> */
    private function note(string $severity, string $code, string $message, ?string $path = null, ?int $line = null): array
    {
        return ['severity' => $severity, 'code' => $code, 'message' => $message, 'source_path' => $path, 'source_line' => $line, 'expression' => null];
    }
}
