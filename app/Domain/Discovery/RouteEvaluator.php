<?php

namespace App\Domain\Discovery;

use Closure;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\PrettyPrinter\Standard as Printer;

/**
 * Walks route-file ASTs and records every route registration it can resolve statically,
 * plus a finding for everything it can't. It interprets Laravel's routing API as data:
 * no route file, controller or provider is ever executed or included by PHP.
 *
 * Output shape per route: methods, uri, domain, name, handler_type, controller, action,
 * middleware, group, module, source_path, source_line, expression, resolution, reasons.
 */
final class RouteEvaluator
{
    private const VERBS = [
        'get' => ['GET', 'HEAD'], 'post' => ['POST'], 'put' => ['PUT'], 'patch' => ['PATCH'],
        'delete' => ['DELETE'], 'options' => ['OPTIONS'],
        'any' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    ];

    private const RESOURCE_ACTIONS = ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'];

    private const API_RESOURCE_ACTIONS = ['index', 'store', 'show', 'update', 'destroy'];

    /** @var list<array<string, mixed>> */
    public array $routes = [];

    /** @var list<array<string, mixed>> */
    public array $findings = [];

    private Printer $printer;

    /** @var array<string, true> files on the current include stack, for cycle detection */
    private array $including = [];

    /** Source of the file being walked, so expressions are stored exactly as written. */
    private string $code = '';

    /** @var array<string, true> (file, context) pairs already evaluated: includes can't fan out exponentially */
    private array $evaluated = [];

    private int $evaluations = 0;

    /** Total file evaluations per scan; far above real apps, far below a crafted fan-out. */
    private const MAX_EVALUATIONS = 500;

    /** @var array<string, Expr\Closure|Expr\ArrowFunction> closures assigned to variables, e.g. = function () {…} */
    private array $closures = [];

    /** Longest fluent chain interpreted; real route chains have a handful of calls. */
    private const MAX_CHAIN = 100;

    /**
     * @param  Closure(string $path): ?ParsedFile  $load  reads + parses a snapshot file by exact path
     */
    public function __construct(
        private readonly Closure $load,
        private readonly string $appRoot,
        private readonly int $maxIncludeDepth = 8,
        private readonly int $maxRoutes = 20000,
        private readonly int $maxFindings = 2000,
        private readonly int $maxGroupDepth = 32,
    ) {
        $this->printer = new Printer;
    }

    public function evaluateFile(string $path, RouteContext $context, int $depth = 0, ?ParsedFile $parsed = null): void
    {
        if ($depth > $this->maxIncludeDepth) {
            $this->finding('warning', 'include_depth', 'Route files include each other too deeply; stopped following includes.', $path);

            return;
        }

        if (isset($this->including[$path])) {
            $this->finding('warning', 'include_cycle', 'Route files include each other in a cycle; the repeated include was skipped.', $path);

            return;
        }

        $visit = $path."\n".md5(serialize($context));
        if (isset($this->evaluated[$visit])) {
            return; // same file in the same context: Laravel would register the same routes again
        }
        if (++$this->evaluations > self::MAX_EVALUATIONS) {
            $this->evaluations === self::MAX_EVALUATIONS + 1
                && $this->finding('warning', 'too_many_includes', 'Route files include each other too many times; the rest were not followed.', $path);

            return;
        }
        $this->evaluated[$visit] = true;

        $parsed ??= ($this->load)($path);

        if ($parsed === null) {
            $this->finding('warning', 'missing_route_file', 'A referenced route file is not in the snapshot.', $path);

            return;
        }

        foreach ($parsed->errors as $error) {
            $this->finding('error', 'parse_error', "PHP could not be parsed: {$error['message']}", $path, $error['line']);
        }

        $this->including[$path] = true;
        [$previous, $this->code] = [$this->code, $parsed->code];

        try {
            $this->statements($parsed->stmts, $context, $parsed, $depth, 0);
        } catch (\Throwable $e) {
            report($e); // one odd file must not lose the whole scan
            $this->finding('error', 'analysis_error', 'This route file could not be analyzed completely.', $path);
        } finally {
            $this->code = $previous;
            unset($this->including[$path]);
        }
    }

    /** Routes the framework itself adds, e.g. withRouting(health: '/up'). */
    /** @param  list<string>  $methods */
    public function addFrameworkRoute(array $methods, string $uri, string $handlerType, string $path, int $line): void
    {
        $this->routes[] = [
            'methods' => $methods, 'uri' => self::joinUri('', $uri), 'domain' => null, 'name' => null,
            'handler_type' => $handlerType, 'controller' => null, 'action' => null,
            'middleware' => [], 'without_middleware' => [], 'group' => 'other', 'module' => null,
            'source_path' => $path, 'source_line' => $line, 'expression' => null,
            'resolution' => 'resolved', 'reasons' => [], 'branches' => [], 'runtime_domain' => false,
        ];
    }

