<?php

namespace App\Domain\Discovery;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;

/**
 * Evaluates the small set of PHP expressions that route files use with literal values:
 * strings, numbers, arrays, Foo::class, concatenation, __DIR__, base_path(), module_path()
 * and $this->property for known properties. Anything else is Dynamic. Nothing is executed.
 */
final class ValueResolver
{
    /** @param  array<string, mixed>  $properties  statically known $this->properties */
    /**
     * @param  array<string, mixed>  $properties  statically known $this->properties
     * @param  array<string, mixed>  $variables  local variables assigned fixed values, e.g. $ns = $this->namespace
     */
    public function __construct(
        private readonly string $fileDir,      // snapshot-relative folder of the file being read
        private readonly string $appRoot,      // snapshot-relative application root ('.' for archive root)
        private readonly array $properties = [],
        private readonly array $variables = [],
    ) {}

    public function resolve(?Expr $expr): mixed
    {
        return match (true) {
            $expr === null => new Dynamic('missing value'),
            $expr instanceof Scalar\String_ => $expr->value,
            $expr instanceof Scalar\Int_ => $expr->value,
            $expr instanceof Scalar\Float_ => $expr->value,
            $expr instanceof Scalar\MagicConst\Dir => $this->fileDir,
            $expr instanceof Expr\ConstFetch => match (strtolower($expr->name->toString())) {
                'true' => true, 'false' => false, 'null' => null,
                default => new Dynamic('constant '.$expr->name->toString()),
            },
            $expr instanceof Expr\ClassConstFetch => $this->classConstant($expr),
            $expr instanceof Expr\BinaryOp\Concat => $this->concat($expr),
            $expr instanceof Expr\Array_ => $this->array($expr),
            $expr instanceof Expr\FuncCall => $this->function($expr),
            $expr instanceof Expr\PropertyFetch => $this->property($expr),
            $expr instanceof Expr\Variable && is_string($expr->name) && array_key_exists($expr->name, $this->variables) => $this->variables[$expr->name],
            default => new Dynamic(class_basename($expr)),
        };
    }

    /** @param  array<Arg|mixed>  $args */
    public function arg(array $args, int $position, ?string $name = null): mixed
    {
        foreach ($args as $i => $arg) {
            if (! $arg instanceof Arg) {
                continue;
            }
            if ($name !== null && $arg->name?->toString() === $name) {
                return $this->resolve($arg->value);
            }
        }

        $positional = array_values(array_filter($args, fn ($a) => $a instanceof Arg && $a->name === null && ! $a->unpack));

        return array_key_exists($position, $positional) ? $this->resolve($positional[$position]->value) : new Dynamic('missing argument');
    }

    public static function isDynamic(mixed $value): bool
    {
        if ($value instanceof Dynamic) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $v) {
                if (self::isDynamic($v)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function classConstant(Expr\ClassConstFetch $expr): mixed
    {
        if (! $expr->class instanceof Name || ! $expr->name instanceof Identifier) {
            return new Dynamic('dynamic class constant');
        }

        if (strtolower($expr->name->toString()) !== 'class') {
            return new Dynamic('class constant '.$expr->name->toString());
        }

        $resolved = $expr->class->getAttribute('resolvedName');

        return ltrim(($resolved instanceof Name ? $resolved : $expr->class)->toString(), '\\');
    }

    private function concat(Expr\BinaryOp\Concat $expr): mixed
    {
        $left = $this->resolve($expr->left);
        $right = $this->resolve($expr->right);

        return is_scalar($left) && is_scalar($right) ? $left.$right : new Dynamic('concatenation with a dynamic part');
    }

    private function array(Expr\Array_ $expr): mixed
    {
        $out = [];

        foreach ($expr->items as $item) {
            if ($item->unpack) {
                return new Dynamic('array spread');
            }

            $value = $this->resolve($item->value);

            if ($item->key === null) {
                $out[] = $value;
            } else {
                $key = $this->resolve($item->key);
                if (! is_string($key) && ! is_int($key)) {
                    return new Dynamic('dynamic array key');
                }
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function function(Expr\FuncCall $expr): mixed
    {
        if (! $expr->name instanceof Name) {
            return new Dynamic('dynamic function call');
        }

        $name = strtolower($expr->name->getLast());

        if ($name === 'base_path') {
            $path = $this->arg($expr->args, 0);

            return is_string($path) ? self::join($this->appRoot, $path) : ($expr->args === [] ? $this->appRoot : new Dynamic('base_path with a dynamic path'));
        }

        if ($name === 'module_path') {
            $module = $this->arg($expr->args, 0);
            $path = $expr->args !== [] && count($expr->args) > 1 ? $this->arg($expr->args, 1) : '';

            return is_string($module) && is_string($path)
                ? self::join(self::join($this->appRoot, 'Modules/'.$module), $path)
                : new Dynamic('module_path with dynamic arguments');
        }

        return new Dynamic("call to {$name}()");
    }

    private function property(Expr\PropertyFetch $expr): mixed
    {
        if ($expr->var instanceof Expr\Variable && $expr->var->name === 'this' && $expr->name instanceof Identifier
            && array_key_exists($expr->name->toString(), $this->properties)) {
            return $this->properties[$expr->name->toString()];
        }

        return new Dynamic('property access');
    }

    /** Joins snapshot-relative paths; '..' segments are kept so the caller's SourcePath check rejects escapes. */
    public static function join(string $base, string $path): string
    {
        $joined = ($base === '.' || $base === '' ? '' : rtrim($base, '/').'/').ltrim($path, '/');

        return $joined === '' ? '.' : $joined;
    }
}
