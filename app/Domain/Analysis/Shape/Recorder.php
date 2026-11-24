<?php

namespace App\Domain\Analysis\Shape;

use App\Domain\Analysis\Evidence;

/**
 * Records the endpoint's workflow graph while the Interpreter follows the action: which methods it
 * calls, which models it reads and writes, what it dispatches, throws and responds with, and every
 * call it could not follow (with the reason). It only observes: it never evaluates code, so the
 * contract the Interpreter builds is the same with or without it.
 *
 * Memoized calls are captured once per memo key and replayed into every caller. An edge recorded
 * inside an undecided branch, loop or catch is conditional; merged edges are unconditional when
 * any path reaches them unconditionally. Only edges reached from the action (and the serialization
 * of what it returns) enter the graph; middleware and exception renderers run unrooted.
 *
 * shortcut: "conditional" is lexical (inside an undecided branch, loop or catch); code after an early
 * return or abort counts as the main path. Upgrade to path-sensitive tracking if guard clauses must count.
 */
final class Recorder
{
    public const VERSION = 'workflow-graph-v1';

    public const KINDS = ['handled_by', 'validates_with', 'calls', 'reads_from', 'writes_to', 'dispatches', 'responds_with', 'throws'];

    public const NODE_TYPES = ['route', 'controller', 'service', 'model', 'job', 'event', 'mail', 'notification', 'resource', 'form_request', 'exception', 'external', 'unresolved'];

    /** Outgoing edges per node; more are dropped and reported. */
    public int $maxFanout = 40;

    public int $maxNodes = 300;

    /** Safety net only (the Interpreter's step budget bounds the work): recording stops after this. */
    public float $maxSeconds = 30.0;

    /** @var array<string, array<string, mixed>> every node seen, by id (only those on a committed edge are output) */
    private array $known = [];

    /** @var array<string, array<string, mixed>> committed edges by from|to|kind */
    private array $edges = [];

    /** @var array<string, int> committed outgoing edge count per node */
    private array $fanout = [];

    /** @var array<string, string> bound code => explanation (recorder-level bounds) */
    private array $bounds = [];

    /** @var array<string, list<array<string, mixed>>> edges recorded inside a memoized call, relative to it */
    private array $captured = [];

    /** @var list<array{key: string|null, symbol: string, branch: int, root: bool, edges: array<string, array<string, mixed>>}> */
    private array $stack = [];

    private int $branch = 0;

    private ?string $rootId = null;

    /** @var array{0: string, 1: string|null, 2: string|null, 3: int, 4: int}|null path, sha256, symbol, start, end */
    private ?array $site = null;

    private float $started;

    public function __construct(private readonly Evidence $evidence)
    {
        $this->started = microtime(true);
    }

    // ───────────────────────────── context ─────────────────────────────

    public function enter(): void
    {
        $this->branch++;
    }

    public function leave(): void
    {
        $this->branch--;
    }

    public function conditional(): bool
    {
        return $this->branch > 0;
    }

    /** @return array{0: string, 1: string|null, 2: string|null, 3: int, 4: int}|null the previous site, for restore() */
    public function at(string $path, ?string $sha256, ?string $symbol, int $start, int $end): ?array
    {
        $previous = $this->site;
        $this->site = [$path, $sha256, $symbol, $start, $end];

        return $previous;
    }

    /** @param array{0: string, 1: string|null, 2: string|null, 3: int, 4: int}|null $site */
    public function restore(?array $site): void
    {
        $this->site = $site;
    }

    /** Start recording a call body. $key: the memo key its edges are kept under (null: discarded, e.g. a constructor). */
    public function begin(?string $key, string $symbol): void
    {
        $this->stack[] = ['key' => $key, 'symbol' => $symbol, 'branch' => $this->branch, 'root' => false, 'edges' => []];
        $this->branch = 0;
    }

    public function end(): void
    {
        $frame = array_pop($this->stack);
        if ($frame === null) {
            return;
        }
        $this->branch = $frame['branch'];
        if ($frame['key'] !== null) {
            $this->captured[$frame['key']] = array_values($frame['edges']);
        }
        if ($frame['root']) {
            foreach ($frame['edges'] as $edge) {
                $this->commit($edge);
            }
        }
    }

    /** Re-add a memoized call's edges where it is called again. */
    public function replay(string $key): void
    {
        foreach ($this->captured[$key] ?? [] as $edge) {
            $edge['conditional'] = $edge['conditional'] || $this->conditional();
            $this->add($edge);
        }
    }