    /**
     * Evaluates a RouteServiceProvider: boot() and every map*() method, with the class's
     * literal properties (e.g. $namespace) known to the value resolver.
     */
    public function evaluateProvider(ParsedFile $file, RouteContext $context): void
    {
        foreach ($file->parseErrors() as $error) {
            $this->finding('error', 'parse_error', "PHP could not be parsed: {$error['message']}", $file->path, $error['line']);
        }

        foreach ($file->classes() as $class) {
            foreach ($class->getProperties() as $property) {
                foreach ($property->props as $prop) {
                    $value = $file->resolver()->resolve($prop->default);
                    ValueResolver::isDynamic($value) || $file->properties[$prop->name->toString()] = $value;
                }
            }

            foreach ($class->getMethods() as $method) {
                $name = strtolower($method->name->toString());
                if (($name === 'boot' || str_starts_with($name, 'map')) && $method->stmts !== null) {
                    $this->including[$file->path] = true;
                    [$previous, $this->code] = [$this->code, $file->code];

                    try {
                        $this->statements($method->stmts, $context, $file, 0, 0);
                    } catch (\Throwable $e) {
                        report($e);
                        $this->finding('error', 'analysis_error', 'This service provider could not be analyzed completely.', $file->path);
                    } finally {
                        $this->code = $previous;
                        unset($this->including[$file->path]);
                    }
                }
            }
        }
    }

    /** @param  array<Node>  $stmts */
    private function statements(array $stmts, RouteContext $context, ParsedFile $file, int $depth, int $nesting): void
    {
        if ($nesting > $this->maxGroupDepth) {
            $this->finding('warning', 'group_depth', 'Route groups are nested too deeply; the inner routes were skipped.', $file->path);

            return;
        }

        $conditional = $context->with(['conditional' => true]);

        foreach ($stmts as $stmt) {
            match (true) {
                $stmt instanceof Stmt\Expression => $this->expression($stmt->expr, $context, $file, $depth, $nesting),
                $stmt instanceof Stmt\Return_ && $stmt->expr !== null => $this->expression($stmt->expr, $context, $file, $depth, $nesting),
                $stmt instanceof Stmt\Namespace_ => $this->statements($stmt->stmts, $context, $file, $depth, $nesting),
                $stmt instanceof Stmt\Block => $this->statements($stmt->stmts, $context, $file, $depth, $nesting),
                $stmt instanceof Stmt\TryCatch => $this->tryCatch($stmt, $context, $conditional, $file, $depth, $nesting),
                $stmt instanceof Stmt\Switch_ => array_map(fn (Stmt\Case_ $case) => $this->statements($case->stmts, $conditional, $file, $depth, $nesting + 1), $stmt->cases),
                $stmt instanceof Stmt\If_ => $this->conditionalBranches($stmt, $context, $file, $depth, $nesting),
                $stmt instanceof Stmt\Foreach_, $stmt instanceof Stmt\For_, $stmt instanceof Stmt\While_, $stmt instanceof Stmt\Do_ => $this->statements($stmt->stmts, $conditional, $file, $depth, $nesting + 1),
                default => null,
            };
        }
    }

    private function tryCatch(Stmt\TryCatch $try, RouteContext $context, RouteContext $conditional, ParsedFile $file, int $depth, int $nesting): void
    {
        $this->statements($try->stmts, $context, $file, $depth, $nesting);
        foreach ($try->catches as $catch) {
            $this->statements($catch->stmts, $conditional, $file, $depth, $nesting + 1);
        }
        $try->finally !== null && $this->statements($try->finally->stmts, $context, $file, $depth, $nesting);
    }

    /**
     * Each branch is tagged, so a definition reached through every branch of an if that has an
     * else (e.g. `if ($domain) Route::domain($domain)->group($r); else $r();`) counts as always registered.
     */
    private function conditionalBranches(Stmt\If_ $if, RouteContext $context, ParsedFile $file, int $depth, int $nesting): void
    {
        $bodies = [$if->stmts, ...array_map(fn (Stmt\ElseIf_ $e) => $e->stmts, $if->elseifs)];
        $if->else !== null && $bodies[] = $if->else->stmts;
        $id = spl_object_id($if).':'.$this->evaluations;
        $kind = $if->else !== null ? 'else' : 'noelse';

        foreach ($bodies as $i => $body) {
            $this->statements($body, $context->with(['branch' => "{$id}:{$i}:".count($bodies).":{$kind}"]), $file, $depth, $nesting + 1);
        }
    }

