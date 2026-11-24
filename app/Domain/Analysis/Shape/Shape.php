<?php

namespace App\Domain\Analysis\Shape;

/**
 * Typed value shapes, as plain arrays (they are stored in AnalysisResultV1).
 *
 * JSON kinds:   scalar {types, value?}, object {props, optional}, list {item}, map {value}, union {of}, unknown {why}
 * Internal kinds (never stored; converted by Interpreter::json()): instance, model, builder, collection,
 * paginator, response, closure, exception.
 *
 * A scalar's `value` is present only when the code states it exactly. Anything else is typed.
 */
final class Shape
{
    /** @param array<string> $types */
    public static function scalar(array $types, mixed $value = null, bool $known = false): array
    {
        $types = array_values(array_unique($types));
        sort($types);

        return $known ? ['k' => 'scalar', 'types' => $types, 'value' => $value] : ['k' => 'scalar', 'types' => $types];
    }

    public static function literal(mixed $value): array
    {
        return match (true) {
            $value === null => self::scalar(['null'], null, true),
            is_bool($value) => self::scalar(['boolean'], $value, true),
            is_int($value) => self::scalar(['integer'], $value, true),
            is_float($value) => self::scalar(['number'], $value, true),
            is_string($value) => self::scalar(['string'], $value, true),
            is_array($value) => array_is_list($value) && $value !== []
                ? ['k' => 'list', 'item' => self::union(array_map([self::class, 'literal'], $value)), 'values' => array_map([self::class, 'literal'], $value)]
                : self::object(array_map([self::class, 'literal'], $value)),
            default => self::unknown('value of type '.get_debug_type($value)),
        };
    }

    public static function string(): array
    {
        return self::scalar(['string']);
    }

    public static function int(): array
    {
        return self::scalar(['integer']);
    }

    public static function bool(): array
    {
        return self::scalar(['boolean']);
    }

    public static function null(): array
    {
        return self::literal(null);
    }

    /**
     * @param  array<string|int, array<string, mixed>>  $props
     * @param  array<string>  $optional
     */
    public static function object(array $props, array $optional = []): array
    {
        return ['k' => 'object', 'props' => $props, 'optional' => array_values(array_unique($optional))];
    }

    public static function list(array $item): array
    {
        return ['k' => 'list', 'item' => $item];
    }

    public static function emptyList(): array
    {
        return ['k' => 'list', 'item' => self::never(), 'values' => []];
    }

    /** The element type of an empty list: no value at all. Disappears from unions. */
    public static function never(): array
    {
        return ['k' => 'never'];
    }

    public static function unknown(string $why): array
    {
        return ['k' => 'unknown', 'why' => $why];
    }

    public static function isUnknown(array $s): bool
    {
        return $s['k'] === 'unknown';
    }

    /** Literal value of a scalar the code states, or null + false. @return array{bool, mixed} */
    public static function known(array $s): array
    {
        return $s['k'] === 'scalar' && array_key_exists('value', $s) ? [true, $s['value']] : [false, null];
    }

    /** A PHP value when the whole shape is literal (for config arrays, constants, keys). */
    public static function toPhp(array $s): mixed
    {
        if ($s['k'] === 'scalar' && array_key_exists('value', $s)) {
            return $s['value'];
        }
        if ($s['k'] === 'object' && $s['optional'] === []) {
            $out = [];
            foreach ($s['props'] as $k => $v) {
                $out[$k] = self::toPhp($v);
                if ($out[$k] instanceof Unresolved) {
                    return new Unresolved;
                }
            }

            return $out;
        }
        if ($s['k'] === 'list' && isset($s['values'])) {
            $out = [];
            foreach ($s['values'] as $v) {
                $out[] = self::toPhp($v);
                if (end($out) instanceof Unresolved) {
                    return new Unresolved;
                }
            }

            return $out;
        }

        return new Unresolved;
    }

