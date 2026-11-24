<?php

namespace App\Domain\Discovery;

/** Everything a route inherits from the groups around it. Immutable: each group derives a new one. */
final readonly class RouteContext
{
    /**
     * @param  list<string>  $middleware
     * @param  list<string>  $withoutMiddleware
     */
    public function __construct(
        public string $group = 'other',      // api | web | module:Name | other
        public string $prefix = '',
        public string $namePrefix = '',
        public array $middleware = [],
        public array $withoutMiddleware = [],
        public ?string $namespace = null,
        public ?string $controller = null,
        public ?string $domain = null,
        public bool $conditional = false,
        public ?string $module = null,
        public bool $runtimeDomain = false,
        /** @var list<string> "ifId:branch:branchCount:else|noelse" for each enclosing if/elseif/else branch */
        public array $branches = [],
    ) {}

    /** @param  array<string, mixed>  $attributes  static values only */
    public function with(array $attributes): self
    {
        $prefix = $this->prefix;
        if (isset($attributes['prefix']) && is_string($attributes['prefix'])) {
            $prefix = trim(trim($prefix, '/').'/'.trim($attributes['prefix'], '/'), '/');
        }

        $namespace = $this->namespace;
        if (isset($attributes['namespace']) && is_string($attributes['namespace']) && $attributes['namespace'] !== '') {
            $ns = $attributes['namespace'];
            $namespace = str_starts_with($ns, '\\') || $namespace === null ? trim($ns, '\\') : trim($namespace.'\\'.$ns, '\\');
        }

        return new self(
            group: $this->group,
            prefix: $prefix,
            namePrefix: $this->namePrefix.(is_string($attributes['as'] ?? null) ? $attributes['as'] : ''),
            middleware: [...$this->middleware, ...self::strings($attributes['middleware'] ?? [])],
            withoutMiddleware: [...$this->withoutMiddleware, ...self::strings($attributes['without_middleware'] ?? [])],
            namespace: $namespace,
            controller: is_string($attributes['controller'] ?? null) ? $attributes['controller'] : $this->controller,
            domain: is_string($attributes['domain'] ?? null) ? $attributes['domain'] : $this->domain,
            conditional: $this->conditional || ($attributes['conditional'] ?? false) === true,
            module: $this->module,
            runtimeDomain: $this->runtimeDomain || ($attributes['runtime_domain'] ?? false) === true,
            branches: is_string($attributes['branch'] ?? null) ? [...$this->branches, $attributes['branch']] : $this->branches,
        );
    }

    /** @return list<string> */
    public static function strings(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_values(array_filter($values, fn ($v) => is_string($v) && $v !== ''));
    }
}
