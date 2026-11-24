<?php

namespace App\Domain\Analysis;

use App\Domain\Discovery\Dynamic;
use App\Domain\Discovery\ParsedFile;
use App\Domain\Discovery\SnapshotPaths;
use App\Domain\Discovery\ValueResolver;
use App\Domain\Sources\SnapshotReader;
use App\Models\SourceFile;
use App\Models\SourceSnapshot;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * The app's middleware configuration, read statically the way Laravel builds it: global
 * middleware, groups and aliases from bootstrap/app.php (withMiddleware), app/Http/Kernel.php
 * (Laravel ≤ 10), module service providers (aliasMiddleware / middlewareGroup), on top of
 * Laravel's defaults. Anything changed by code that can't be read is marked unknown, never guessed.
 */
final class MiddlewareMap
{
    private const MAX_PROVIDER_FILES = 200;

    /** Laravel 11+ defaults (Illuminate\Foundation\Configuration\Middleware). */
    private const DEFAULT_ALIASES = [
        'auth' => 'Illuminate\Auth\Middleware\Authenticate',
        'auth.basic' => 'Illuminate\Auth\Middleware\AuthenticateWithBasicAuth',
        'auth.session' => 'Illuminate\Session\Middleware\AuthenticateSession',
        'cache.headers' => 'Illuminate\Http\Middleware\SetCacheHeaders',
        'can' => 'Illuminate\Auth\Middleware\Authorize',
        'guest' => 'Illuminate\Auth\Middleware\RedirectIfAuthenticated',
        'password.confirm' => 'Illuminate\Auth\Middleware\RequirePassword',
        'precognitive' => 'Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests',
        'signed' => 'Illuminate\Routing\Middleware\ValidateSignature',
        'throttle' => 'Illuminate\Routing\Middleware\ThrottleRequests',
        'verified' => 'Illuminate\Auth\Middleware\EnsureEmailIsVerified',
    ];

    private const DEFAULT_GROUPS = [
        'web' => [
            'Illuminate\Cookie\Middleware\EncryptCookies',
            'Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse',
            'Illuminate\Session\Middleware\StartSession',
            'Illuminate\View\Middleware\ShareErrorsFromSession',
            'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
            'Illuminate\Routing\Middleware\SubstituteBindings',
        ],
        'api' => ['Illuminate\Routing\Middleware\SubstituteBindings'],
    ];

    private const DEFAULT_GLOBAL = [
        'Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks',
        'Illuminate\Http\Middleware\TrustProxies',
        'Illuminate\Http\Middleware\HandleCors',
        'Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance',
        'Illuminate\Http\Middleware\ValidatePostSize',
        'Illuminate\Foundation\Http\Middleware\TrimStrings',
        'Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull',
    ];

    /** @var array<string, string> alias => class */
    private array $aliases = self::DEFAULT_ALIASES;

    /** @var array<string, list<string>> group => entries (aliases, classes or other groups) */
    private array $groups = self::DEFAULT_GROUPS;

    /** @var list<string> */
    private array $global = self::DEFAULT_GLOBAL;

    /** @var array<string, string> what could not be read: "group:api" | "global" | "aliases" => reason */
    private array $unknown = [];

    /** @var array<string, string> key ("alias:x", "group:x", "global") => evidence id */
    private array $evidenceOf = [];

    /** @var array<string, bool> */
    private array $providersScanned = [];

    public function __construct(
        private readonly SnapshotReader $reader,
        private readonly SourceSnapshot $snapshot,
        private readonly string $root,
        private readonly Evidence $evidence,
    ) {
        $this->kernel();
        $this->bootstrap();
    }