    /**
     * Union of possible shapes, flattened; scalars merge their types (values dropped unless equal).
     *
     * @param  array<array<string, mixed>>  $shapes
     */
    public static function union(array $shapes): array
    {
        $shapes = array_values($shapes);
        $flat = [];
        foreach ($shapes as $s) {
            foreach ($s['k'] === 'union' ? $s['of'] : [$s] as $one) {
                $one['k'] !== 'never' && $flat[] = $one;
            }
        }
        if ($flat === []) {
            return $shapes === [] ? self::unknown('no value') : self::never();
        }

        $scalars = array_values(array_filter($flat, fn ($s) => $s['k'] === 'scalar'));
        $others = array_values(array_filter($flat, fn ($s) => $s['k'] !== 'scalar'));

        $out = [];
        if ($scalars !== []) {
            $types = array_merge(...array_map(fn ($s) => $s['types'], $scalars));
            $values = array_map(fn ($s) => self::known($s), $scalars);
            $allKnown = array_filter($values, fn ($v) => ! $v[0]) === [];
            $distinct = [];
            foreach ($scalars as $s) {
                $distinct[serialize($s['value'] ?? null)] ??= $s;
            }
            // A few known alternatives stay exact (null|'Foo\Bar', 200|404); otherwise one typed scalar.
            if ($allKnown && count($distinct) <= 8) {
                array_push($out, ...array_values($distinct));
            } else {
                $out[] = self::scalar($types);
            }
        }

        // Objects with the same keys merge prop by prop; different key sets stay alternatives.
        $byKeys = [];
        foreach ($others as $s) {
            $key = $s['k'] === 'object' ? 'object:'.implode(',', array_keys($s['props'])) : $s['k'].':'.md5(json_encode($s) ?: '');
            if (isset($byKeys[$key]) && $s['k'] === 'object') {
                $merged = $byKeys[$key];
                foreach ($s['props'] as $k => $v) {
                    $merged['props'][$k] = self::union([$merged['props'][$k], $v]);
                }
                $merged['optional'] = array_values(array_unique([...$merged['optional'], ...$s['optional']]));
                $byKeys[$key] = $merged;
            } elseif ($s['k'] === 'list' && ($existing = self::firstOf($byKeys, 'list')) !== null) {
                // [] adds no item type: a list that may be empty is just a list.
                $a = $byKeys[$existing];
                $byKeys[$existing] = match (true) {
                    isset($s['values']) && $s['values'] === [] => isset($a['values']) && $a['values'] !== [] ? self::list($a['item']) : $a,
                    isset($a['values']) && $a['values'] === [] => self::list($s['item']),
                    default => self::list(self::union([$a['item'], $s['item']])),
                };
            } else {
                $byKeys[$key] ??= $s;
            }
        }
        array_push($out, ...array_values($byKeys));

        // A known value beside unknowns is still only one of several possibilities.
        return count($out) === 1 ? $out[0] : ['k' => 'union', 'of' => $out];
    }

    /** @param array<string, array<string, mixed>> $byKeys */
    private static function firstOf(array $byKeys, string $kind): ?string
    {
        foreach ($byKeys as $key => $s) {
            if ($s['k'] === $kind) {
                return $key;
            }
        }

        return null;
    }

    public static function nullable(array $s): array
    {
        return self::union([$s, self::null()]);
    }

    /** Does the value, when it is JSON, contain any part whose structure couldn't be read? */
    public static function holes(array $s, string $path = '$'): array
    {
        return match ($s['k']) {
            'unknown' => [$path.': '.$s['why']],
            'object' => array_merge([], ...array_map(fn ($k, $v) => self::holes($v, $path.'.'.$k), array_keys($s['props']), $s['props'])),
            'list' => isset($s['values']) && $s['values'] === [] ? [] : self::holes($s['item'], $path.'[]'),
            'map' => self::holes($s['value'], $path.'.*'),
            'union' => array_merge([], ...array_map(fn ($v) => self::holes($v, $path), $s['of'])),
            default => [], // scalars, never
        };
    }
}