    /** Edges recorded from here on belong to the graph, from $id (the action, or the route). */
    public function root(string $id): void
    {
        $this->rootId ??= $id;
        $this->stack[] = ['key' => null, 'symbol' => $id, 'branch' => $this->branch, 'root' => true, 'edges' => []];
        $this->branch = 0;
    }

    /** Root again at the action: its returned values are being serialized. */
    public function resume(): bool
    {
        if ($this->rootId === null) {
            return false;
        }
        $this->root($this->rootId);

        return true;
    }

    // ───────────────────────────── recording ─────────────────────────────

    /** @param array{type: string, label: string, class?: string|null, method?: string|null} $node */
    public function node(string $id, array $node): void
    {
        $this->known[$id] ??= ['id' => $id, 'type' => $node['type'], 'label' => $node['label'], 'class' => $node['class'] ?? null, 'method' => $node['method'] ?? null];
    }

    /** @param list<array{name: string, presence: string, condition: string, expression: string|null}> $fields */
    public function fields(string $id, array $fields): void
    {
        if (! isset($this->known[$id])) {
            return;
        }
        $new = array_column($fields, null, 'name');
        if (! isset($this->known[$id]['fields'])) {
            $this->known[$id]['fields'] = array_values($new);

            return;
        }
        // Serialized more than once (different branches): conditional anywhere, or missing anywhere, is conditional.
        $old = array_column($this->known[$id]['fields'], null, 'name');
        $merged = [];
        foreach ($old + $new as $name => $f) {
            $merged[] = match (true) {
                ($old[$name]['presence'] ?? null) === 'conditional' => $old[$name],
                ($new[$name]['presence'] ?? null) === 'conditional' => $new[$name],
                isset($old[$name], $new[$name]) => $f,
                default => ['name' => (string) $name, 'presence' => 'conditional', 'condition' => 'present on some code paths only', 'expression' => null],
            };
        }
        $this->known[$id]['fields'] = $merged;
    }

    /**
     * An edge from the current function to $to.
     *
     * @param  array<string, mixed>  $extra  resolution, reason, note, async, expression; fallback: the site to cite when no call site is current
     */
    public function edge(string $to, string $kind, array $extra = []): void
    {
        $from = $this->current();
        if ($from === null) {
            return; // nothing is recording (no root, no call body)
        }
        if (! in_array($kind, ['handled_by', 'validates_with'], true) && microtime(true) - $this->started > $this->maxSeconds) {
            $this->bounds['time'] = "Bound reached: {$this->maxSeconds} seconds of tracing; later calls were not recorded";

            return; // what was recorded before the deadline is kept
        }
        $this->add([
            'from' => $from,
            'to' => $to,
            'kind' => $kind,
            'conditional' => $this->conditional(),
            'resolution' => $extra['resolution'] ?? 'resolved',
            'reason' => $extra['reason'] ?? null,
            'note' => $extra['note'] ?? null,
            'async' => $extra['async'] ?? null,
            'expression' => isset($extra['expression']) ? mb_substr((string) $extra['expression'], 0, 200) : null,
            'sites' => ($site = $this->site ?? $extra['fallback'] ?? null) === null ? [] : [$site],
        ]);
    }

    /** A call that can't be followed statically, shown as a typed, explained edge. */
    public function unresolved(string $reason, string $label, ?string $expression = null, ?string $note = null, string $kind = 'calls'): void
    {
        if ($this->current() === null) {
            return;
        }
        $id = 'unresolved:'.$reason.':'.substr(md5($label.'|'.$expression), 0, 12);
        $this->node($id, ['type' => 'unresolved', 'label' => $label]);
        $this->edge($id, $kind, ['resolution' => 'unresolved', 'reason' => $reason, 'note' => $note, 'expression' => $expression]);
    }

    public function recording(): bool
    {
        return $this->current() !== null;
    }

    private function current(): ?string
    {
        return $this->stack === [] ? null : $this->stack[count($this->stack) - 1]['symbol'];
    }