    /**
     * The full ordered stack for a route: global middleware, then the route's entries with
     * groups expanded and aliases resolved, minus withoutMiddleware (matched by resolved class,
     * as Laravel's router does).
     *
     * @param  list<string>  $route
     * @param  list<string>  $without
     * @return list<array{entry: string, class: string|null, params: list<string>, from: string, problem: string|null, evidence: list<string>}>
     */
    public function stack(array $route, array $without): array
    {
        $entries = [];
        foreach ($this->global as $class) {
            $entries[] = $this->entry($class, 'global', $this->ev('global'));
        }
        if (isset($this->unknown['global'])) {
            $entries[] = ['entry' => 'global middleware', 'class' => null, 'params' => [], 'from' => 'global', 'problem' => $this->unknown['global'], 'evidence' => $this->ev('global')];
        }

        foreach ($route as $name) {
            array_push($entries, ...$this->expand($name, 'route', 0));
        }

        $excluded = [];
        foreach ($without as $name) {
            foreach ($this->expand($name, 'route', 0) as $e) {
                $e['class'] !== null && $excluded[strtolower($e['class'])] = true;
                $excluded['entry:'.strtolower($e['entry'])] = true;
            }
        }

        $out = [];
        $seen = [];
        foreach ($entries as $e) {
            if (isset($excluded['entry:'.strtolower($e['entry'])]) || ($e['class'] !== null && isset($excluded[strtolower($e['class'])]))) {
                continue;
            }
            $key = strtolower(($e['class'] ?? $e['entry']).':'.implode(',', $e['params']));
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $e; // Laravel de-duplicates the gathered middleware the same way
            }
        }