    private function expression(Expr $expr, RouteContext $context, ParsedFile $file, int $depth, int $nesting): void
    {
        if ($expr instanceof Expr\Assign) {
            if ($expr->var instanceof Expr\Variable && is_string($expr->var->name)
                && ($expr->expr instanceof Expr\Closure || $expr->expr instanceof Expr\ArrowFunction)) {
                $this->closures[$expr->var->name] = $expr->expr; // followed when called or passed to group()

                return;
            }

            // $moduleNamespace = $this->moduleNamespace; — remembered so later uses resolve.
            if ($expr->var instanceof Expr\Variable && is_string($expr->var->name)) {
                $value = $file->resolver()->resolve($expr->expr);
                ValueResolver::isDynamic($value)
                    ? $file->variables[$expr->var->name] = new Dynamic('variable assigned a dynamic value')
                    : $file->variables[$expr->var->name] = $value;
            }

            $this->expression($expr->expr, $context, $file, $depth, $nesting);

            return;
        }

        // $registerApiRoutes();
        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Expr\Variable && is_string($expr->name->name)) {
            $closure = $this->closures[$expr->name->name] ?? null;
            $closure !== null && $this->closureBody($closure, $context, $file, $depth, $nesting);

            return;
        }

        if ($expr instanceof Expr\Include_) {
            $this->includeFile($file->resolver()->resolve($expr->expr), $context, $file, $expr, $depth);

            return;
        }

        // Service providers: $this->routes(function () {…}) and $this->loadRoutesFrom(path)
        if ($expr instanceof Expr\MethodCall && $expr->var instanceof Expr\Variable && $expr->var->name === 'this' && $expr->name instanceof Node\Identifier) {
            $arg = $expr->args[0] ?? null;
            $value = $arg instanceof Node\Arg ? $arg->value : null;

            match (strtolower($expr->name->toString())) {
                'routes' => $value instanceof Expr\Closure ? $this->statements($value->stmts, $context, $file, $depth, $nesting + 1) : null,
                'loadroutesfrom' => $this->includeFile($file->resolver()->resolve($value), $context, $file, $expr, $depth),
                default => null,
            };

            return;
        }

