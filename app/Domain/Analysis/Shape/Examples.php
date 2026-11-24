<?php

namespace App\Domain\Analysis\Shape;

/**
 * Deterministic example JSON from a shape. Every value is tagged by where it comes from:
 *   exact   – the code states this value
 *   example – typed by the code; the value itself is runtime data, so an example is shown
 *   unread  – the code builds this part in a way that couldn't be read; a typed placeholder is shown
 * Same shape in, same example out (no randomness), so docs and tests stay stable.
 */
final class Examples
{
    /** @var list<array{path: string, source: string, types: list<string>, note?: string}> */
    private array $notes = [];

    /** @return array{example: mixed, notes: list<array{path: string, source: string, types: list<string>, note?: string}>} */
    public static function of(array $shape): array
    {
        $self = new self;
        $example = $self->value($shape, '$', null);

        return ['example' => $example, 'notes' => $self->notes];
    }

    private function value(array $s, string $path, ?string $key): mixed
    {
        switch ($s['k']) {
            case 'scalar':
                if (array_key_exists('value', $s)) {
                    $this->note($path, 'exact', $s['types'], $s['note'] ?? null);

                    return $s['value'];
                }
                $this->note($path, 'example', $s['types']);

                return self::scalar($s['types'], $key);

            case 'object':
                $out = [];
                foreach ($s['props'] as $k => $v) {
                    $childPath = $path.'.'.$k;
                    $out[$k] = $this->value($v, $childPath, (string) $k);
                    in_array((string) $k, array_map('strval', $s['optional']), true) && $this->note($childPath, 'optional', []);
                }

                return $out === [] ? new \stdClass : $out;

            case 'list':
                if (isset($s['values'])) {
                    return array_map(fn ($v, $i) => $this->value($v, $path.'['.$i.']', $key), $s['values'], array_keys($s['values']));
                }

                return $s['item']['k'] === 'never' ? [] : [$this->value($s['item'], $path.'[]', $key === null ? null : rtrim($key, 's'))];

            case 'map':
                return ['key' => $this->value($s['value'], $path.'.*', $key)];

            case 'union':
                // Show the most informative alternative; the note lists them all.
                $alts = $s['of'];
                usort($alts, fn ($a, $b) => self::weight($b) <=> self::weight($a));
                $types = array_values(array_unique(array_merge(...array_map(fn ($a) => self::typesOf($a), $alts))));
                $value = $this->value($alts[0], $path, $key);
                $this->note($path, $this->sourceOf($path) ?? 'example', $types);

                return $value;

            case 'never':
                return null;

            default: // unknown
                $this->note($path, 'unread', ['string'], $s['why'] ?? null);

                return self::scalar(['string'], $key);
        }
    }

    private function sourceOf(string $path): ?string
    {
        foreach ($this->notes as $n) {
            if ($n['path'] === $path) {
                return $n['source'];
            }
        }

        return null;
    }

    /** @param list<string> $types */
    private function note(string $path, string $source, array $types, ?string $why = null): void
    {
        // Keep one note per path; a union's note replaces its chosen branch's.
        $this->notes = array_values(array_filter($this->notes, fn ($n) => $n['path'] !== $path || $source === 'optional' || $n['source'] === 'optional'));
        $note = ['path' => $path, 'source' => $source, 'types' => $types];
        $why !== null && $note['note'] = $why;
        $this->notes[] = $note;
    }

    private static function weight(array $s): int
    {
        return match ($s['k']) {
            'object' => 5, 'list' => 4, 'map' => 3,
            'scalar' => $s['types'] === ['null'] ? 0 : (array_key_exists('value', $s) ? 2 : 2),
            default => 1,
        };
    }

    /** @return list<string> */
    private static function typesOf(array $s): array
    {
        return match ($s['k']) {
            'scalar' => $s['types'], 'object', 'map' => ['object'], 'list' => ['array'], 'union' => array_merge(...array_map([self::class, 'typesOf'], $s['of'])),
            default => ['string'],
        };
    }

    /**
     * An example value for a type, chosen by the field name. Deterministic.
     *
     * @param  list<string>  $types
     */
    public static function scalar(array $types, ?string $key): mixed
    {
        $types = array_values(array_diff($types, ['null'])) ?: ['null'];
        $k = strtolower((string) $key);

        return match ($types[0]) {
            'null' => null,
            'boolean' => true,
            'integer' => match (true) {
                str_contains($k, 'status') || str_starts_with($k, 'is_') => 1,
                str_contains($k, 'year') => 2026,
                default => 1,
            },
            'number' => match (true) {
                str_contains($k, 'lat') => 23.8103,
                str_contains($k, 'lng') || str_contains($k, 'lon') => 90.4125,
                default => 10.5,
            },
            default => self::string($k),
        };
    }

    private static function string(string $k): string
    {
        return match (true) {
            $k === '' => 'string',
            str_contains($k, 'email') => 'user@example.com',
            str_contains($k, 'phone') || str_contains($k, 'mobile') => '+8801700000000',
            str_ends_with($k, '_at') => '2026-01-01T00:00:00.000000Z',
            str_contains($k, 'date') => '2026-01-01',
            str_contains($k, 'time') => '12:00:00',
            str_contains($k, 'url') || str_contains($k, 'link') || str_contains($k, 'website') => 'https://example.com',
            str_contains($k, 'image') || str_contains($k, 'logo') || str_contains($k, 'icon') || str_contains($k, 'photo') || str_contains($k, 'avatar') || str_contains($k, 'thumbnail') => 'https://example.com/image.png',
            str_contains($k, 'token') => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.example',
            str_contains($k, 'password') => 'secret123',
            str_contains($k, 'uuid') => '9b2f6c1e-1d2a-4c3b-8e4f-5a6b7c8d9e0f',
            str_contains($k, 'slug') => 'example-slug',
            str_contains($k, 'price') || str_contains($k, 'amount') || str_contains($k, 'total') || str_contains($k, 'cost') || str_contains($k, 'balance') => '10.50',
            str_contains($k, 'lat') => '23.8103',
            str_contains($k, 'lng') || str_contains($k, 'lon') => '90.4125',
            str_contains($k, 'color') => '#000000',
            str_contains($k, 'code') => 'ABC123',
            str_contains($k, 'name') => 'Example name',
            str_contains($k, 'title') => 'Example title',
            str_contains($k, 'message') || str_contains($k, 'description') || str_contains($k, 'note') => 'Example text',
            str_contains($k, 'address') => 'House 1, Road 1, Dhaka',
            str_contains($k, 'type') || str_contains($k, 'status') => 'string',
            default => 'string',
        };
    }
}