        return $out;
    }

    /**
     * Controller middleware entries (aliases, groups or classes) resolved the same way.
     *
     * @return list<array{entry: string, class: string|null, params: list<string>, from: string, problem: string|null, evidence: list<string>}>
     */
    public function resolve(string $name, string $from): array
    {
        return $this->expand($name, $from, 0);
    }

    /**
     * @return list<array{entry: string, class: string|null, params: list<string>, from: string, problem: string|null, evidence: list<string>}>
     */
    private function expand(string $name, string $from, int $depth): array
    {
        [$base, $params] = self::split($name);

        if (isset($this->groups[$base]) && $params === []) {
            if ($depth > 4) {
                return [['entry' => $name, 'class' => null, 'params' => [], 'from' => $from, 'problem' => 'middleware groups nest too deeply', 'evidence' => []]];
            }
            $out = [];
            foreach ($this->groups[$base] as $member) {
                array_push($out, ...$this->expand($member, "group:{$base}", $depth + 1));
            }
            if (isset($this->unknown["group:{$base}"])) {
                $out[] = ['entry' => "group {$base}", 'class' => null, 'params' => [], 'from' => "group:{$base}", 'problem' => $this->unknown["group:{$base}"], 'evidence' => $this->ev("group:{$base}")];
            }

            return $out;
        }

        if (str_contains($base, '\\')) {
            return [$this->entry($name, $from, [])];
        }

        if (! isset($this->aliases[$base])) {
            $this->scanProviders();
        }
        if (isset($this->aliases[$base])) {
            return [$this->entry($this->aliases[$base].($params === [] ? '' : ':'.implode(',', $params)), $from, $this->ev("alias:{$base}"), $name)];
        }
        if (isset($this->groups[$base])) {
            return $this->expand($name, $from, $depth); // a group a provider registered
        }

        return [['entry' => $name, 'class' => null, 'params' => $params, 'from' => $from, 'problem' => isset($this->unknown['aliases'])
            ? "alias \"{$base}\" is not registered in code that could be read ({$this->unknown['aliases']})"
            : "alias \"{$base}\" is not registered anywhere in the snapshot", 'evidence' => []]];
    }

    /**
     * @param  list<string>  $evidence
     * @return array{entry: string, class: string|null, params: list<string>, from: string, problem: string|null, evidence: list<string>}
     */
    private function entry(string $classWithParams, string $from, array $evidence, ?string $display = null): array
    {
        [$class, $params] = self::split($classWithParams);

        return ['entry' => $display ?? $classWithParams, 'class' => ltrim($class, '\\'), 'params' => $params, 'from' => $from, 'problem' => null, 'evidence' => $evidence];
    }

    /** @return array{string, list<string>} "throttle:60,1" → ["throttle", ["60", "1"]] */
    private static function split(string $name): array
    {
        $parts = explode(':', $name, 2);

        return [$parts[0], isset($parts[1]) && $parts[1] !== '' ? explode(',', $parts[1]) : []];
    }

    /** @return list<string> */
    private function ev(string $key): array
    {
        return isset($this->evidenceOf[$key]) ? [$this->evidenceOf[$key]] : [];
    }

    private function note(string $key, string $path, ?string $sha, Node $node): void
    {
        $this->evidenceOf[$key] ??= $this->evidence->add($path, $sha, null, $node, 'middleware_config');
    }

    /** Laravel ≤ 10: properties of App\Http\Kernel replace the defaults. */
    private function kernel(): void
    {
        $path = SnapshotPaths::join($this->root, 'app/Http/Kernel.php');
        if (($parsed = $this->parse($path)) === null) {
            return;
        }
        [$file, $sha] = $parsed;
        $resolver = $file->resolver();

        foreach ((new NodeFinder)->findInstanceOf($file->stmts, Stmt\Property::class) as $property) {
            foreach ($property->props as $prop) {
                $name = $prop->name->toString();
                if (! in_array($name, ['middleware', 'middlewareGroups', 'middlewareAliases', 'routeMiddleware'], true) || $prop->default === null) {
                    continue;
                }
                $value = $resolver->resolve($prop->default);
                $dynamic = ValueResolver::isDynamic($value) || ! is_array($value);

                if ($name === 'middleware') {
                    $dynamic ? $this->unknown['global'] = 'Kernel::$middleware is not a fixed list' : $this->global = self::strings($value);
                    $this->note('global', $path, $sha, $prop);
                } elseif ($name === 'middlewareGroups') {
                    $this->groups = [];
                    foreach (is_array($value) ? $value : [] as $group => $members) {
                        is_array($members) && ! ValueResolver::isDynamic($members)
                            ? $this->groups[(string) $group] = self::strings($members)
                            : $this->unknown['group:'.$group] = 'Kernel group is not a fixed list';
                        $this->note('group:'.$group, $path, $sha, $prop);
                    }
                    $dynamic && $this->unknown['group:*'] = 'Kernel::$middlewareGroups is not a fixed list';
                } else {
                    $this->aliases = [];
                    foreach (is_array($value) ? $value : [] as $alias => $class) {
                        is_string($class) && $this->aliases[(string) $alias] = ltrim($class, '\\');
                        $this->note('alias:'.$alias, $path, $sha, $prop);
                    }
                    $dynamic && $this->unknown['aliases'] = 'Kernel aliases are not a fixed list';
                }
            }
        }
    }

    /** Laravel 11+: ->withMiddleware(function (Middleware $middleware) { ... }) in bootstrap/app.php. */
    private function bootstrap(): void
    {
        $path = SnapshotPaths::join($this->root, 'bootstrap/app.php');
        if (($parsed = $this->parse($path)) === null) {
            return;
        }
        [$file, $sha] = $parsed;
        $resolver = $file->resolver();
        $finder = new NodeFinder;

        $with = $finder->findFirst($file->stmts, fn (Node $n) => $n instanceof Expr\MethodCall && $n->name instanceof Node\Identifier && strtolower($n->name->toString()) === 'withmiddleware');
        if (! $with instanceof Expr\MethodCall) {
            return;
        }
        $closure = $with->args[0] ?? null;
        $closure = $closure instanceof Node\Arg ? $closure->value : null;
        if (! $closure instanceof Expr\Closure && ! $closure instanceof Expr\ArrowFunction) {
            $this->unknown['global'] = $this->unknown['aliases'] = 'withMiddleware() is not given an inline function';

            return;
        }
        $param = $closure->params[0]->var ?? null;
        $var = $param instanceof Expr\Variable && is_string($param->name) ? $param->name : 'middleware';

        $calls = $finder->find($closure instanceof Expr\Closure ? $closure->stmts : [$closure->expr], fn (Node $n) => $n instanceof Expr\MethodCall
            && $n->name instanceof Node\Identifier && $this->rootIs($n->var, $var));
        // Outermost first is wrong for chained calls ($m->alias()->group()); source order is what Laravel runs.
        usort($calls, fn ($a, $b) => $a->getStartFilePos() <=> $b->getStartFilePos());

        foreach ($calls as $call) {
            /** @var Expr\MethodCall $call */
            $method = $call->name instanceof Node\Identifier ? strtolower($call->name->toString()) : '';
            $arg = fn (int $i, ?string $named = null) => $resolver->arg($call->args, $i, $named);
            $list = fn (mixed $v) => ValueResolver::isDynamic($v) ? null : self::strings(is_array($v) ? $v : [$v]);

            switch ($method) {
                case 'use':
                    ($l = $list($arg(0))) === null ? $this->unknown['global'] = 'the global middleware list is not a fixed value' : $this->global = $l;
                    $this->note('global', $path, $sha, $call);
                    break;
                case 'append': case 'prepend':
                    ($l = $list($arg(0))) === null ? $this->unknown['global'] = "{$method}() is given a value that isn't fixed" : $this->global = $method === 'append' ? [...$this->global, ...$l] : [...$l, ...$this->global];
                    $this->note('global', $path, $sha, $call);
                    break;
                case 'group':
                    $group = $arg(0);
                    if (! is_string($group)) {
                        $this->unknown['group:*'] = 'a middleware group name is not a fixed value';
                        break;
                    }
                    ($l = $list($arg(1))) === null ? $this->unknown["group:{$group}"] = 'its members are not a fixed list' : $this->groups[$group] = $l;
                    $this->note("group:{$group}", $path, $sha, $call);
                    break;
                case 'appendtogroup': case 'prependtogroup': case 'removefromgroup':
                    $group = $arg(0);
                    $l = $list($arg(1));
                    if (! is_string($group) || $l === null) {
                        $this->unknown[is_string($group) ? "group:{$group}" : 'group:*'] = "{$method}() is given values that aren't fixed";
                        break;
                    }
                    $current = $this->groups[$group] ?? [];
                    $this->groups[$group] = match ($method) {
                        'appendtogroup' => [...$current, ...$l],
                        'prependtogroup' => [...$l, ...$current],
                        default => array_values(array_diff($current, $l)),
                    };
                    $this->note("group:{$group}", $path, $sha, $call);
                    break;
                case 'web': case 'api':
                    $current = $this->groups[$method] ?? [];
                    foreach (['remove' => 2, 'replace' => 3] as $named => $pos) {
                        $value = $arg($pos, $named);
                        if (! ($value instanceof Dynamic && $value->why === 'missing argument')) {
                            $this->unknown["group:{$method}"] = "{$method}({$named}: …) changes the group in a way that isn't read";
                        }
                    }
                    foreach (['append' => 0, 'prepend' => 1] as $named => $pos) {
                        $value = $arg($pos, $named);
                        if ($value instanceof Dynamic && $value->why === 'missing argument') {
                            continue;
                        }
                        ($l = $list($value)) === null
                            ? $this->unknown["group:{$method}"] = "{$method}({$named}: …) is not a fixed list"
                            : $current = $named === 'append' ? [...$current, ...$l] : [...$l, ...$current];
                    }
                    $this->groups[$method] = $current;
                    $this->note("group:{$method}", $path, $sha, $call);
                    break;
                case 'alias':
                    $value = $arg(0);
                    if (! is_array($value)) {
                        $this->unknown['aliases'] = 'alias() is given a value that isn\'t fixed';
                        break;
                    }
                    foreach ($value as $alias => $class) {
                        if (is_string($class)) {
                            $this->aliases[(string) $alias] = ltrim($class, '\\');
                            $this->note('alias:'.$alias, $path, $sha, $call);
                        } else {
                            $this->unknown['aliases'] = "the class for alias \"{$alias}\" isn't a fixed value";
                        }
                    }
                    break;
                case 'statefulapi':
                    $this->groups['api'] = ['Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful', ...($this->groups['api'] ?? [])];
                    break;
                case 'throttleapi': case 'throttlewithredis':
                    $limiter = $arg(0);
                    $method === 'throttleapi' && $this->groups['api'] = [...($this->groups['api'] ?? []), 'throttle:'.(is_string($limiter) ? $limiter : 'api')];
                    break;
                case 'authenticatesessions':
                    $this->groups['web'] = [...($this->groups['web'] ?? []), 'Illuminate\Session\Middleware\AuthenticateSession'];
                    break;
                case 'remove': case 'replace':
                    $this->unknown['global'] = "{$method}() changes global middleware in a way that isn't read";
                    break;
            }
        }
    }

    /** Module and app service providers may register aliases and groups on the router. */
    private function scanProviders(): void
    {
        if ($this->providersScanned !== []) {
            return;
        }
        $this->providersScanned = ['done' => true];

        $paths = [];
        foreach (['app/Providers', 'Modules'] as $folder) {
            foreach ($this->reader->paths($this->snapshot, SnapshotPaths::join($this->root, $folder), like: '%Provider.php') as $p) {
                $paths[] = $p;
            }
        }

        foreach (array_slice($paths, 0, self::MAX_PROVIDER_FILES) as $path) {
            $code = $this->reader->read($this->snapshot, $path);
            if ($code === null || (! str_contains($code, 'aliasMiddleware') && ! str_contains($code, 'middlewareGroup') && ! str_contains($code, 'pushMiddlewareToGroup'))) {
                continue;
            }
            if (($parsed = $this->parse($path, $code)) === null) {
                continue;
            }
            [$file, $sha] = $parsed;
            $resolver = $file->resolver();

            foreach ((new NodeFinder)->find($file->stmts, fn (Node $n) => ($n instanceof Expr\MethodCall || $n instanceof Expr\StaticCall) && $n->name instanceof Node\Identifier
                && in_array(strtolower($n->name->toString()), ['aliasmiddleware', 'middlewaregroup', 'pushmiddlewaretogroup', 'prependmiddlewaretogroup'], true)) as $call) {
                /** @var Expr\MethodCall|Expr\StaticCall $call */
                $method = strtolower($call->name instanceof Node\Identifier ? $call->name->toString() : '');
                $name = $resolver->arg($call->args, 0);
                $value = $resolver->arg($call->args, 1);
                if (! is_string($name)) {
                    $this->unknown['aliases'] = "a provider ({$path}) registers middleware under a computed name";

                    continue;
                }
                if ($method === 'aliasmiddleware' && is_string($value)) {
                    $this->aliases[$name] ??= ltrim($value, '\\');
                    $this->note('alias:'.$name, $path, $sha, $call);
                } elseif ($method === 'middlewaregroup' && is_array($value) && ! ValueResolver::isDynamic($value)) {
                    $this->groups[$name] ??= self::strings($value);
                    $this->note('group:'.$name, $path, $sha, $call);
                } elseif (in_array($method, ['pushmiddlewaretogroup', 'prependmiddlewaretogroup'], true) && is_string($value)) {
                    $this->groups[$name] = $method === 'pushmiddlewaretogroup' ? [...($this->groups[$name] ?? []), $value] : [$value, ...($this->groups[$name] ?? [])];
                    $this->note('group:'.$name, $path, $sha, $call);
                } else {
                    $this->unknown[$method === 'aliasmiddleware' ? 'aliases' : "group:{$name}"] = "a provider ({$path}) registers \"{$name}\" with a value that isn't fixed";
                }
            }
        }

        count($paths) > self::MAX_PROVIDER_FILES && $this->unknown['aliases'] ??= 'too many provider files to read them all';
    }

    private function rootIs(Expr $expr, string $var): bool
    {
        while ($expr instanceof Expr\MethodCall) {
            $expr = $expr->var;
        }

        return $expr instanceof Expr\Variable && $expr->name === $var;
    }

    /** @return array{ParsedFile, string|null}|null */
    private function parse(string $path, ?string $code = null): ?array
    {
        $code ??= $this->reader->read($this->snapshot, $path);
        if ($code === null || strlen($code) > 262144) {
            return null;
        }
        $sha = SourceFile::query()->where('source_snapshot_id', $this->snapshot->id)->where('path_hash', hash('sha256', $path))->value('sha256');

        return [ParsedFile::parse($path, mb_scrub($code, 'UTF-8'), $this->root), is_string($sha) ? $sha : null];
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_map(fn ($v) => ltrim((string) $v, '\\'), array_filter($values, 'is_string')));
    }
}
