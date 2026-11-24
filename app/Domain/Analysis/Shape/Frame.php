<?php

namespace App\Domain\Analysis\Shape;

use App\Domain\Analysis\ClassInfo;

/** One function body being interpreted: its variables, $this, and the class it runs in. */
final class Frame
{
    /**
     * @param  array<string, array<string, mixed>>  $vars
     * @param  array<string, mixed>|null  $self  shape of $this (instance / model)
     */
    public function __construct(
        public ClassInfo $class,
        public array $vars = [],
        public ?array $self = null,
        public int $depth = 0,
        /** name of the middleware's $next variable and what calling it yields */
        public ?string $next = null,
        public ?array $nextValue = null,
        /** 'break' / 'continue' while leaving a loop body */
        public ?string $jump = null,
    ) {}

    public static function merge(self $a, self $b): self
    {
        $out = clone $a;
        $out->jump = $a->jump === $b->jump ? $a->jump : null;
        foreach ($b->vars as $name => $shape) {
            $out->vars[$name] = isset($a->vars[$name]) ? Shape::union([$a->vars[$name], $shape]) : $shape;
        }
        if ($a->self !== null && $b->self !== null && ($a->self['props'] ?? null) !== ($b->self['props'] ?? null)) {
            $props = $a->self['props'] ?? [];
            foreach ($b->self['props'] ?? [] as $k => $v) {
                $props[$k] = isset($props[$k]) ? Shape::union([$props[$k], $v]) : $v;
            }
            $out->self['props'] = $props;
        }

        return $out;
    }
}
