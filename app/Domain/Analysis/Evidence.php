<?php

namespace App\Domain\Analysis;

use PhpParser\Node;

/** Collects evidence entries for one analysis; facts reference them by id ("ev-3"). */
final class Evidence
{
    /** @var array<string, array{id: string, path: string, sha256: string|null, symbol: string|null, lines: array{int, int}, kind: string}> */
    private array $items = [];

    public function add(string $path, ?string $sha256, ?string $symbol, Node $node, string $kind): string
    {
        return $this->lines($path, $sha256, $symbol, $node->getStartLine(), $node->getEndLine(), $kind);
    }

    public function lines(string $path, ?string $sha256, ?string $symbol, int $start, int $end, string $kind): string
    {
        $key = $path.':'.$start.':'.$end.':'.$kind;

        if (! isset($this->items[$key])) {
            $this->items[$key] = [
                'id' => 'ev-'.(count($this->items) + 1),
                'path' => $path,
                'sha256' => $sha256,
                'symbol' => $symbol,
                'lines' => [max(1, $start), max(1, $start, $end)],
                'kind' => $kind,
            ];
        }

        return $this->items[$key]['id'];
    }

    /** @return list<array{id: string, path: string, sha256: string|null, symbol: string|null, lines: array{int, int}, kind: string}> */
    public function all(): array
    {
        return array_values($this->items);
    }
}