    /** @param array<string, mixed> $edge */
    private function add(array $edge): void
    {
        if ($this->stack === []) {
            return;
        }
        $top = count($this->stack) - 1;
        $hash = $edge['from']."\n".$edge['to']."\n".$edge['kind'];
        $existing = $this->stack[$top]['edges'][$hash] ?? null;
        $this->stack[$top]['edges'][$hash] = $existing === null ? $edge : self::merge($existing, $edge);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private static function merge(array $a, array $b): array
    {
        $a['conditional'] = $a['conditional'] && $b['conditional']; // unconditional if any path is
        // A followed call beats a recursion back-edge or a bound on another path.
        $a['reason'] === $b['reason'] || $a['reason'] = $a['resolution'] === 'resolved' && $b['resolution'] === 'resolved' ? null : ($a['reason'] ?? $b['reason']);
        $a['async'] ??= $b['async'];
        $a['sites'] = array_slice(array_values(array_unique([...$a['sites'], ...$b['sites']], SORT_REGULAR)), 0, 3);

        return $a;
    }

    /** @param array<string, mixed> $edge */
    private function commit(array $edge): void
    {
        $hash = $edge['from']."\n".$edge['to']."\n".$edge['kind'];
        if (isset($this->edges[$hash])) {
            $this->edges[$hash] = self::merge($this->edges[$hash], $edge);

            return;
        }
        // The route and its FormRequest always make it in, so the graph's root is always one of its nodes.
        $reserved = in_array($edge['kind'], ['handled_by', 'validates_with'], true);
        if (! $reserved && ($this->fanout[$edge['from']] ?? 0) >= $this->maxFanout) {
            $this->bounds['fanout'] = "Bound reached: more than {$this->maxFanout} outgoing relationships from one method; the rest were not recorded";

            return;
        }
        $nodes = [$edge['from'] => true, $edge['to'] => true];
        $new = count(array_diff_key($nodes, $this->nodeIds()));
        if (! $reserved && count($this->nodeIds()) + $new > $this->maxNodes) {
            $this->bounds['nodes'] = "Bound reached: {$this->maxNodes} nodes; later relationships were not recorded";

            return;
        }
        $this->fanout[$edge['from']] = ($this->fanout[$edge['from']] ?? 0) + 1;
        $this->edges[$hash] = $edge;
        $this->ids = null;
    }

    /** @var array<string, true>|null */
    private ?array $ids = null;

    /** @return array<string, true> */
    private function nodeIds(): array
    {
        if ($this->ids === null) {
            $this->ids = [];
            foreach ($this->edges as $e) {
                $this->ids[$e['from']] = true;
                $this->ids[$e['to']] = true;
            }
        }

        return $this->ids;
    }

    // ───────────────────────────── output ─────────────────────────────

    /**
     * The graph for the result: nodes, edges (evidence ids instead of raw sites) and coverage.
     *
     * @param  bool  $stepBudgetHit  the Interpreter stopped early
     * @param  array<string, int|float>  $limits  bounds the Interpreter applies (depth, steps)
     * @return array<string, mixed>
     */
    public function graph(bool $stepBudgetHit, array $limits): array
    {
        $bounds = $this->bounds;
        $stepBudgetHit && $bounds['steps'] = 'Bound reached: the analysis step budget ('.($limits['steps'] ?? 0).' steps); later code was not followed';

        $edges = [];
        foreach ($this->edges as $e) {
            $ids = [];
            foreach ($e['sites'] as [$path, $sha, $symbol, $start, $end]) {
                $ids[] = $this->evidence->lines($path, $sha, $symbol, $start, $end, 'workflow');
            }
            $e['evidence'] = array_values(array_unique($ids));
            unset($e['sites']);
            if (($e['reason'] ?? null) === 'bound_depth') {
                $bounds['depth'] = 'Bound reached: call depth '.($limits['depth'] ?? 0).'; deeper calls were not followed';
            }
            $edges[] = $e;
        }

        $nodes = [];
        foreach (array_keys($this->nodeIds()) as $id) {
            $nodes[] = $this->known[$id] ?? ['id' => $id, 'type' => 'service', 'label' => $id, 'class' => null, 'method' => null];
        }

        $count = fn (callable $fn) => count(array_filter($edges, $fn));
        ksort($bounds);

        return [
            'version' => self::VERSION,
            'root' => $this->rootId,
            'nodes' => $nodes,
            'edges' => $edges,
            'coverage' => [
                'complete' => $bounds === [] && $count(fn ($e) => $e['resolution'] === 'unresolved') === 0,
                'nodes' => count($nodes),
                'edges' => count($edges),
                'followed_calls' => $count(fn ($e) => $e['kind'] === 'calls' && $e['resolution'] === 'resolved' && $e['reason'] === null),
                'unresolved' => $count(fn ($e) => $e['resolution'] === 'unresolved'),
                'conditional' => $count(fn ($e) => $e['conditional']),
                'async' => $count(fn ($e) => $e['async'] === true),
                'bounds' => array_map(fn ($code, $detail) => ['code' => $code, 'detail' => $detail], array_keys($bounds), array_values($bounds)),
                'limits' => $limits + ['fanout' => $this->maxFanout, 'nodes' => $this->maxNodes, 'seconds' => $this->maxSeconds],
            ],
        ];
    }
}