        if ($expr instanceof Expr\MethodCall || $expr instanceof Expr\StaticCall) {
            $this->chain($expr, $context, $file, $depth, $nesting);
        }
    }

    /** One fluent chain: Route::prefix('x')->middleware('y')->group(...), Route::get(...)->name(...), … */
    private function chain(Expr\MethodCall|Expr\StaticCall $expr, RouteContext $context, ParsedFile $file, int $depth, int $nesting): void
    {
        $calls = [];
        $node = $expr;

        while ($node instanceof Expr\MethodCall) {
            $calls[] = $node;
            $node = $node->var;
        }

        $rootIsRouter = ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name && $this->isRouteFacade($node->class))
            || ($node instanceof Expr\Variable && in_array($node->name, ['router', 'route'], true));

        if (! $rootIsRouter) {
            return;
        }

        if ($node instanceof Expr\StaticCall) {
            $calls[] = $node;
        }

        $calls = array_reverse($calls);

        if (count($calls) > self::MAX_CHAIN) {
            $this->finding('warning', 'chain_too_long', 'A route definition chains too many calls to analyze.', $file->path, $expr->getStartLine());

            return;
        }

        $resolver = $file->resolver();
        $attributes = [];
        $created = [];      // indexes of routes this chain registered
        $resource = null;   // pending resource registration

        foreach ($calls as $call) {
            if (! $call->name instanceof Node\Identifier) {
                $this->finding('warning', 'dynamic_method', 'A route method is called by a dynamic name.', $file->path, $call->getStartLine(), $this->print($expr));

                return;
            }

            $method = strtolower($call->name->toString());
            $args = $call->args;
            $first = fn () => $resolver->arg($args, 0);

            switch ($method) {
                case 'prefix': case 'domain': case 'controller': case 'namespace':
                    $value = $first();
                    if ($method === 'domain' && ! is_string($value)) {
                        // e.g. Route::domain(config('app.host_domain')): the paths are still known.
                        $attributes['runtime_domain'] = true;
                        break;
                    }
                    if (! is_string($value)) {
                        $this->finding('warning', 'dynamic_group_attribute', "A route {$method}() is not a fixed value; routes in this group were skipped.", $file->path, $call->getStartLine(), $this->print($expr));

                        return;
                    }
                    $attributes[$method] = $value;
                    break;

                case 'name': case 'as':
                    $value = $first();
                    if ($created !== []) {
                        $this->applyToCreated($created, fn (array &$r) => is_string($value)
                            ? $r['name'] = ($r['name'] ?? '').$value
                            : $this->downgrade($r, 'route name is not a fixed value'));
                    } elseif ($resource !== null) {
                        is_string($value) && $resource['as'] = $value;
                    } elseif (is_string($value)) {
                        $attributes['as'] = $value; // RouteRegistrar replaces, it doesn't append
                    }
                    break;

                case 'middleware': case 'withoutmiddleware': case 'can':
                    $value = $method === 'can' ? 'can:'.(is_string($first()) ? $first() : '?') : $this->middlewareArgs($args, $resolver);
                    $key = $method === 'withoutmiddleware' ? 'without_middleware' : 'middleware';
                    if ($created !== []) {
                        $this->applyToCreated($created, function (array &$r) use ($value, $key) {
                            ValueResolver::isDynamic($value) ? $this->downgrade($r, 'middleware is not a fixed value') : $r[$key] = [...$r[$key] ?? [], ...RouteContext::strings($value)];
                        });
                    } else {
                        if (ValueResolver::isDynamic($value)) {
                            $this->finding('warning', 'dynamic_middleware', 'Some middleware here is not a fixed value, so the middleware (and auth) shown for these routes may be incomplete.', $file->path, $call->getStartLine(), $this->print($expr));
                        }
                        // RouteRegistrar and pending resources replace the previous value.
                        $resource !== null ? $resource[$key] = RouteContext::strings($value) : $attributes[$key] = RouteContext::strings($value);
                    }
                    break;

                case 'group':
                    $this->group($args, $context, $attributes, $file, $depth, $nesting, $call);

                    return;

                case 'get': case 'post': case 'put': case 'patch': case 'delete': case 'options': case 'any':
                    $created = array_merge($created, $this->register(self::VERBS[$method], $args[0] ?? null, $args[1] ?? null, ...$this->verbContext($context, $attributes), file: $file, expr: $expr));
                    break;

                case 'match':
                    $verbs = $first();
                    $verbs = is_string($verbs) ? [$verbs] : $verbs;
                    if (! is_array($verbs) || ValueResolver::isDynamic($verbs)) {
                        $this->finding('warning', 'dynamic_methods', 'Route::match() uses HTTP methods that are not fixed values.', $file->path, $call->getStartLine(), $this->print($expr));

                        return;
                    }
                    if (array_filter($verbs, fn ($v) => ! is_string($v)) !== []) {
                        $this->finding('warning', 'dynamic_methods', 'Route::match() uses HTTP methods that are not plain strings.', $file->path, $call->getStartLine(), $this->print($expr));

                        return;
                    }
                    $methods = array_values(array_unique(array_map(fn (string $v) => strtoupper($v), $verbs)));
                    in_array('GET', $methods, true) && ! in_array('HEAD', $methods, true) && $methods[] = 'HEAD';
                    $created = array_merge($created, $this->register($methods, $args[1] ?? null, $args[2] ?? null, ...$this->verbContext($context, $attributes), file: $file, expr: $expr));
                    break;

                case 'view':
                    $created = array_merge($created, $this->register(['GET', 'HEAD'], $args[0] ?? null, null, ...$this->verbContext($context, $attributes), file: $file, expr: $expr, special: 'view'));
                    break;

                case 'redirect': case 'permanentredirect':
                    // Laravel registers redirects for every verb (Route::any).
                    $created = array_merge($created, $this->register(self::VERBS['any'], $args[0] ?? null, null, ...$this->verbContext($context, $attributes), file: $file, expr: $expr, special: 'redirect'));
                    break;

                case 'fallback':
                    $created = array_merge($created, $this->register(['GET', 'HEAD'], null, $args[0] ?? null, $context->with($attributes), $file, $expr, fallback: true));
                    break;

                case 'resource': case 'apiresource': case 'singleton': case 'apisingleton':
                    $resource = $this->startResource(in_array($method, ['apiresource', 'apisingleton'], true), $first(), $resolver->arg($args, 1), $resolver->arg($args, 2), $context->with($attributes), $file, $expr);
                    $resource !== null && str_contains($method, 'singleton') && $resource['singleton'] = true;
                    if ($resource === null) {
                        return;
                    }
                    break;

                case 'resources': case 'apiresources':
                    $map = $first();
                    if (! is_array($map) || ValueResolver::isDynamic($map)) {
                        $this->finding('warning', 'dynamic_resource', 'Route::'.$call->name->toString().'() uses values that are not fixed.', $file->path, $call->getStartLine(), $this->print($expr));

                        return;
                    }
                    foreach ($map as $name => $controller) {
                        $pending = $this->startResource($method === 'apiresources', $name, $controller, [], $context->with($attributes), $file, $expr);
                        $pending !== null && $this->finishResource($pending);
                    }
                    break;

                case 'only': case 'except':
                    if ($resource !== null) {
                        $value = $resolver->arg($args, 0);
                        $resource[$method] = is_array($value) ? RouteContext::strings($value) : RouteContext::strings(array_map(fn (Node\Arg $a) => $resolver->resolve($a->value), array_filter($args, fn ($a) => $a instanceof Node\Arg)));
                    }
                    break;

                case 'names': case 'parameters':
                    if ($resource !== null) {
                        $value = $first();
                        is_array($value) && ! ValueResolver::isDynamic($value) && $resource[$method] = $value;
                        is_string($value) && $method === 'names' && $resource['names'] = $value; // replaces the base name
                    }
                    break;

                case 'parameter':
                    if ($resource !== null && is_string($first()) && is_string($resolver->arg($args, 1))) {
                        $resource['parameters'][$first()] = $resolver->arg($args, 1);
                    }
                    break;

                case 'shallow':
                    $resource !== null && $resource['shallow'] = $resolver->arg($args, 0) !== false;
                    break;

                case 'creatable': case 'destroyable':
                    $resource !== null && $resource[$method] = true;
                    break;

                case 'middlewarefor': case 'withoutmiddlewarefor': case 'resourceparameters':
                    $this->finding('info', 'unsupported_resource_option', $call->name->toString().'() on a resource is not analyzed; the middleware or parameters shown may differ.', $file->path, $call->getStartLine());
                    break;

                default:
                    // where*, scopeBindings, missing, withTrashed, … don't change method, URI, name or handler.
                    break;
            }
        }

        if ($resource !== null) {
            $this->finishResource($resource);
        }
    }

    private function closureBody(Expr\Closure|Expr\ArrowFunction $closure, RouteContext $context, ParsedFile $file, int $depth, int $nesting): void
    {
        $stmts = $closure instanceof Expr\Closure ? $closure->stmts : [new Stmt\Expression($closure->expr)];
        $this->statements($stmts, $context, $file, $depth, $nesting + 1);
    }

    /**
     * Route::prefix('v1')->get(...) inside a prefixed group: Laravel puts the registrar's prefix
     * before the group's (/v1/api/x), unlike a group, which nests (/api/v1/x).
     *
     * @param  array<string, mixed>  $attributes
     * @return array{context: RouteContext, outerPrefix: string}
     */
    private function verbContext(RouteContext $context, array $attributes): array
    {
        $prefix = is_string($attributes['prefix'] ?? null) ? $attributes['prefix'] : '';
        unset($attributes['prefix']);

        return ['context' => $context->with($attributes), 'outerPrefix' => $prefix];
    }

    /**
     * @param  array<mixed>  $args
     * @param  array<string, mixed>  $attributes
     */
    private function group(array $args, RouteContext $context, array $attributes, ParsedFile $file, int $depth, int $nesting, Expr $call): void
    {
        $resolver = $file->resolver();
        $positional = array_values(array_filter($args, fn ($a) => $a instanceof Node\Arg));

        // Route::group(['prefix' => …, 'middleware' => …], function () {…}) array form
        if (count($positional) >= 2) {
            $array = $resolver->resolve($positional[0]->value);
            if (! is_array($array)) {
                $this->finding('warning', 'dynamic_group_attribute', 'Route group attributes are not fixed values; routes in this group were skipped.', $file->path, $call->getStartLine(), $this->print($call));

                return;
            }
            foreach (['middleware', 'without_middleware'] as $key) {
                if (array_key_exists($key, $array)) {
                    $array[$key] = RouteContext::strings($array[$key]);
                }
            }
            if (ValueResolver::isDynamic($array)) {
                $this->finding('warning', 'dynamic_group_attribute', 'Some route group attributes are not fixed values; they were ignored.', $file->path, $call->getStartLine(), $this->print($call));
                $array = array_filter($array, fn ($v) => ! ValueResolver::isDynamic($v));
            }
            $attributes = [...$attributes, ...$array, 'middleware' => [...$attributes['middleware'] ?? [], ...$array['middleware'] ?? []]];
            $body = $positional[1]->value;
        } else {
            $body = $positional[0]->value ?? null;
        }

        $inner = $context->with($attributes);

        // ->group($registerHostRoutes): a closure kept in a variable
        if ($body instanceof Expr\Variable && is_string($body->name) && isset($this->closures[$body->name])) {
            $body = $this->closures[$body->name];
        }

        if ($body instanceof Expr\Closure || $body instanceof Expr\ArrowFunction) {
            $this->closureBody($body, $inner, $file, $depth, $nesting);

            return;
        }

        // ->group(base_path('routes/api.php')) or ->group(__DIR__.'/x.php')
        $this->includeFile($resolver->resolve($body), $inner, $file, $call, $depth);
    }

    private function includeFile(mixed $path, RouteContext $context, ParsedFile $file, Node $node, int $depth): void
    {
        $normalized = is_string($path) ? SnapshotPaths::normalize($path) : null;

        if ($normalized === null || ! SnapshotPaths::within($normalized, $this->appRoot)) {
            $this->finding('warning', 'unresolved_include', is_string($path)
                ? 'A route file include points outside the application root; it was not followed.'
                : 'A route file is included by a path that is not a fixed value; it was not followed.', $file->path, $node->getStartLine(), $this->print($node));

            return;
        }

        $this->evaluateFile($normalized, $context, $depth + 1);
    }

    /**
     * @param  list<string>  $methods
     * @return list<int> indexes of created routes
     */
    private function register(array $methods, ?Node $uriArg, ?Node $actionArg, RouteContext $context, ParsedFile $file, Expr $expr, ?string $special = null, bool $fallback = false, string $outerPrefix = ''): array
    {
        if (count($this->routes) >= $this->maxRoutes) {
            $this->finding('warning', 'too_many_routes', "More than {$this->maxRoutes} routes; the rest were not recorded.", $file->path);

            return [];
        }

        $uri = $fallback ? '{fallbackPlaceholder}' : ($uriArg instanceof Node\Arg ? $file->resolver()->resolve($uriArg->value) : null);

        if (! is_string($uri)) {
            $this->finding('warning', 'dynamic_uri', 'A route URI is not a fixed value, so this route could not be listed.', $file->path, $expr->getStartLine(), $this->print($expr));

            return [];
        }

        $route = [
            'methods' => $methods,
            'uri' => self::joinUri($outerPrefix, self::joinUri($context->prefix, $uri)),
            'domain' => $context->domain,
            'name' => $context->namePrefix === '' ? null : $context->namePrefix,
            'handler_type' => 'unknown',
            'controller' => null,
            'action' => null,
            'middleware' => $context->middleware,
            'without_middleware' => $context->withoutMiddleware,
            'group' => $context->group,
            'module' => $context->module,
            'source_path' => $file->path,
            'source_line' => $expr->getStartLine(),
            'expression' => $this->print($expr),
            'resolution' => 'resolved',
            'reasons' => [],
        ];

        if ($special !== null) {
            $route['handler_type'] = $special;
        } else {
            $this->resolveAction($route, $actionArg instanceof Node\Arg ? $actionArg->value : null, $context, $file);
        }

        // Decided in RouteDiscoverer::finalize(), once every registration of this definition is known.
        $route['branches'] = $context->branches;
        $route['runtime_domain'] = $context->runtimeDomain;

        if ($context->conditional) {
            $this->downgrade($route, 'registered inside a loop, switch or catch block, so it may not always exist');
        }

        $this->routes[] = $route;

        return [array_key_last($this->routes)];
    }

    /** @param  array<string, mixed>  $route */
    private function resolveAction(array &$route, ?Expr $action, RouteContext $context, ParsedFile $file): void
    {
        if ($action === null) {
            if ($context->controller !== null) {
                $this->downgrade($route, 'no action given');
            } else {
                $this->unresolve($route, 'no handler given');
            }

            return;
        }

        if ($action instanceof Expr\Closure || $action instanceof Expr\ArrowFunction) {
            $route['handler_type'] = 'closure';
            $this->unresolve($route, 'the handler is a closure, so it has no controller to analyze');

            return;
        }

        $value = $file->resolver()->resolve($action);

        // Legacy array form: ['as' => …, 'uses' => …, 'middleware' => …]
        if (is_array($value) && (array_key_exists('uses', $value) || array_key_exists('as', $value))) {
            is_string($value['as'] ?? null) && $route['name'] = ($route['name'] ?? '').$value['as'];
            $route['middleware'] = [...$route['middleware'], ...RouteContext::strings($value['middleware'] ?? [])];
            $value = $value['uses'] ?? null;

            if ($value === null) {
                foreach ($action instanceof Expr\Array_ ? $action->items : [] as $item) {
                    if ($item->key === null && ($item->value instanceof Expr\Closure || $item->value instanceof Expr\ArrowFunction)) {
                        $route['handler_type'] = 'closure';
                        $this->unresolve($route, 'the handler is a closure, so it has no controller to analyze');

                        return;
                    }
                }
            }
        }

        if (is_array($value) && count($value) === 2 && is_string($value[0] ?? null) && is_string($value[1] ?? null)) {
            // Array callables are taken as written: Laravel adds a group namespace only to string actions.
            [$route['controller'], $route['action']] = [ltrim($value[0], '\\'), $value[1]];
            $route['handler_type'] = 'controller';

            return;
        }

        if (is_string($value)) {
            if (str_contains($value, '@')) {
                [$class, $method] = explode('@', $value, 2);
                $route['controller'] = $this->className($class, $context, $file, $route);
                $route['action'] = $method;
                $route['handler_type'] = 'controller';
            } elseif ($context->controller !== null) {
                $route['controller'] = $context->controller;   // Route::controller(X::class)->group(… Route::get('/', 'index'))
                $route['action'] = $value;
                $route['handler_type'] = 'controller';
            } else {
                $route['controller'] = $this->className($value, $context, $file, $route);
                $route['action'] = '__invoke';
                $route['handler_type'] = 'invokable';
            }

            return;
        }

        $this->unresolve($route, 'the handler is not a fixed value');
    }

    /**
     * Resolves a controller name written as a string, using the file's imports and the group namespace.
     *
     * @param  array<string, mixed>  $route
     */
    private function className(string $class, RouteContext $context, ParsedFile $file, array &$route): string
    {
        if (str_starts_with($class, '\\')) {
            return ltrim($class, '\\');
        }

        if ($context->namespace !== null) {
            return str_starts_with($class, $context->namespace.'\\') ? $class : trim($context->namespace.'\\'.$class, '\\');
        }

        $first = explode('\\', $class)[0];
        if (isset($file->imports[strtolower($first)])) {
            return $file->imports[strtolower($first)].substr($class, strlen($first));
        }

        if (! str_contains($class, '\\')) {
            $this->downgrade($route, "controller \"{$class}\" has no namespace, so the exact class is unknown");
        }

        return $class;
    }

    /** @return array<string, mixed>|null */
    private function startResource(bool $api, mixed $name, mixed $controller, mixed $options, RouteContext $context, ParsedFile $file, Expr $expr): ?array
    {
        if (! is_string($name) || ! is_string($controller)) {
            $this->finding('warning', 'dynamic_resource', 'A resource route uses a name or controller that is not a fixed value.', $file->path, $expr->getStartLine(), $this->print($expr));

            return null;
        }

        $options = is_array($options) ? $options : [];

        // Route::resource('admin/photos', …): Laravel wraps it in a group prefixed "admin".
        if (str_contains($name, '/')) {
            $segments = explode('/', trim($name, '/'));
            $name = (string) array_pop($segments);
            $context = $context->with(['prefix' => implode('/', $segments)]);
        }

        return [
            'api' => $api,
            'name' => $name,
            'controller' => str_starts_with($controller, '\\') ? ltrim($controller, '\\') : $controller,
            'context' => $context,
            'file' => $file,
            'expr' => $expr,
            'only' => isset($options['only']) ? RouteContext::strings($options['only']) : null,
            'except' => isset($options['except']) ? RouteContext::strings($options['except']) : null,
            'names' => is_array($options['names'] ?? null) ? $options['names'] : [],
            'parameters' => is_array($options['parameters'] ?? null) ? $options['parameters'] : [],
            'as' => is_string($options['as'] ?? null) ? $options['as'] : null,
            'middleware' => RouteContext::strings($options['middleware'] ?? []),
            'shallow' => ($options['shallow'] ?? false) === true,
            'singleton' => false,
            'creatable' => ($options['creatable'] ?? false) === true,
            'destroyable' => ($options['destroyable'] ?? false) === true,
        ];
    }

    /**
     * Expands a resource like Laravel's ResourceRegistrar: URIs, wildcards, names, methods.
     *
     * @param  array<string, mixed>  $resource
     */
    private function finishResource(array $resource): void
    {
        if ($resource['singleton']) {
            $actions = $resource['api'] ? ['show', 'update'] : ['show', 'edit', 'update'];
            $resource['creatable'] && $actions = [...($resource['api'] ? ['store'] : ['create', 'store']), ...$actions, 'destroy'];
            $resource['destroyable'] && ! in_array('destroy', $actions, true) && $actions[] = 'destroy';
        } else {
            $actions = $resource['api'] ? self::API_RESOURCE_ACTIONS : self::RESOURCE_ACTIONS;
        }
        $resource['only'] !== null && $actions = array_values(array_intersect($actions, $resource['only']));
        $resource['except'] !== null && $actions = array_values(array_diff($actions, $resource['except']));

        $wildcard = fn (string $segment) => str_replace('-', '_', is_string($resource['parameters'][$segment] ?? null) ? $resource['parameters'][$segment] : Str::singular($segment));

        // URI and route-name base for a (possibly nested, possibly shallow) resource name.
        $shape = function (string $name) use ($wildcard): array {
            $segments = explode('.', $name);
            $base = '';
            foreach ($segments as $i => $segment) {
                $base .= ($base === '' ? '' : '/').$segment;
                $i < count($segments) - 1 && $base .= '/{'.$wildcard($segment).'}';
            }

            return [$base, '{'.$wildcard((string) end($segments)).'}'];
        };

        $name = $resource['name'];
        $shallowName = $resource['shallow'] ? (string) last(explode('.', $name)) : $name;
        [$base, $param] = $shape($name);
        [$shallowBase, $shallowParam] = $shape($shallowName);
        $member = ['show', 'edit', 'update', 'destroy'];

        $routeName = function (string $action) use ($resource, $name, $shallowName, $member): string {
            if (is_array($resource['names']) && is_string($resource['names'][$action] ?? null)) {
                return $resource['names'][$action];
            }
            $resourceName = is_string($resource['names']) ? $resource['names'] : (in_array($action, $member, true) ? $shallowName : $name);

            return trim(($resource['as'] !== null ? $resource['as'].'.' : '').$resourceName.'.'.$action, '.');
        };

        if ($resource['singleton']) {
            $shapes = [
                'create' => [['GET', 'HEAD'], "{$base}/create"], 'store' => [['POST'], $base],
                'show' => [['GET', 'HEAD'], $base], 'edit' => [['GET', 'HEAD'], "{$base}/edit"],
                'update' => [['PUT', 'PATCH'], $base], 'destroy' => [['DELETE'], $base],
            ];
        } else {
            $memberBase = $resource['shallow'] ? $shallowBase : $base;
            $memberParam = $resource['shallow'] ? $shallowParam : $param;
            $shapes = [
                'index' => [['GET', 'HEAD'], $base], 'create' => [['GET', 'HEAD'], "{$base}/create"],
                'store' => [['POST'], $base], 'show' => [['GET', 'HEAD'], "{$memberBase}/{$memberParam}"],
                'edit' => [['GET', 'HEAD'], "{$memberBase}/{$memberParam}/edit"], 'update' => [['PUT', 'PATCH'], "{$memberBase}/{$memberParam}"],
                'destroy' => [['DELETE'], "{$memberBase}/{$memberParam}"],
            ];
        }

        $context = $resource['context']->with(['middleware' => $resource['middleware'], 'without_middleware' => $resource['without_middleware'] ?? []]);

        foreach ($actions as $action) {
            if (! isset($shapes[$action])) {
                continue; // e.g. only(['index']) on a singleton
            }
            [$methods, $uri] = $shapes[$action];
            $created = $this->register($methods, new Node\Arg(new Node\Scalar\String_($uri)), null, $context, $resource['file'], $resource['expr'], 'controller');

            foreach ($created as $index) {
                $route = &$this->routes[$index];
                $route['controller'] = $resource['controller'];
                $route['action'] = $action;
                $route['handler_type'] = 'controller';
                $route['name'] = $context->namePrefix.$routeName($action);
                unset($route);
            }
        }
    }

    /**
     * @param  array<mixed>  $args
     */
    private function middlewareArgs(array $args, ValueResolver $resolver): mixed
    {
        $values = [];

        foreach ($args as $arg) {
            if ($arg instanceof Node\Arg) {
                $value = $resolver->resolve($arg->value);
                $values = [...$values, ...(is_array($value) ? $value : [$value])];
            }
        }

        return $values;
    }

    /** @param  list<int>  $indexes */
    private function applyToCreated(array $indexes, Closure $change): void
    {
        foreach ($indexes as $i) {
            $change($this->routes[$i]);
        }
    }

    /** @param  array<string, mixed>  $route */
    private function downgrade(array &$route, string $reason): void
    {
        $route['resolution'] === 'resolved' && $route['resolution'] = 'partial';
        $route['reasons'][] = $reason;
    }

    /** @param  array<string, mixed>  $route */
    private function unresolve(array &$route, string $reason): void
    {
        $route['resolution'] = 'unresolved';
        $route['reasons'][] = $reason;
    }

    private function isRouteFacade(Node\Name $name): bool
    {
        $resolved = $name->getAttribute('resolvedName');
        $full = strtolower(ltrim(($resolved instanceof Node\Name ? $resolved : $name)->toString(), '\\'));

        return in_array($full, ['route', 'illuminate\support\facades\route'], true);
    }

    public static function joinUri(string $prefix, string $uri): string
    {
        $joined = trim(trim($prefix, '/').'/'.trim($uri, '/'), '/');

        return '/'.$joined;
    }

    public function findingsFull(): bool
    {
        return count($this->findings) >= $this->maxFindings;
    }

    private function finding(string $severity, string $code, string $message, ?string $path = null, ?int $line = null, ?string $expression = null): void
    {
        if (count($this->findings) >= $this->maxFindings) {
            return;
        }

        $this->findings[] = compact('severity', 'code', 'message') + [
            'source_path' => $path,
            'source_line' => $line,
            'expression' => $expression,
        ];
    }

    private function print(Node $node): string
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        // The original text as written (imports and formatting intact); pretty-print only as a fallback.
        $text = $start >= 0 && $end >= $start && $this->code !== ''
            ? substr($this->code, $start, min($end - $start + 1, 2000))
            : ($node instanceof Expr ? $this->printer->prettyPrintExpr($node) : $this->printer->prettyPrint([$node]));

        return mb_strimwidth($text, 0, 500, '…');
    }
}
