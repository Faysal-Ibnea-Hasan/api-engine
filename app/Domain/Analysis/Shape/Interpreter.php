<?php

namespace App\Domain\Analysis\Shape;

use App\Domain\Analysis\ClassIndex;
use App\Domain\Analysis\ClassInfo;
use App\Domain\Discovery\ParsedFile;
use App\Domain\Discovery\SnapshotPaths;
use App\Domain\Sources\SnapshotReader;
use App\Models\SourceSnapshot;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/**
 * Abstract interpreter: evaluates the app's PHP over typed shapes instead of values. Nothing is
 * executed, included or autoloaded. Branches whose condition is decidable from the shapes are
 * followed exactly; otherwise every branch is followed and the results are unions. Values that
 * only exist at runtime (database rows, request input) stay typed: never guessed.
 */
final class Interpreter
{
    public const MAX_DEPTH = 13;

    public const MAX_STEPS = 400000;

    private const JSON_RESOURCE = 'Illuminate\Http\Resources\Json\JsonResource';

    private const RESOURCE_COLLECTION = 'Illuminate\Http\Resources\Json\ResourceCollection';

    private const MODEL = 'Illuminate\Database\Eloquent\Model';

    private int $steps = 0;

    /** @var array<string, array{returns: list<array<string, mixed>>, throws: list<array<string, mixed>>}> */
    private array $memo = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $configFiles = [];

    /** @var array<string, array<string, mixed>> */
    private array $lang = [];

    /** @var list<list<array<string, mixed>>> exceptions thrown inside the code being evaluated, innermost try first */
    private array $thrown = [[]];

    /** @var array<string, int> methods currently on the stack (recursion guard) */
    private array $active = [];

    public readonly ModelSchema $schema;

    /** Request input shapes by name, from the endpoint's validation rules. @var array<string, array<string, mixed>> */
    public array $inputs = [];

    public bool $budgetHit = false;

    /** Advanced profile: records the workflow graph while interpreting. Null: nothing is recorded. */
    public ?Recorder $recorder = null;

    /** The endpoint's path without a leading slash ("api/v1/orders/1"): decides $request->is('api/*'). */
    public string $requestPath = '';

    public function __construct(
        private readonly ClassIndex $index,
        private readonly SnapshotReader $reader,
        private readonly SourceSnapshot $snapshot,
        private readonly string $root,
    ) {
        $this->schema = new ModelSchema($reader, $snapshot, $root, $index);
    }

    // ───────────────────────────── entry points ─────────────────────────────

    /**
     * Runs a method and returns what it can return and throw.
     *
     * @param  array<int|string, array<string, mixed>>  $args
     * @param  array<string, mixed>|null  $self
     * @return array{returns: list<array<string, mixed>>, throws: list<array<string, mixed>>}
     */
    public function run(ClassInfo $class, Stmt\ClassMethod|Stmt\Function_|Expr\Closure|Expr\ArrowFunction $fn, array $args, ?array $self = null, int $depth = 0, ?string $next = null, ?array $nextValue = null, array $vars = []): array
    {
        $frame = new Frame($class, $vars, $self, $depth, $next, $nextValue);
        $this->bindParams($fn->params, $args, $frame);
        if ($fn instanceof Expr\ArrowFunction) {
            return ['returns' => [$this->eval($fn->expr, $frame)], 'throws' => []];
        }

        $this->thrown[] = [];
        [$returns] = $this->exec($fn->stmts ?? [], $frame);
        $throws = array_pop($this->thrown);

        return ['returns' => $returns === [] ? [Shape::null()] : $returns, 'throws' => $throws];
    }

    /** An object as the container builds it: constructor run, injected services typed. */
    public function instantiate(string $class): array
    {
        $info = $this->index->find($class);

        return $info === null ? ['k' => 'instance', 'class' => $class, 'props' => []]
            : $this->newObject(new Expr\New_(new Node\Name\FullyQualified($class)), new Frame($info));
    }

    /** The JSON form of a value, as Laravel would serialize it. */
    public function json(array $s, int $depth = 0): array
    {
        if ($depth > 12) {
            return Shape::unknown('nested too deeply');
        }

        return match ($s['k']) {
            'scalar', 'unknown' => $s,
            'object' => Shape::object(array_map(fn ($v) => $this->json($v, $depth + 1), $s['props']), $s['optional']),
            'list' => isset($s['values']) ? ['k' => 'list', 'item' => $this->json($s['item'], $depth + 1), 'values' => array_map(fn ($v) => $this->json($v, $depth + 1), $s['values'])]
                : Shape::list($this->json($s['item'], $depth + 1)),
            'map' => ['k' => 'map', 'value' => $this->json($s['value'], $depth + 1)],
            'union' => Shape::union(array_map(fn ($v) => $this->json($v, $depth + 1), $s['of'])),
            'model' => $this->schema->shape($s['class'], $s['with'] ?? [], $this, $depth),
            'collection' => Shape::list($this->json($s['item'], $depth + 1)),
            'paginator' => self::paginatorJson($this->json($s['item'], $depth + 1)),
            'instance' => $this->instanceJson($s, $depth),
            'response' => $this->json($s['body'], $depth + 1),
            // nested inside an array, a resource collection serializes as a plain list (no data/links/meta)
            'resource_collection' => ($s['custom'] ?? false) ? $this->json(['k' => 'collection', 'item' => $this->itemOf($s['source'])], $depth + 1)
                : Shape::list($this->resourceArray(['k' => 'instance', 'class' => $s['class'], 'props' => ['resource' => $this->itemOf($s['source'])]], $depth + 1)),
            'optional', 'merge' => $this->json($s['value'], $depth + 1),
            'request' => Shape::unknown('the request object'),
            'never' => $s,
            default => Shape::unknown("a {$s['k']} value can't be turned into JSON"),
        };
    }

    /** A model accessor in $appends: getFooAttribute() or foo(): Attribute. */
    public function accessor(ClassInfo $model, string $name, int $depth): ?array
    {
        $studly = Str::studly($name);
        // Laravel 9+: protected function fooBar(): Attribute { return Attribute::make(get: fn ($value) => …); }
        $modern = $this->index->method($model, Str::camel($name));
        if ($modern !== null && $modern[1]->returnType instanceof Node\Name && strtolower($modern[1]->returnType->getLast()) === 'attribute' && $depth < self::MAX_DEPTH) {
            /** @var list<Expr\StaticCall|Expr\New_> $makes */
            $makes = (new NodeFinder)->find($modern[1]->stmts ?? [], fn (Node $n) => ($n instanceof Expr\StaticCall || $n instanceof Expr\New_));
            foreach ($makes as $make) {
                foreach ($make->args as $i => $arg) {
                    if ($arg instanceof Node\Arg && ($arg->name?->toString() === 'get' || ($arg->name === null && $i === 0))
                        && ($arg->value instanceof Expr\Closure || $arg->value instanceof Expr\ArrowFunction)) {
                        $raw = $this->schema->column($model->fqcn, $name) ?? Shape::null();
                        $result = $this->run($modern[0], $arg->value, [$raw, ['k' => 'map', 'value' => Shape::unknown('attributes')]], ['k' => 'model', 'class' => $model->fqcn, 'with' => []], $depth + 1);

                        return $this->json(Shape::union($result['returns']), $depth + 1);
                    }
                }
            }
        }
        $found = $this->index->method($model, "get{$studly}Attribute");
        if ($found !== null && $depth < self::MAX_DEPTH) {
            // The accessor receives the stored column value, when the column exists.
            $raw = $this->schema->column($model->fqcn, $name) ?? Shape::null();

            return $this->json(Shape::union($this->run($found[0], $found[1], [$raw], ['k' => 'model', 'class' => $model->fqcn, 'with' => []], $depth + 1)['returns']), $depth + 1);
        }

        return null;
    }

    /** A relation loaded on a model: hasMany → list, belongsTo/hasOne → object or null. */
    public function relation(ClassInfo $model, string $name, int $depth): ?array
    {
        $related = $this->relationTarget($model, $name);
        if ($related === null) {
            return null;
        }
        [$class, $many] = $related;
        $shape = $this->schema->shape($class, [], $this, $depth + 1);

        return $many ? Shape::list($shape) : Shape::nullable($shape);
    }

    // ───────────────────────────── statements ─────────────────────────────

    /**
     * @param  array<Node>  $stmts
     * @return array{list<array<string, mixed>>, Frame|null} returns, and the frame when control falls through
     */
    private function exec(array $stmts, Frame $frame): array
    {
        $returns = [];
        foreach ($stmts as $stmt) {
            if (++$this->steps > self::MAX_STEPS) {
                $this->budgetHit = true;

                return [$returns, null];
            }

            if ($stmt instanceof Stmt\Return_) {
                $returns[] = $stmt->expr === null ? Shape::null() : $this->eval($stmt->expr, $frame);

                return [$returns, null];
            }
            if ($stmt instanceof Stmt\Break_ || $stmt instanceof Stmt\Continue_) {
                $frame->jump = $stmt instanceof Stmt\Break_ ? 'break' : 'continue';

                return [$returns, $frame];
            }
            if ($stmt instanceof Stmt\Expression) {
                if ($stmt->expr instanceof Expr\Throw_) {
                    $thrown = $this->eval($stmt->expr->expr, $frame);
                    $this->recorder !== null && $this->recordThrow($thrown, $frame, $stmt);
                    $this->throwShape($thrown);

                    return [$returns, null];
                }
                $value = $this->eval($stmt->expr, $frame);
                if ($this->ends($stmt->expr)) {
                    return [$returns, null]; // abort(...), exit
                }
                unset($value);

                continue;
            }
            if ($stmt instanceof Stmt\If_) {
                [$more, $after] = $this->execIf($stmt, $frame);
                array_push($returns, ...$more);
                if ($after === null) {
                    return [$returns, null];
                }
                $frame = $after;
                if ($frame->jump !== null) {
                    return [$returns, $frame]; // break/continue inside the if leaves this body
                }

                continue;
            }
            if ($stmt instanceof Stmt\Foreach_ && ($unrolled = $this->unroll($stmt, $frame)) !== null) {
                [$more, $after] = $unrolled;
                array_push($returns, ...$more);
                if ($after === null) {
                    return [$returns, null];
                }
                $frame = $after;

                continue;
            }
            if ($stmt instanceof Stmt\Foreach_) {
                $source = $this->eval($stmt->expr, $frame);
                $body = clone $frame;
                $this->assignTo($stmt->valueVar, $this->itemOf($source), $body);
                $stmt->keyVar !== null && $this->assignTo($stmt->keyVar, $this->keyOf($source), $body);
                [$more, $after] = $this->branch(fn () => $this->exec($stmt->stmts, $body)); // may run zero times
                array_push($returns, ...$more);
                $after !== null && $after->jump = null;
                $frame = $after === null ? $frame : Frame::merge($frame, $after);

                continue;
            }
            if ($stmt instanceof Stmt\For_ || $stmt instanceof Stmt\While_ || $stmt instanceof Stmt\Do_) {
                foreach ($stmt instanceof Stmt\For_ ? $stmt->init : [] as $init) {
                    $this->eval($init, $frame);
                }
                [$more, $after] = $this->branch(fn () => $this->exec($stmt->stmts, clone $frame), ! $stmt instanceof Stmt\Do_);
                array_push($returns, ...$more);
                $after !== null && $after->jump = null;
                $frame = $after === null ? $frame : Frame::merge($frame, $after);

                continue;
            }
            if ($stmt instanceof Stmt\TryCatch) {
                [$more, $after] = $this->execTry($stmt, $frame);
                array_push($returns, ...$more);
                if ($after === null) {
                    return [$returns, null];
                }
                $frame = $after;

                continue;
            }
            if ($stmt instanceof Stmt\Switch_) {
                $subject = $this->eval($stmt->cond, $frame);
                $falls = [];
                $matched = false;
                $undecided = false;
                foreach ($stmt->cases as $case) {
                    $test = $case->cond === null ? null : $this->truth($this->compare($subject, $this->eval($case->cond, $frame), true));
                    if ($test === false && ! $matched) {
                        continue;
                    }
                    $matched = $matched || $test === true;
                    $conditional = $undecided || $test !== true;
                    $undecided = $undecided || $test === null;
                    [$more, $after] = $this->branch(fn () => $this->exec(array_filter($case->stmts, fn ($s) => ! $s instanceof Stmt\Break_), clone $frame), $conditional);
                    array_push($returns, ...$more);
                    $after !== null && $falls[] = $after;
                    if ($test === true) {
                        break;
                    }
                }
                if ($falls === [] && $returns !== []) {
                    return [$returns, null];
                }
                foreach ($falls as $f) {
                    $frame = Frame::merge($frame, $f);
                }

                continue;
            }
            if ($stmt instanceof Stmt\Unset_) {
                foreach ($stmt->vars as $var) {
                    $var instanceof Expr\Variable && is_string($var->name) && $frame->vars = array_diff_key($frame->vars, [$var->name => true]);
                }
            }
            // echo, static, global, nop, nested declarations: no effect on values
        }

        return [$returns, $frame];
    }

    /**
     * foreach over an array whose elements are all known (a constant list, a literal map): run the
     * body once per element, in order, so the result is exact. null when it can't be unrolled.
     *
     * @return array{list<array<string, mixed>>, Frame|null}|null
     */
    private function unroll(Stmt\Foreach_ $stmt, Frame $frame): ?array
    {
        $source = $this->eval($stmt->expr, $frame);
        $elements = match (true) {
            $source['k'] === 'list' && isset($source['values']) => array_map(null, array_keys($source['values']), $source['values']),
            $source['k'] === 'object' && $source['optional'] === [] => array_map(null, array_keys($source['props']), $source['props']),
            default => null,
        };
        if ($elements === null || count($elements) > 64) {
            return null;
        }

        $returns = [];
        foreach ($elements as [$key, $value]) {
            $frame->jump = null;
            $this->assignTo($stmt->valueVar, $value, $frame);
            $stmt->keyVar !== null && $this->assignTo($stmt->keyVar, Shape::literal($key), $frame);
            [$more, $after] = $this->exec($stmt->stmts, $frame);
            array_push($returns, ...$more);
            if ($after === null) {
                return [$returns, null];
            }
            $frame = $after;
            if ($frame->jump === 'break') {
                break;
            }
        }
        $frame->jump = null;

        return [$returns, $frame];
    }

    /** @return array{list<array<string, mixed>>, Frame|null} */
    private function execIf(Stmt\If_ $stmt, Frame $frame): array
    {
        $returns = [];
        $falls = [];
        $branches = [[$stmt->cond, $stmt->stmts]];
        foreach ($stmt->elseifs as $elseif) {
            $branches[] = [$elseif->cond, $elseif->stmts];
        }
        $branches[] = [null, $stmt->else->stmts ?? []];

        $skipped = clone $frame; // the path where every condition so far was false
        $undecided = false; // an earlier condition could go either way: this branch may not be reached
        foreach ($branches as [$cond, $body]) {
            $truth = $cond === null ? true : $this->truth($this->branch(fn () => $this->eval($cond, $skipped), $undecided));
            if ($truth === false) {
                continue; // provably not taken
            }
            $branchFrame = clone $skipped;
            $cond !== null && $this->narrow($cond, $branchFrame);
            $cond !== null && $this->narrowFalse($cond, $skipped);
            $conditional = $undecided || $truth === null;
            $undecided = $conditional;
            [$more, $after] = $this->branch(fn () => $this->exec($body, $branchFrame), $conditional);
            array_push($returns, ...$more);
            $after !== null && $falls[] = $after;
            if ($truth === true) {
                break; // provably taken: later branches can't run
            }
        }

        if ($falls === []) {
            return [$returns, null];
        }
        $merged = array_shift($falls);
        foreach ($falls as $f) {
            $merged = Frame::merge($merged, $f);
        }

        return [$returns, $merged];
    }

    /** @return array{list<array<string, mixed>>, Frame|null} */
    private function execTry(Stmt\TryCatch $stmt, Frame $frame): array
    {
        $this->thrown[] = [];
        [$returns, $after] = $this->exec($stmt->stmts, clone $frame);
        $thrown = array_pop($this->thrown);
        $falls = $after === null ? [] : [$after];

        foreach ($thrown as $exception) {
            $caught = false;
            foreach ($stmt->catches as $catch) {
                if ($this->catches($catch, $exception)) {
                    $catchFrame = clone $frame;
                    $catch->var instanceof Expr\Variable && is_string($catch->var->name) && $catchFrame->vars[$catch->var->name] = $exception;
                    [$more, $catchAfter] = $this->branch(fn () => $this->exec($catch->stmts, $catchFrame));
                    array_push($returns, ...$more);
                    $catchAfter !== null && $falls[] = $catchAfter;
                    $caught = true;

                    break;
                }
            }
            $caught || $this->throwShape($exception);
        }

        // A catch for Exception/Throwable also runs for failures the analyzer can't see (DB errors, I/O).
        foreach ($stmt->catches as $catch) {
            if ($thrown === [] && $this->catchesAnything($catch)) {
                $catchFrame = clone $frame;
                $catch->var instanceof Expr\Variable && is_string($catch->var->name)
                    && $catchFrame->vars[$catch->var->name] = ['k' => 'exception', 'class' => ClassInfo::name($catch->types[0]), 'status' => null, 'message' => Shape::string()];
                [$more, $catchAfter] = $this->branch(fn () => $this->exec($catch->stmts, $catchFrame));
                array_push($returns, ...$more);
                $catchAfter !== null && $falls[] = $catchAfter;
            }
        }

        if ($stmt->finally !== null) {
            [$more] = $this->exec($stmt->finally->stmts, clone $frame);
            array_push($returns, ...$more);
        }
        if ($falls === []) {
            return [$returns, null];
        }
        $merged = array_shift($falls);
        foreach ($falls as $f) {
            $merged = Frame::merge($merged, $f);
        }

        return [$returns, $merged];
    }

    private function catches(Stmt\Catch_ $catch, array $exception): bool
    {
        foreach ($catch->types as $type) {
            $name = strtolower(ClassInfo::name($type));
            if (in_array(class_basename($name), ['throwable', 'exception'], true) || strtolower($exception['class'] ?? '') === $name
                || $this->isA($exception['class'] ?? '', ClassInfo::name($type))) {
                return true;
            }
        }

        return false;
    }

    private function catchesAnything(Stmt\Catch_ $catch): bool
    {
        foreach ($catch->types as $type) {
            if (in_array(strtolower(class_basename(ClassInfo::name($type))), ['throwable', 'exception', 'queryexception', 'pdoexception', 'errorexception'], true)) {
                return true;
            }
        }

        return false;
    }

    private function throwShape(array $exception): void
    {
        $this->thrown[count($this->thrown) - 1][] = $exception;
    }

    /** Statements after these calls never run. */
    private function ends(Expr $expr): bool
    {
        return ($expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name && in_array(strtolower($expr->name->toString()), ['abort', 'dd', 'exit', 'die'], true))
            || $expr instanceof Expr\Exit_;
    }

    /**
     * Keep only the alternatives of union-typed variables for which $cond can evaluate to $truth
     * (e.g. `data_get($check, 'status_code') === 403` picks the error-array alternatives).
     */
    private function narrowUnions(Expr $cond, Frame $frame, bool $truth): void
    {
        $names = [];
        foreach ((new NodeFinder)->findInstanceOf([$cond], Expr\Variable::class) as $var) {
            if (is_string($var->name) && ($frame->vars[$var->name]['k'] ?? null) === 'union' && count($frame->vars[$var->name]['of']) <= 12) {
                $names[$var->name] = true;
            }
        }
        foreach (array_keys($names) as $name) {
            $kept = [];
            foreach ($frame->vars[$name]['of'] as $alt) {
                $probe = clone $frame;
                $probe->vars[$name] = $alt;
                $this->thrown[] = [];
                $result = $this->truth($this->eval($cond, $probe));
                array_pop($this->thrown);
                $result !== ! $truth && $kept[] = $alt; // keep unless provably the other way
            }
            if ($kept !== []) {
                $frame->vars[$name] = Shape::union($kept);
            }
        }
    }

    /** Inside `if ($x)` / `if (isset($x['k']))` the variable is known not to be null. */
    private function narrow(Expr $cond, Frame $frame): void
    {
        $this->narrowUnions($cond, $frame, true);
        if ($cond instanceof Expr\Variable && is_string($cond->name) && isset($frame->vars[$cond->name])) {
            $frame->vars[$cond->name] = self::withoutNull($frame->vars[$cond->name]);
        }
        if ($cond instanceof Expr\BinaryOp\BooleanAnd) {
            $this->narrow($cond->left, $frame);
            $this->narrow($cond->right, $frame);
        }
    }

    private function debugComputed(Frame $f, array $object): array
    {
        return Shape::unknown('static call on a computed class');
    }

    /** @var array<string, array<string, array<string, mixed>>> model class => attribute => values the code sets at runtime */
    private array $setAttributes = [];

    /** Attributes the code set on instances of this model ($cart->selected_variation = …). @return array<string, array<string, mixed>> */
    public function setAttributes(string $class): array
    {
        return $this->setAttributes[strtolower($class)] ?? [];
    }

    /** On the path where $cond was false: `if (! $x) return;` leaves $x truthy after it. */
    private function narrowFalse(Expr $cond, Frame $frame): void
    {
        $this->narrowUnions($cond, $frame, false);
        $var = fn (Expr $e) => $e instanceof Expr\Variable && is_string($e->name) && isset($frame->vars[$e->name]) ? $e->name : null;
        if ($cond instanceof Expr\BooleanNot && ($name = $var($cond->expr)) !== null) {
            $frame->vars[$name] = self::truthyOnly($frame->vars[$name]);
        } elseif (($cond instanceof Expr\BinaryOp\Identical || $cond instanceof Expr\BinaryOp\Equal)
            && $cond->right instanceof Expr\ConstFetch && strtolower($cond->right->name->toString()) === 'null' && ($name = $var($cond->left)) !== null) {
            $frame->vars[$name] = self::withoutNull($frame->vars[$name]);
        } elseif ($cond instanceof Expr\FuncCall && $cond->name instanceof Node\Name && strtolower($cond->name->toString()) === 'is_null'
            && ($cond->args[0] ?? null) instanceof Node\Arg && ($name = $var($cond->args[0]->value)) !== null) {
            $frame->vars[$name] = self::withoutNull($frame->vars[$name]);
        } elseif ($cond instanceof Expr\BinaryOp\BooleanOr) {
            $this->narrowFalse($cond->left, $frame);
            $this->narrowFalse($cond->right, $frame);
        }
    }

    /** Drop the falsy alternatives (null, false, '', 0) from a union. */
    private static function truthyOnly(array $s): array
    {
        $alts = $s['k'] === 'union' ? $s['of'] : [$s];
        $kept = array_values(array_filter($alts, fn ($x) => ! ($x['k'] === 'scalar' && (($x['types'] === ['null']) || (array_key_exists('value', $x) && ! $x['value'])))));

        return $kept === [] ? $s : self::withoutNull(Shape::union($kept));
    }

    private static function withoutNull(array $s): array
    {
        if ($s['k'] === 'union') {
            $rest = array_values(array_filter($s['of'], fn ($x) => ! ($x['k'] === 'scalar' && $x['types'] === ['null'])));

            return $rest === [] ? $s : Shape::union(array_map([self::class, 'withoutNull'], $rest));
        }
        if ($s['k'] === 'scalar' && count($s['types']) > 1 && in_array('null', $s['types'], true)) {
            return Shape::scalar(array_values(array_diff($s['types'], ['null'])));
        }

        return $s;
    }

    // ───────────────────────────── expressions ─────────────────────────────

    private function eval(Expr $e, Frame $f): array
    {
        if (++$this->steps > self::MAX_STEPS) {
            $this->budgetHit = true;

            return Shape::unknown('analysis budget reached');
        }

        return match (true) {
            $e instanceof Scalar\String_ => Shape::literal($e->value),
            $e instanceof Scalar\Int_ => Shape::literal($e->value),
            $e instanceof Scalar\Float_ => Shape::literal($e->value),
            $e instanceof Scalar\InterpolatedString => Shape::string(),
            $e instanceof Scalar\MagicConst => Shape::string(),
            $e instanceof Expr\ConstFetch => match (strtolower($e->name->toString())) {
                'true' => Shape::literal(true), 'false' => Shape::literal(false), 'null' => Shape::null(),
                default => Shape::unknown('constant '.$e->name->toString()),
            },
            $e instanceof Expr\Array_ => $this->arrayLiteral($e, $f),
            $e instanceof Expr\Variable => $this->variable($e, $f),
            $e instanceof Expr\Assign => $this->assign($e, $f),
            $e instanceof Expr\AssignOp => $this->assignOp($e, $f),
            $e instanceof Expr\AssignRef => $this->assign(new Expr\Assign($e->var, $e->expr), $f),
            $e instanceof Expr\ArrayDimFetch => $this->dim($this->eval($e->var, $f), $e->dim === null ? null : $this->eval($e->dim, $f)),
            $e instanceof Expr\PropertyFetch, $e instanceof Expr\NullsafePropertyFetch => $this->property($e, $f),
            $e instanceof Expr\StaticPropertyFetch => $this->staticProperty($e, $f),
            $e instanceof Expr\ClassConstFetch => $this->classConst($e, $f),
            $e instanceof Expr\Cast => $this->cast($e, $f),
            $e instanceof Expr\BinaryOp\Coalesce => $this->coalesce($e, $f),
            $e instanceof Expr\BinaryOp\Concat => $this->concat($e, $f),
            $e instanceof Expr\BinaryOp => $this->binary($e, $f),
            $e instanceof Expr\BooleanNot => $this->not($this->eval($e->expr, $f)),
            $e instanceof Expr\UnaryMinus, $e instanceof Expr\UnaryPlus => $this->numeric($this->eval($e->expr, $f)),
            $e instanceof Expr\Ternary => $this->ternary($e, $f),
            $e instanceof Expr\Isset_ => $this->isset($e, $f),
            $e instanceof Expr\Empty_ => $this->not($this->eval($e->expr, $f)),
            $e instanceof Expr\Instanceof_ => $this->instanceOf($e, $f),
            $e instanceof Expr\Match_ => $this->match($e, $f),
            $e instanceof Expr\FuncCall => $this->traced($e, $f, fn () => $this->funcCall($e, $f)),
            $e instanceof Expr\MethodCall, $e instanceof Expr\NullsafeMethodCall => $this->traced($e, $f, fn () => $this->methodCall($e, $f)),
            $e instanceof Expr\StaticCall => $this->traced($e, $f, fn () => $this->staticCall($e, $f)),
            $e instanceof Expr\New_ => $this->traced($e, $f, fn () => $this->newObject($e, $f)),
            $e instanceof Expr\Closure, $e instanceof Expr\ArrowFunction => ['k' => 'closure', 'node' => $e, 'class' => $f->class, 'vars' => $f->vars, 'self' => $f->self],
            $e instanceof Expr\ErrorSuppress => $this->eval($e->expr, $f),
            $e instanceof Expr\PreInc, $e instanceof Expr\PostInc, $e instanceof Expr\PreDec, $e instanceof Expr\PostDec => Shape::int(),
            $e instanceof Expr\Throw_ => $this->throwExpr($e, $f),
            $e instanceof Expr\Print_ => Shape::literal(1),
            $e instanceof Expr\Clone_ => $this->eval($e->expr, $f),
            default => Shape::unknown(class_basename($e)),
        };
    }

    private function throwExpr(Expr\Throw_ $e, Frame $f): array
    {
        $thrown = $this->eval($e->expr, $f);
        $this->recorder !== null && $this->recordThrow($thrown, $f, $e);
        $this->throwShape($thrown);

        return Shape::unknown('never: throws');
    }

    private function arrayLiteral(Expr\Array_ $e, Frame $f): array
    {
        $props = [];
        $list = [];
        $isList = true;
        $optional = [];
        foreach ($e->items as $item) {
            if ($item->unpack) {
                $spread = $this->eval($item->value, $f);
                if ($spread['k'] === 'object') {
                    $props = array_merge($props, $spread['props']);
                    $isList = false;
                } else {
                    $list[] = $spread['k'] === 'list' ? $spread['item'] : Shape::unknown('spread of a computed value');
                }

                continue;
            }
            $value = $this->eval($item->value, $f);
            if ($item->key === null) {
                $list[] = $value;
                $props[count($props)] = $value;

                continue;
            }
            $isList = false;
            $key = Shape::toPhp($this->eval($item->key, $f));
            if (! is_string($key) && ! is_int($key)) {
                return ['k' => 'map', 'value' => $value];
            }
            $props[$key] = $value;
        }

        if ($isList) {
            return $list === [] ? Shape::emptyList() : ['k' => 'list', 'item' => Shape::union($list), 'values' => $list];
        }

        return Shape::object($props, $optional);
    }

    private function variable(Expr\Variable $e, Frame $f): array
    {
        if (! is_string($e->name)) {
            return Shape::unknown('variable variable');
        }
        if ($e->name === 'this') {
            return $f->self ?? ['k' => 'instance', 'class' => $f->class->fqcn, 'props' => []];
        }

        return $f->vars[$e->name] ?? Shape::unknown("undefined variable \${$e->name}");
    }

    private function assign(Expr\Assign $e, Frame $f): array
    {
        $value = $this->eval($e->expr, $f);
        $this->assignTo($e->var, $value, $f);

        return $value;
    }

    private function assignOp(Expr\AssignOp $e, Frame $f): array
    {
        $value = $e instanceof Expr\AssignOp\Concat ? Shape::string()
            : ($e instanceof Expr\AssignOp\Coalesce ? Shape::union([$this->eval($e->var, $f), $this->eval($e->expr, $f)]) : Shape::scalar(['integer', 'number']));
        $this->assignTo($e->var, $value, $f);

        return $value;
    }

    private function assignTo(Expr $target, array $value, Frame $f): void
    {
        if ($target instanceof Expr\Variable && is_string($target->name)) {
            $f->vars[$target->name] = $value;

            return;
        }
        if ($target instanceof Expr\List_ || $target instanceof Expr\Array_) {
            foreach ($target->items as $i => $item) {
                if ($item === null) {
                    continue;
                }
                $key = $item->key === null ? $i : Shape::toPhp($this->eval($item->key, $f));
                $this->assignTo($item->value, is_string($key) || is_int($key) ? $this->dim($value, Shape::literal($key)) : Shape::unknown('destructuring'), $f);
            }

            return;
        }
        if ($target instanceof Expr\ArrayDimFetch) {
            // $a['x']['y'] = v  /  $a[] = v
            $path = [];
            $base = $target;
            while ($base instanceof Expr\ArrayDimFetch) {
                array_unshift($path, $base->dim === null ? null : Shape::toPhp($this->eval($base->dim, $f)));
                $base = $base->var;
            }
            if ($base instanceof Expr\Variable && is_string($base->name) && ($f->vars[$base->name]['k'] ?? null) === 'request') {
                // $request['vendor'] = $vendor in a middleware: later code reads it from the request.
                $key = $path[0] ?? null;
                if (is_string($key) && count($path) === 1) {
                    $this->requestAttributes[$key] = isset($this->requestAttributes[$key]) ? Shape::union([$this->requestAttributes[$key], $value]) : $value;
                }

                return;
            }
            if ($base instanceof Expr\Variable && is_string($base->name)) {
                $current = $f->vars[$base->name] ?? Shape::emptyList();
                $f->vars[$base->name] = $this->setPath($current, $path, $value);
            } elseif ($base instanceof Expr\PropertyFetch && $base->var instanceof Expr\Variable && $base->var->name === 'this' && $base->name instanceof Node\Identifier && $f->self !== null) {
                $name = $base->name->toString();
                $f->self['props'][$name] = $this->setPath($f->self['props'][$name] ?? Shape::emptyList(), $path, $value);
            }

            return;
        }
        if ($target instanceof Expr\StaticPropertyFetch && $target->class instanceof Node\Name && $target->name instanceof Node\VarLikeIdentifier) {
            $key = strtolower($this->className($target->class, $f)).'::'.$target->name->toString();
            // A static is shared across calls: it can hold anything ever assigned to it.
            $this->statics[$key] = isset($this->statics[$key]) ? Shape::union([$this->statics[$key], $value]) : Shape::union([$this->staticDefault($target, $f), $value]);

            return;
        }
        // $model->extra = …: Laravel serializes attributes set at runtime too.
        if ($target instanceof Expr\PropertyFetch && $target->name instanceof Node\Identifier && ! ($target->var instanceof Expr\Variable && $target->var->name === 'this')) {
            $owner = $this->eval($target->var, $f);
            foreach ($owner['k'] === 'union' ? $owner['of'] : [$owner] as $o) {
                if ($o['k'] === 'model' && $this->schema->column($o['class'], $target->name->toString()) === null) {
                    $key = strtolower($o['class']);
                    $name = $target->name->toString();
                    $this->setAttributes[$key][$name] = isset($this->setAttributes[$key][$name]) ? Shape::union([$this->setAttributes[$key][$name], $value]) : $value;
                }
            }

            return;
        }
        if ($target instanceof Expr\PropertyFetch && $target->var instanceof Expr\Variable && $target->var->name === 'this' && $target->name instanceof Node\Identifier) {
            $f->self ??= ['k' => 'instance', 'class' => $f->class->fqcn, 'props' => []];
            $f->self['props'][$target->name->toString()] = $value;
        }
    }

    /** @var array<string, array<string, mixed>> class::prop => every value assigned to a static property */
    private array $statics = [];

    private function staticProperty(Expr\StaticPropertyFetch $e, Frame $f): array
    {
        if (! $e->class instanceof Node\Name || ! $e->name instanceof Node\VarLikeIdentifier) {
            return Shape::unknown('dynamic static property');
        }
        $key = strtolower($this->className($e->class, $f)).'::'.$e->name->toString();
        if (! isset($this->statics[$key])) {
            // Not assigned yet in what was read: its declared default, unioned with assignments anywhere in the class.
            $values = [$this->staticDefault($e, $f)];
            $info = $this->index->find($this->className($e->class, $f));
            foreach ($info === null ? [] : (new NodeFinder)->find($info->node->stmts, fn (Node $n) => $n instanceof Expr\Assign && $n->var instanceof Expr\StaticPropertyFetch
                && $n->var->name instanceof Node\VarLikeIdentifier && $n->var->name->toString() === $e->name->toString()) as $assign) {
                /** @var Expr\Assign $assign */
                $this->statics[$key] = Shape::union($values); // guard against self-reference while evaluating
                $values[] = $this->eval($assign->expr, new Frame($info, [], null, $f->depth + 1));
            }
            $this->statics[$key] = Shape::union($values);
        }

        return $this->statics[$key];
    }

    private function staticDefault(Expr\StaticPropertyFetch $e, Frame $f): array
    {
        if (! $e->class instanceof Node\Name || ! $e->name instanceof Node\VarLikeIdentifier) {
            return Shape::null();
        }
        $info = $this->index->find($this->className($e->class, $f));
        foreach ($info?->node->getProperties() ?? [] as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === $e->name->toString()) {
                    return $prop->default === null ? Shape::null() : $this->eval($prop->default, new Frame($info));
                }
            }
        }

        return Shape::null();
    }

    /** @param list<mixed> $path */
    private function setPath(array $container, array $path, array $value): array
    {
        if ($path === []) {
            return $value;
        }
        $key = array_shift($path);

        if ($key === null) { // $a[] = v
            if ($container['k'] === 'list') {
                $values = isset($container['values']) && $container['values'] === [] ? [] : null;
                $item = $values === [] ? $this->setPath(Shape::emptyList(), $path, $value) : Shape::union([$container['item'], $this->setPath(Shape::emptyList(), $path, $value)]);

                return Shape::list($item);
            }

            return Shape::list($this->setPath(Shape::emptyList(), $path, $value));
        }
        if ($key instanceof Unresolved || (! is_string($key) && ! is_int($key))) {
            // $a[$x][$y] = v: a map of maps (keys are runtime data, the values' shape is known)
            $inner = $container['k'] === 'map' ? $container['value'] : Shape::emptyList();

            return ['k' => 'map', 'value' => $path === [] ? $value : $this->setPath($inner['k'] === 'never' ? Shape::emptyList() : $inner, $path, $value)];
        }
        if ($container['k'] === 'list' && isset($container['values']) && $container['values'] === []) {
            $container = Shape::object([]);
        }
        if ($container['k'] === 'object') {
            $container['props'][$key] = $this->setPath($container['props'][$key] ?? Shape::emptyList(), $path, $value);

            return $container;
        }
        if ($container['k'] === 'map') {
            return $container;
        }
        // $model['cuisines'] = …: sets an attribute on the model (serialized with it); the model stays a model.
        if ($container['k'] === 'model' || ($container['k'] === 'union' && array_filter($container['of'], fn ($o) => $o['k'] === 'model') !== [])) {
            foreach ($container['k'] === 'union' ? $container['of'] : [$container] as $o) {
                if ($o['k'] === 'model' && $path === []) {
                    $k = strtolower($o['class']);
                    $this->setAttributes[$k][(string) $key] = isset($this->setAttributes[$k][(string) $key]) ? Shape::union([$this->setAttributes[$k][(string) $key], $value]) : $value;
                }
            }

            return $container;
        }

        return Shape::object([$key => $this->setPath(Shape::emptyList(), $path, $value)]);
    }

    /** $value[$key] */
    private function dim(array $value, ?array $key): array
    {
        if ($value['k'] === 'never') {
            return $value;
        }
        $k = $key === null ? new Unresolved : Shape::toPhp($key);

        return match ($value['k']) {
            'object' => (is_string($k) || is_int($k))
                ? ($value['props'][$k] ?? Shape::null())  // a missing key reads as null (with a warning in PHP)
                : Shape::union(array_values($value['props']) ?: [Shape::null()]),
            'list' => isset($value['values']) ? ((is_int($k) || is_string($k)) && isset($value['values'][$k]) ? $value['values'][$k] : ($value['values'] === [] ? Shape::null() : Shape::nullable($value['item'])))
                : Shape::nullable($value['item']),
            'map' => Shape::nullable($value['value']),
            'union' => Shape::union(array_map(fn ($v) => $this->dim($v, $key), $value['of'])),
            'model' => is_string($k) ? $this->modelAttribute($value, $k) : Shape::unknown('model attribute'),
            'request' => is_string($k) ? $this->input($k) : Shape::unknown('request value'),
            'collection' => Shape::nullable($value['item']),
            'scalar' => $value['types'] === ['null'] ? Shape::null() : Shape::unknown('offset of a scalar'),
            'instance' => is_string($k) && isset($value['props'][$k]) ? $value['props'][$k] : Shape::unknown('offset of an object'),
            'unknown' => $value, // keep the original reason
            default => Shape::unknown('offset of '.$value['k']),
        };
    }

    private function property(Expr\PropertyFetch|Expr\NullsafePropertyFetch $e, Frame $f): array
    {
        $object = $this->eval($e->var, $f);
        if (! $e->name instanceof Node\Identifier) {
            return Shape::unknown('dynamic property');
        }
        $name = $e->name->toString();
        $value = $this->propertyOf($object, $name, $f);

        return $e instanceof Expr\NullsafePropertyFetch ? Shape::union([$value, ...($this->mayBeNull($object) ? [Shape::null()] : [])]) : $value;
    }

    private function propertyOf(array $object, string $name, Frame $f): array
    {
        if ($object['k'] === 'never') {
            return $object;
        }

        return match ($object['k']) {
            'union' => Shape::union(array_map(fn ($o) => $this->propertyOf($o, $name, $f), array_values(array_filter($object['of'], fn ($o) => ! ($o['k'] === 'scalar' && $o['types'] === ['null']))) ?: [Shape::null()])),
            'model' => $this->modelAttribute($object, $name),
            'request' => $this->input($name),
            'instance' => $this->instanceProperty($object, $name),
            'object' => $object['props'][$name] ?? Shape::null(),
            'exception' => match ($name) {
                'message' => $object['message'],
                'status' => $object['status'] === null ? Shape::int() : Shape::literal($object['status']),
                'validator' => ['k' => 'validator', 'messages' => $object['errors'] ?? null],
                'response' => $object['response'] ?? Shape::null(),
                default => $object['props'][$name] ?? Shape::unknown("exception property {$name}"),
            },
            'scalar' => $object['types'] === ['null'] ? Shape::null() : Shape::unknown("property {$name} of a scalar"),
            'unknown' => $object,
            'optional', 'merge' => $this->propertyOf($object['value'], $name, $f),
            default => Shape::unknown("property {$name} of a {$object['k']}"),
        };
    }

    private function instanceProperty(array $object, string $name): array
    {
        if (array_key_exists($name, $object['props'])) {
            return $object['props'][$name];
        }
        // In a JsonResource, $this->name reads the wrapped resource.
        if (isset($object['props']['resource']) && $this->isA($object['class'], self::JSON_RESOURCE)) {
            return $name === 'resource' ? $object['props']['resource'] : $this->propertyOf($object['props']['resource'], $name, new Frame($this->index->find($object['class']) ?? throw new \LogicException));
        }
        // A declared property with a literal default.
        $info = $this->index->find($object['class']);
        foreach ($info?->node->getProperties() ?? [] as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === $name) {
                    return $prop->default === null ? Shape::null() : $this->eval($prop->default, new Frame($info));
                }
            }
        }

        return Shape::unknown("property {$name} of ".class_basename($object['class']));
    }

    private function modelAttribute(array $model, string $name): array
    {
        $info = $this->index->find($model['class']);
        if ($info !== null && ($relation = $this->relationTarget($info, $name)) !== null) {
            [$class, $many] = $relation;
            $related = ['k' => 'model', 'class' => $class, 'with' => []];

            return $many ? ['k' => 'collection', 'item' => $related] : Shape::nullable($related);
        }
        if ($info !== null && ($accessor = $this->accessor($info, $name, 3)) !== null) {
            return $accessor;
        }
        if (isset($this->setAttributes[strtolower($model['class'])][$name])) {
            return Shape::nullable($this->setAttributes[strtolower($model['class'])][$name]);
        }
        // created_at/updated_at/deleted_at and date casts are Carbon objects when read (strings in JSON).
        if ($this->schema->isDate($model['class'], $name)) {
            $carbon = ['k' => 'instance', 'class' => 'Carbon\Carbon', 'props' => []];

            return in_array('null', $this->schema->column($model['class'], $name)['types'] ?? ['null'], true) ? Shape::nullable($carbon) : $carbon;
        }

        if (($column = $this->schema->column($model['class'], $name)) !== null) {
            return $column;
        }

        // Laravel returns null for an attribute the model doesn't have: exact when its columns are known.
        return $this->schema->tableKnown($model['class']) ? Shape::null()
            : Shape::unknown("attribute {$name} of ".class_basename($model['class']).' (its table was not found)');
    }

    /** @return array{string, bool}|null related class, and whether it is a "many" relation */
    private function relationTarget(ClassInfo $model, string $name): ?array
    {
        $found = $this->index->method($model, $name); // the class, its traits and parents
        if ($found === null) {
            return null;
        }
        [$model, $method] = $found;
        foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Expr\MethodCall::class) as $call) {
            if ($call->var instanceof Expr\Variable && $call->var->name === 'this' && $call->name instanceof Node\Identifier) {
                $type = strtolower($call->name->toString());
                $many = in_array($type, ['hasmany', 'belongstomany', 'morphmany', 'morphtomany', 'morphedbymany', 'hasmanythrough'], true);
                if ($many || in_array($type, ['belongsto', 'hasone', 'morphone', 'hasonethrough'], true)) {
                    $class = $model->file->resolver()->arg($call->args, 0);

                    return is_string($class) ? [$class, $many] : null;
                }
            }
        }

        return null;
    }

    private function classConst(Expr\ClassConstFetch $e, Frame $f): array
    {
        if (! $e->name instanceof Node\Identifier || ! $e->class instanceof Node\Name) {
            return Shape::unknown('dynamic class constant');
        }
        $class = $this->className($e->class, $f);
        if ($e->name->toString() === 'class') {
            return Shape::literal($class);
        }
        // Response::HTTP_* and similar framework constants
        if (str_starts_with($e->name->toString(), 'HTTP_') && defined('Symfony\Component\HttpFoundation\Response::'.$e->name->toString())) {
            return Shape::literal(constant('Symfony\Component\HttpFoundation\Response::'.$e->name->toString()));
        }
        for ($info = $this->index->find($class), $i = 0; $info !== null && $i < 6; $i++) {
            foreach ($info->node->getConstants() as $const) {
                foreach ($const->consts as $c) {
                    if ($c->name->toString() === $e->name->toString()) {
                        return $this->eval($c->value, new Frame($info));
                    }
                }
            }
            $parent = $info->parentName();
            $info = $parent === null ? null : $this->index->find($parent);
        }

        return Shape::unknown("constant {$class}::{$e->name}");
    }

    private function className(Node\Name $name, Frame $f): string
    {
        return match (strtolower($name->toString())) {
            'self', 'static' => $f->class->fqcn,
            'parent' => $f->class->parentName() ?? 'parent',
            default => ClassInfo::name($name),
        };
    }

    private function cast(Expr\Cast $e, Frame $f): array
    {
        $inner = $this->eval($e->expr, $f);
        [$known, $value] = Shape::known($inner);

        return match (true) {
            $e instanceof Expr\Cast\Int_ => $known && is_scalar($value) ? Shape::literal((int) $value) : Shape::int(),
            $e instanceof Expr\Cast\Double => $known && is_scalar($value) ? Shape::literal((float) $value) : Shape::scalar(['number']),
            $e instanceof Expr\Cast\String_ => $known && is_scalar($value) ? Shape::literal((string) $value) : Shape::string(),
            $e instanceof Expr\Cast\Bool_ => ($t = $this->truth($inner)) === null ? Shape::bool() : Shape::literal($t),
            $e instanceof Expr\Cast\Array_ => match ($inner['k']) {
                'object', 'list', 'map' => $inner,
                'scalar' => $inner['types'] === ['null'] ? Shape::emptyList() : Shape::list($inner),
                default => $this->json($inner),
            },
            default => $inner,
        };
    }

    private function coalesce(Expr\BinaryOp\Coalesce $e, Frame $f): array
    {
        $left = $this->eval($e->left, $f);
        if (! $this->mayBeNull($left)) {
            return $left;
        }

        return Shape::union([self::withoutNull($left), $this->branch(fn () => $this->eval($e->right, $f), $this->truth($left) !== false)]);
    }

    private function mayBeNull(array $s): bool
    {
        return match ($s['k']) {
            'scalar' => in_array('null', $s['types'], true),
            'union' => array_filter($s['of'], fn ($x) => $this->mayBeNull($x)) !== [],
            'unknown' => true,
            default => false,
        };
    }

    private function concat(Expr\BinaryOp\Concat $e, Frame $f): array
    {
        [$lk, $l] = Shape::known($this->eval($e->left, $f));
        [$rk, $r] = Shape::known($this->eval($e->right, $f));

        return $lk && $rk && is_scalar($l ?? '') && is_scalar($r ?? '') ? Shape::literal($l.$r) : Shape::string();
    }

    private function binary(Expr\BinaryOp $e, Frame $f): array
    {
        if ($e instanceof Expr\BinaryOp\BooleanAnd || $e instanceof Expr\BinaryOp\LogicalAnd) {
            $l = $this->truth($this->eval($e->left, $f));
            if ($l === false) {
                return Shape::literal(false);
            }
            $r = $this->truth($this->branch(fn () => $this->eval($e->right, $f), $l === null));

            return $l === true && $r !== null ? Shape::literal($r) : ($r === false ? Shape::literal(false) : Shape::bool());
        }
        if ($e instanceof Expr\BinaryOp\BooleanOr || $e instanceof Expr\BinaryOp\LogicalOr) {
            $l = $this->truth($this->eval($e->left, $f));
            if ($l === true) {
                return Shape::literal(true);
            }
            $r = $this->truth($this->branch(fn () => $this->eval($e->right, $f), $l === null));

            return $l === false && $r !== null ? Shape::literal($r) : ($r === true ? Shape::literal(true) : Shape::bool());
        }

        $left = $this->eval($e->left, $f);
        $right = $this->eval($e->right, $f);

        // $a + $b on arrays is a union: keys of $a win, missing keys come from $b.
        if ($e instanceof Expr\BinaryOp\Plus && in_array($left['k'], ['object', 'list', 'map'], true) && in_array($right['k'], ['object', 'list', 'map'], true)) {
            if ($left['k'] === 'object' && $right['k'] === 'object') {
                return Shape::object($left['props'] + $right['props'], [...$left['optional'], ...array_diff($right['optional'], array_map('strval', array_keys($left['props'])))]);
            }

            return $left['k'] === 'map' ? $left : $right;
        }

        if ($e instanceof Expr\BinaryOp\Identical || $e instanceof Expr\BinaryOp\Equal) {
            return $this->compare($left, $right, $e instanceof Expr\BinaryOp\Identical);
        }
        if ($e instanceof Expr\BinaryOp\NotIdentical || $e instanceof Expr\BinaryOp\NotEqual) {
            return $this->not($this->compare($left, $right, $e instanceof Expr\BinaryOp\NotIdentical));
        }

        [$lk, $l] = Shape::known($left);
        [$rk, $r] = Shape::known($right);
        $both = $lk && $rk && is_scalar($l ?? 0) && is_scalar($r ?? 0);

        if ($e instanceof Expr\BinaryOp\Smaller || $e instanceof Expr\BinaryOp\SmallerOrEqual || $e instanceof Expr\BinaryOp\Greater
            || $e instanceof Expr\BinaryOp\GreaterOrEqual || $e instanceof Expr\BinaryOp\Spaceship) {
            if (! $both) {
                return $e instanceof Expr\BinaryOp\Spaceship ? Shape::int() : Shape::bool();
            }

            return Shape::literal(match (true) {
                $e instanceof Expr\BinaryOp\Smaller => $l < $r,
                $e instanceof Expr\BinaryOp\SmallerOrEqual => $l <= $r,
                $e instanceof Expr\BinaryOp\Greater => $l > $r,
                $e instanceof Expr\BinaryOp\GreaterOrEqual => $l >= $r,
                default => $l <=> $r,
            });
        }

        // arithmetic
        $types = array_merge($left['types'] ?? ['number'], $right['types'] ?? ['number']);
        $float = in_array('number', $types, true) || $e instanceof Expr\BinaryOp\Div;

        return Shape::scalar([$float ? 'number' : 'integer']);
    }

    private function compare(array $a, array $b, bool $strict): array
    {
        [$ak, $av] = Shape::known($a);
        [$bk, $bv] = Shape::known($b);
        if ($ak && $bk) {
            return Shape::literal($strict ? $av === $bv : $av == $bv);
        }
        // Fully known arrays compare by value too.
        $pa = Shape::toPhp($a);
        $pb = Shape::toPhp($b);
        if (! $pa instanceof Unresolved && ! $pb instanceof Unresolved) {
            return Shape::literal($strict ? $pa === $pb : $pa == $pb);
        }
        // A value that can't be null is never identical to null.
        if (($bk && $bv === null && ! $this->mayBeNull($a)) || ($ak && $av === null && ! $this->mayBeNull($b))) {
            return Shape::literal(false);
        }

        return Shape::bool();
    }

    private function not(array $s): array
    {
        $t = $this->truth($s);

        return $t === null ? Shape::bool() : Shape::literal(! $t);
    }

    private function numeric(array $s): array
    {
        return $s['k'] === 'scalar' && in_array('number', $s['types'], true) ? Shape::scalar(['number']) : Shape::scalar(['integer', 'number']);
    }

    private function ternary(Expr\Ternary $e, Frame $f): array
    {
        $cond = $this->eval($e->cond, $f);
        $truth = $this->truth($cond);
        $if = $e->if === null ? $cond : null;

        return match ($truth) {
            true => $if ?? $this->eval($e->if, $f),
            false => $this->eval($e->else, $f),
            null => Shape::union([$if ?? $this->branch(fn () => $this->eval($e->if, $f)), $this->branch(fn () => $this->eval($e->else, $f))]),
        };
    }

    private function isset(Expr\Isset_ $e, Frame $f): array
    {
        $all = true;
        foreach ($e->vars as $var) {
            $value = $this->eval($var, $f);
            if ($value['k'] === 'scalar' && $value['types'] === ['null']) {
                return Shape::literal(false);
            }
            $this->mayBeNull($value) && $all = false;
        }

        return $all ? Shape::literal(true) : Shape::bool();
    }

    private function instanceOf(Expr\Instanceof_ $e, Frame $f): array
    {
        $value = $this->eval($e->expr, $f);
        if (! $e->class instanceof Node\Name) {
            return Shape::bool();
        }
        $class = $this->className($e->class, $f);
        $of = fn (array $v) => match ($v['k']) {
            'instance', 'model', 'exception' => $this->isA($v['class'], $class),
            'scalar', 'object', 'list', 'map' => false,
            'response' => $this->isA('Illuminate\Http\JsonResponse', $class),
            default => null,
        };
        $results = $value['k'] === 'union' ? array_map($of, $value['of']) : [$of($value)];
        $results = array_unique(array_map(fn ($r) => $r === null ? 'n' : ($r ? 't' : 'f'), $results));

        return count($results) === 1 && $results[0] !== 'n' ? Shape::literal($results[0] === 't') : Shape::bool();
    }

    private function match(Expr\Match_ $e, Frame $f): array
    {
        $subject = $this->eval($e->cond, $f);
        $out = [];
        $undecided = false;
        foreach ($e->arms as $arm) {
            if ($arm->conds === null) {
                $out[] = $this->branch(fn () => $this->eval($arm->body, $f), $undecided);

                break;
            }
            $hit = null;
            foreach ($arm->conds as $cond) {
                $t = $this->truth($this->compare($subject, $this->eval($cond, $f), true));
                $hit = $t === true ? true : ($hit ?? $t);
            }
            if ($hit !== false) {
                $out[] = $this->branch(fn () => $this->eval($arm->body, $f), $undecided || $hit === null);
            }
            $undecided = $undecided || $hit === null;
            if ($hit === true) {
                break;
            }
        }

        return Shape::union($out ?: [Shape::unknown('no match arm')]);
    }

    /** true / false when decidable from the shape, null otherwise. */
    public function truth(array $s): ?bool
    {
        return match ($s['k']) {
            'scalar' => array_key_exists('value', $s) ? (bool) $s['value'] : ($s['types'] === ['null'] ? false : null),
            'object' => array_diff(array_keys($s['props']), $s['optional']) !== [] ? true : ($s['props'] === [] ? false : null),
            'list' => isset($s['values']) ? $s['values'] !== [] : null,
            'instance', 'response', 'closure', 'exception', 'request', 'factory', 'builder', 'paginator', 'collection' => true,
            'model' => true,
            'union' => (function () use ($s) {
                $t = array_unique(array_map(fn ($x) => var_export($this->truth($x), true), $s['of']));

                return count($t) === 1 && $t[0] !== 'NULL' ? $t[0] === 'true' : null;
            })(),
            default => null,
        };
    }

    // ───────────────────────────── calls ─────────────────────────────

    /** @return array<int|string, array<string, mixed>> */
    private function args(array $args, Frame $f): array
    {
        $out = [];
        foreach ($args as $arg) {
            if ($arg instanceof Node\Arg) {
                $out[$arg->name?->toString() ?? count($out)] = $this->eval($arg->value, $f);
            }
        }

        return $out;
    }

    private function funcCall(Expr\FuncCall $e, Frame $f): array
    {
        if ($e->name instanceof Expr\Variable && $f->next !== null && $e->name->name === $f->next) {
            return $f->nextValue ?? Shape::unknown('$next');
        }
        if ($e->name instanceof Expr) {
            $callee = $this->eval($e->name, $f);
            $callee['k'] === 'closure' || $this->recorder?->unresolved('dynamic_function', 'Dynamic function call: the callable is chosen at runtime', $this->source($e));

            return $callee['k'] === 'closure' ? $this->callClosure($callee, $this->args($e->args, $f), $f->depth) : Shape::unknown('dynamic function call');
        }

        $name = strtolower($e->name->getLast());
        $args = $this->args($e->args, $f);
        $this->recorder !== null && in_array($name, ['dispatch', 'dispatch_sync', 'dispatch_now', 'event', 'broadcast'], true)
            && $this->recordDispatch($args[0] ?? null, $name === 'event' || $name === 'broadcast' ? 'event' : 'job', $name !== 'dispatch');
        $a = fn (int $i, ?string $named = null) => $args[$named] ?? $args[$i] ?? null;
        $lit = function (int $i) use ($a) {
            $v = $a($i);

            return $v === null ? new Unresolved : Shape::toPhp($v);
        };

        return match ($name) {
            'response' => $args === [] ? ['k' => 'factory'] : $this->response($a(0, 'content') ?? Shape::string(), $a(1, 'status') ?? Shape::literal(200)),
            'config' => is_string($k = $lit(0)) ? $this->config($k, $a(1)) : Shape::unknown('config key is computed'),
            'env' => $a(1) !== null ? Shape::union([$a(1), Shape::string()]) : Shape::scalar(['null', 'string']),
            'translate', '__', 'trans', 'trans_choice', '_t', 'translate_message' => $this->translate($lit(0)),
            'abort' => $this->abort($a(0) ?? Shape::literal(500), $a(1) ?? Shape::string()),
            'abort_if' => (($t = $this->truth($a(0) ?? Shape::bool())) !== false ? $this->branch(fn () => $this->abortSide($a(1) ?? Shape::literal(500), $a(2) ?? Shape::string()), $t === null) : Shape::null()),
            'abort_unless' => (($t = $this->truth($a(0) ?? Shape::bool())) !== true ? $this->branch(fn () => $this->abortSide($a(1) ?? Shape::literal(500), $a(2) ?? Shape::string()), $t === null) : Shape::null()),
            'collect' => ['k' => 'collection', 'item' => $this->itemOf($a(0) ?? Shape::emptyList())],
            'request' => $args === [] ? ['k' => 'request'] : $this->input(is_string($lit(0)) ? $lit(0) : ''),
            'auth' => ['k' => 'guard', 'name' => is_string($lit(0)) ? $lit(0) : null],
            'app', 'resolve' => $args === [] ? ['k' => 'container'] : ($lit(0) === 'cache' ? ['k' => 'cache'] : $this->resolveClass($lit(0), $f)),
            'cache' => $args === [] ? ['k' => 'cache'] : Shape::unknown('cache() value'),
            // now()/today() are Carbon instances (->format(), ->addMinutes() …); JSON-encoded they are strings.
            'now', 'today' => ['k' => 'instance', 'class' => 'Carbon\Carbon', 'props' => []],
            'date', 'gmdate', 'url', 'route', 'asset', 'secure_url', 'storage_path', 'public_path', 'base_path', 'app_path', 'json_encode',
            'implode', 'join', 'sprintf', 'str_replace', 'strtolower', 'strtoupper', 'ucfirst', 'ucwords', 'lcfirst', 'trim', 'ltrim', 'rtrim', 'substr',
            'number_format', 'uniqid', 'md5', 'sha1', 'hash', 'base64_encode', 'str_pad', 'str_repeat', 'nl2br', 'strip_tags', 'htmlspecialchars', 'preg_replace',
            'csrf_token', 'bcrypt', 'mb_substr', 'mb_strtolower', 'mb_strtoupper', 'strval', 'urlencode', 'rawurlencode', 'http_build_query', 'str_slug' => $this->stringFn($name, $args),
            'count', 'sizeof', 'strlen', 'mb_strlen', 'intval', 'time', 'strtotime', 'rand', 'mt_rand', 'random_int', 'array_sum', 'array_key_last', 'array_key_first', 'array_search', 'strpos', 'stripos', 'floor', 'ceil' => $this->countFn($name, $args),
            'round', 'floatval', 'abs', 'max', 'min', 'pow', 'sqrt', 'array_product' => Shape::scalar(['integer', 'number']),
            'is_array', 'is_string', 'is_int', 'is_integer', 'is_numeric', 'is_bool', 'is_null', 'is_object', 'is_float', 'is_callable' => $this->typeCheck($name, $a(0) ?? Shape::unknown('missing')),
            'in_array' => $this->inArray($a(0), $a(1)),
            'array_key_exists', 'key_exists' => $this->keyExists($a(0), $a(1)),
            'array_is_list' => ($s = $a(0)) === null ? Shape::bool() : match ($s['k']) {
                'list' => Shape::literal(true), 'object' => Shape::literal($s['props'] === [] || array_is_list($s['props'])), default => Shape::bool(),
            },
            'array_merge', 'array_replace' => $this->arrayMerge($args),
            'array_values' => $this->arrayValues($a(0)),
            'array_keys' => ($s = $a(0)) !== null && $s['k'] === 'object' && $s['optional'] === [] ? Shape::literal(array_keys($s['props']))
                : (($s['k'] ?? null) === 'list' && isset($s['values']) ? Shape::literal(array_keys($s['values'])) : Shape::list(Shape::scalar(['integer', 'string']))),
            'sort', 'rsort', 'ksort', 'krsort', 'asort', 'arsort', 'natsort', 'natcasesort', 'usort', 'uasort', 'uksort', 'shuffle' => $this->sortInPlace($name, $e, $f),
            'array_map' => $this->arrayMap($a(0), $a(1), $f),
            'array_filter', 'array_unique', 'array_reverse', 'array_slice', 'array_splice', 'array_diff', 'array_intersect', 'array_diff_key', 'array_intersect_key' => $this->listish($a(0)),
            'array_column' => is_string($col = $lit(1)) || is_int($col) ? Shape::list($this->dim($this->itemOf($a(0) ?? Shape::emptyList()), Shape::literal($col))) : Shape::list(Shape::unknown('array_column by a computed key')),
            'array_combine', 'array_flip', 'array_fill_keys' => ['k' => 'map', 'value' => Shape::unknown('combined values')],
            'explode', 'str_split', 'preg_split', 'range' => Shape::list($name === 'range' ? Shape::int() : Shape::string()),
            'compact' => $this->compact($args, $f),
            'optional', 'value', 'tap', 'with' => $a(0) ?? Shape::null(),
            'data_get' => $this->dataGet($a(0), $lit(1), $a(2)),
            'json_decode' => $this->jsonDecode($a(0)),
            'array_key_last', 'end', 'reset', 'current', 'array_pop', 'array_shift', 'last', 'head' => Shape::nullable($this->itemOf($a(0) ?? Shape::emptyList())),
            'boolval', 'filled', 'blank', 'empty', 'str_contains', 'str_starts_with', 'str_ends_with', 'preg_match', 'is_file', 'file_exists', 'method_exists', 'class_exists',
            'function_exists', 'hash_equals', 'password_verify', 'checkdate', 'ctype_digit', 'filter_var' => Shape::bool(),
            default => $this->userFunction($e, $args, $f),
        };
    }

    /** sort($keys) and friends change their argument: apply it when the array is fully known. */
    private function sortInPlace(string $name, Expr\FuncCall $e, Frame $f): array
    {
        $target = $e->args[0] ?? null;
        if (! $target instanceof Node\Arg || ! $target->value instanceof Expr\Variable || ! is_string($target->value->name)) {
            return Shape::bool();
        }
        $var = $target->value->name;
        $value = Shape::toPhp($f->vars[$var] ?? Shape::null());
        if (is_array($value) && in_array($name, ['sort', 'rsort', 'ksort', 'krsort', 'asort', 'arsort'], true)) {
            $name($value);
            $f->vars[$var] = Shape::literal($value);

            return Shape::literal(true);
        }
        if (isset($f->vars[$var]) && in_array($f->vars[$var]['k'], ['list', 'object'], true)) {
            // order changes, the element types don't; lists lose their known positions
            $f->vars[$var] = $f->vars[$var]['k'] === 'list' && in_array($name, ['sort', 'rsort', 'usort', 'shuffle'], true) ? Shape::list($f->vars[$var]['item']) : $f->vars[$var];
        }

        return Shape::bool();
    }

    /** @param array<int|string, array<string, mixed>> $args */
    private function compact(array $args, Frame $f): array
    {
        $props = [];
        foreach ($args as $arg) {
            $name = Shape::toPhp($arg);
            if (is_string($name)) {
                $props[$name] = $f->vars[$name] ?? Shape::unknown("compact('{$name}')");
            }
        }

        return Shape::object($props);
    }

    /** app(Foo::class): the container builds Foo; read it like `new Foo` (constructor dependencies typed). */
    private function resolveClass(mixed $class, Frame $f): array
    {
        if (! is_string($class)) {
            $this->recorder?->unresolved('container_name', 'Container lookup by a runtime name: the service is chosen at runtime');

            return Shape::unknown('app() resolves a service by name');
        }
        $info = $this->index->find($class);
        if ($info === null) {
            $this->recorder !== null && $this->recordOpaque($class, null);

            return Shape::unknown("service {$class} {$this->index->missingReason($class)}");
        }

        // Interfaces bound in a provider aren't followed; concrete classes are.
        return $info->node instanceof Stmt\Class_ ? $this->newObject(new Expr\New_(new Node\Name\FullyQualified($class)), $f) : Shape::unknown("{$class} is not a concrete class");
    }

    /** @var array<string, array{ClassInfo, Stmt\Function_}>|null global functions from composer "files" */
    private ?array $functions = null;

    /** A global function defined in the app (helpers.php): interpreted like a method. */
    private function userFunction(Expr\FuncCall $e, array $args, Frame $f): array
    {
        $name = $e->name instanceof Node\Name ? strtolower($e->name->getLast()) : '';
        $fn = $this->globalFunctions()[$name] ?? null;
        if ($fn === null) {
            return Shape::unknown("function {$name}() is not defined in the app");
        }
        $to = null;
        if ($this->recorder !== null) {
            $to = 'fn:'.$name;
            $this->recorder->node($to, ['type' => 'service', 'label' => $fn[1]->name->toString().'()', 'class' => null, 'method' => $fn[1]->name->toString()]);
        }
        if ($f->depth >= self::MAX_DEPTH) {
            $to !== null && $this->recorder->edge($to, 'calls', ['reason' => 'bound_depth', 'note' => 'Bound reached: call depth '.self::MAX_DEPTH.'; not followed further']);

            return Shape::unknown('call chain too deep');
        }
        $key = 'fn:'.$name.'#'.md5(serialize($this->memoArgs($args)));
        if (isset($this->active[$key])) {
            $to !== null && $this->recorder->edge($to, 'calls', ['reason' => 'cycle', 'note' => 'Recursive call: already on the call path, so it is not followed again']);

            return Shape::unknown('recursive call');
        }
        $to !== null && $this->recorder->edge($to, 'calls');
        if (! isset($this->memo[$key])) {
            $this->active[$key] = 1;
            $this->recorder?->begin($key, (string) $to);
            $this->memo[$key] = $this->run($fn[0], $fn[1], $args, null, $f->depth + 1);
            $this->recorder?->end();
            unset($this->active[$key]);
        }
        $this->recorder?->replay($key);
        foreach ($this->memo[$key]['throws'] as $t) {
            $this->throwShape($t);
        }

        return Shape::union($this->memo[$key]['returns']);
    }

    /** @return array<string, array{ClassInfo, Stmt\Function_}> */
    private function globalFunctions(): array
    {
        if ($this->functions !== null) {
            return $this->functions;
        }
        $this->functions = [];
        $composer = json_decode((string) $this->reader->read($this->snapshot, SnapshotPaths::join($this->root, 'composer.json')), true);
        foreach ((array) ($composer['autoload']['files'] ?? []) as $file) {
            $path = SnapshotPaths::normalize(SnapshotPaths::join($this->root, (string) $file));
            $code = $path === null ? null : $this->reader->read($this->snapshot, $path);
            if ($code === null || strlen($code) > 1048576) {
                continue;
            }
            $parsed = ParsedFile::parse($path, mb_scrub($code, 'UTF-8'), $this->root);
            $context = new ClassInfo('functions:'.$path, $parsed, new Stmt\Class_('Functions'), null);
            foreach ((new NodeFinder)->findInstanceOf($parsed->stmts, Stmt\Function_::class) as $function) {
                $this->functions[strtolower($function->name->toString())] ??= [$context, $function];
            }
        }

        return $this->functions;
    }

    private function stringFn(string $name, array $args): array
    {
        $values = array_map(fn ($a) => Shape::toPhp($a), array_values($args));
        $literal = $values !== [] && array_filter($values, fn ($v) => ! is_scalar($v)) === [];

        if ($literal && in_array($name, ['strtolower', 'strtoupper', 'ucfirst', 'ucwords', 'lcfirst', 'trim', 'ltrim', 'rtrim', 'str_replace', 'sprintf', 'implode', 'substr'], true)) {
            try {
                // pure string builtins on literals the code states (never on uploaded code)
                $result = $name(...$values);

                return is_string($result) ? Shape::literal($result) : Shape::string();
            } catch (\Throwable) {
            }
        }

        return Shape::string();
    }

    private function countFn(string $name, array $args): array
    {
        $first = $args[0] ?? $args[array_key_first($args) ?? 0] ?? null;
        if (in_array($name, ['count', 'sizeof'], true) && $first !== null) {
            if ($first['k'] === 'object' && $first['optional'] === []) {
                return Shape::literal(count($first['props']));
            }
            if ($first['k'] === 'list' && isset($first['values'])) {
                return Shape::literal(count($first['values']));
            }
        }

        return in_array($name, ['strpos', 'stripos', 'array_search'], true) ? Shape::scalar(['boolean', 'integer']) : Shape::int();
    }

    private function typeCheck(string $name, array $s): array
    {
        $kinds = $s['k'] === 'union' ? $s['of'] : [$s];
        $results = [];
        foreach ($kinds as $k) {
            $types = $k['k'] === 'scalar' ? $k['types'] : [match ($k['k']) {
                'object', 'list', 'map' => 'array', 'instance', 'model', 'collection', 'response', 'exception', 'paginator', 'closure' => 'object', default => '?',
            }];
            foreach ($types as $t) {
                $results[] = $t === '?' ? null : match ($name) {
                    'is_array' => $t === 'array', 'is_string' => $t === 'string', 'is_int', 'is_integer' => $t === 'integer',
                    'is_numeric' => in_array($t, ['integer', 'number'], true) ? true : ($t === 'string' ? null : false),
                    'is_bool' => $t === 'boolean', 'is_null' => $t === 'null', 'is_float' => $t === 'number', 'is_object' => $t === 'object',
                    default => null,
                };
            }
        }
        $unique = array_unique(array_map(fn ($r) => var_export($r, true), $results));

        return count($unique) === 1 && $unique[0] !== 'NULL' ? Shape::literal($unique[0] === 'true') : Shape::bool();
    }

    private function inArray(?array $needle, ?array $haystack): array
    {
        $n = $needle === null ? new Unresolved : Shape::toPhp($needle);
        $h = $haystack === null ? new Unresolved : Shape::toPhp($haystack);

        return is_array($h) && ! $n instanceof Unresolved ? Shape::literal(in_array($n, $h, false)) : Shape::bool();
    }

    private function keyExists(?array $key, ?array $array): array
    {
        $k = $key === null ? new Unresolved : Shape::toPhp($key);
        if ($array !== null && $array['k'] === 'object' && (is_string($k) || is_int($k))) {
            return in_array($k, $array['optional'], true) ? Shape::bool() : Shape::literal(array_key_exists($k, $array['props']));
        }

        return Shape::bool();
    }

    /** @param array<int|string, array<string, mixed>> $args */
    private function arrayMerge(array $args): array
    {
        $props = [];
        $optional = [];
        $list = [];
        foreach ($args as $arg) {
            if ($arg['k'] === 'object') {
                if (array_is_list($arg['props']) && $arg['props'] !== []) {
                    array_push($list, ...$arg['props']);
                } else {
                    $props = array_merge($props, $arg['props']);
                    $optional = array_merge($optional, $arg['optional']);
                }
            } elseif ($arg['k'] === 'list') {
                array_push($list, ...($arg['values'] ?? [$arg['item']]));
            } elseif ($arg['k'] === 'map') {
                return $arg;
            } else {
                return Shape::unknown('array_merge of a computed value');
            }
        }

        return $props === [] ? ($list === [] ? Shape::emptyList() : Shape::list(Shape::union($list))) : Shape::object($props, $optional);
    }

    private function arrayValues(?array $s): array
    {
        return match ($s['k'] ?? null) {
            'object' => $s['props'] === [] ? Shape::emptyList() : ['k' => 'list', 'item' => Shape::union(array_values($s['props'])), 'values' => array_values($s['props'])],
            'list' => $s,
            'map' => Shape::list($s['value']),
            default => Shape::list(Shape::unknown('values of a computed array')),
        };
    }

    private function arrayMap(?array $fn, ?array $array, Frame $f): array
    {
        if ($fn === null || $array === null || $fn['k'] !== 'closure') {
            return Shape::list(Shape::unknown('array_map callback'));
        }

        return Shape::list($this->branch(fn () => $this->callClosure($fn, [$this->itemOf($array)], $f->depth)));
    }

    private function listish(?array $s): array
    {
        return match ($s['k'] ?? null) {
            'object' => $s,
            'list' => isset($s['values']) && $s['values'] !== [] ? Shape::list($s['item']) : $s,
            'map', 'collection' => $s,
            default => Shape::list(Shape::unknown('filtered values')),
        };
    }

    private function dataGet(?array $target, mixed $path, ?array $default): array
    {
        if ($target === null || ! is_string($path)) {
            return Shape::unknown('data_get');
        }
        $value = $target;
        foreach (explode('.', $path) as $segment) {
            $value = $this->dim($value, Shape::literal($segment));
        }

        return $default === null ? $value : Shape::union([self::withoutNull($value), $default]);
    }

    private function jsonDecode(?array $s): array
    {
        $raw = $s === null ? null : Shape::toPhp($s);
        if (is_string($raw) && ($decoded = json_decode($raw, true)) !== null) {
            return Shape::literal($decoded);
        }

        return Shape::unknown('JSON decoded from stored data');
    }

    private function callClosure(array $closure, array $args, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return Shape::unknown('call chain too deep');
        }
        $result = $this->run($closure['class'], $closure['node'], array_values($args), $closure['self'], $depth + 1, vars: $closure['vars']);
        foreach ($result['throws'] as $t) {
            $this->throwShape($t);
        }

        return Shape::union($result['returns']);
    }

    private function bindParams(array $params, array $args, Frame $frame): void
    {
        $positional = array_values(array_filter($args, fn ($k) => is_int($k), ARRAY_FILTER_USE_KEY));
        foreach ($params as $i => $param) {
            if (! $param->var instanceof Expr\Variable || ! is_string($param->var->name)) {
                continue;
            }
            $name = $param->var->name;
            $value = $args[$name] ?? $positional[$i] ?? ($param->default !== null ? $this->eval($param->default, $frame) : null);
            if ($param->variadic) {
                $value = Shape::list(Shape::union(array_slice($positional, $i) ?: [Shape::unknown('variadic')]));
            }
            if ($value === null) {
                $value = $this->typedParam($param);
            }
            $frame->vars[$name] = $value;
            // promoted constructor property
            if ($param->flags !== 0 && $frame->self !== null) {
                $frame->self['props'][$name] = $value;
            }
        }
    }

    private function typedParam(Node\Param $param): array
    {
        $type = $param->type instanceof Node\Name ? ClassInfo::name($param->type) : ($param->type instanceof Node\Identifier ? $param->type->toString() : null);

        return match (true) {
            $type === null => Shape::unknown('untyped parameter'),
            in_array(strtolower($type), ['string'], true) => Shape::string(),
            in_array(strtolower($type), ['int'], true) => Shape::int(),
            in_array(strtolower($type), ['bool'], true) => Shape::bool(),
            in_array(strtolower($type), ['float'], true) => Shape::scalar(['number']),
            in_array(strtolower($type), ['array'], true) => ['k' => 'map', 'value' => Shape::unknown('array parameter')],
            $this->isA($type, 'Illuminate\Http\Request') || str_ends_with($type, 'Request') => ['k' => 'request', 'class' => $type],
            $this->isA($type, self::MODEL) => ['k' => 'model', 'class' => $type, 'with' => []],
            default => ['k' => 'instance', 'class' => $type, 'props' => []],
        };
    }

    private function methodCall(Expr\MethodCall|Expr\NullsafeMethodCall $e, Frame $f): array
    {
        if (! $e->name instanceof Node\Identifier) {
            $computed = Shape::toPhp($this->eval($e->name, $f));
            if (! is_string($computed)) {
                $this->recorder?->unresolved('dynamic_method', 'Dynamic method name: the method is chosen at runtime', $this->source($e));

                return Shape::unknown('method call with a computed name');
            }
            $e = $e instanceof Expr\NullsafeMethodCall ? new Expr\NullsafeMethodCall($e->var, new Node\Identifier($computed), $e->args) : new Expr\MethodCall($e->var, new Node\Identifier($computed), $e->args);
        }
        $object = $this->eval($e->var, $f);
        $name = $e->name instanceof Node\Identifier ? $e->name->toString() : '';
        $args = $this->args($e->args, $f);
        $nullsafe = $e instanceof Expr\NullsafeMethodCall && $this->mayBeNull($object);
        $this->recorder !== null && $this->recordMethod($name, $args);

        $result = $this->branch(fn () => $this->callOn($object, $name, $args, $f), $nullsafe); // ?-> runs only when not null

        return $nullsafe ? Shape::nullable($result) : $result;
    }

    /** @param array<int|string, array<string, mixed>> $args */
    private function callOn(array $object, string $name, array $args, Frame $f): array
    {
        $m = strtolower($name);
        $a = fn (int $i, ?string $named = null) => $args[$named] ?? $args[$i] ?? null;

        if ($object['k'] === 'never') {
            return $object;
        }
        if ($object['k'] === 'union') {
            $alts = array_values(array_filter($object['of'], fn ($o) => ! ($o['k'] === 'scalar' && $o['types'] === ['null'])));

            return Shape::union(array_map(fn ($o) => $this->callOn($o, $name, $args, $f), $alts ?: [Shape::null()]));
        }

        return match ($object['k']) {
            'factory' => match ($m) {
                'json' => $this->response($a(0, 'data') ?? Shape::emptyList(), $a(1, 'status') ?? Shape::literal(200), $a(2, 'headers')),
                'nocontent' => $this->response(Shape::literal(''), $a(0) ?? Shape::literal(204)),
                'make' => $this->response($a(0) ?? Shape::literal(''), $a(1) ?? Shape::literal(200)),
                default => $this->response(Shape::unknown("response()->{$name}()"), Shape::literal(200)),
            },
            'response' => match ($m) {
                'setstatuscode' => ['k' => 'response', 'status' => $a(0) ?? $object['status'], 'body' => $object['body'], 'origin' => $object['origin'] ?? null],
                'getdata' => $this->json($object['body']),
                'getstatuscode', 'status' => $object['status'],
                'setdata' => ['k' => 'response', 'status' => $object['status'], 'body' => $a(0) ?? $object['body'], 'origin' => $object['origin'] ?? null],
                'getcontent' => Shape::string(),
                default => $object, // header(), withHeaders(), setEncodingOptions(), cookie() …
            },
            'collection' => $this->collectionCall($object, $m, $args, $f),
            'paginator' => match ($m) {
                'items', 'getcollection' => $m === 'items' ? Shape::list($object['item']) : ['k' => 'collection', 'item' => $object['item']],
                'total', 'currentpage', 'lastpage', 'perpage', 'count', 'firstitem', 'lastitem' => in_array($m, ['firstitem', 'lastitem'], true) ? Shape::scalar(['integer', 'null']) : Shape::int(),
                'through', 'map', 'transform' => ['k' => 'paginator', 'item' => ($c = $a(0)) && $c['k'] === 'closure' ? $this->branch(fn () => $this->callClosure($c, [$object['item']], $f->depth)) : $object['item']],
                'hasmorepages', 'onfirstpage', 'isempty', 'isnotempty' => Shape::bool(),
                'nextpageurl', 'previouspageurl' => Shape::scalar(['null', 'string']),
                'toarray' => $this->json($object),
                default => $object,
            },
            'builder' => $this->builderCall($object, $m, $args, $f),
            'model' => $this->modelCall($object, $name, $args, $f),
            'request' => $this->requestCall($m, $args, $object, $f),
            'guard' => match ($m) {
                'user', 'authenticate' => Shape::nullable(['k' => 'model', 'class' => $this->guardModel($object['name']), 'with' => []]),
                'id' => Shape::scalar(['integer', 'null']),
                'check', 'guest', 'attempt', 'validate', 'once' => Shape::bool(),
                'guard' => ['k' => 'guard', 'name' => is_string($g = Shape::toPhp($a(0) ?? Shape::null())) ? $g : null],
                default => Shape::unknown("auth()->{$name}()"),
            },
            'validator' => match ($m) {
                'fails', 'passes' => Shape::bool(),
                'errors', 'getmessagebag', 'messages' => ['k' => 'instance', 'class' => 'Illuminate\Support\MessageBag', 'props' => [], 'messages' => $object['messages'] ?? ['k' => 'map', 'value' => Shape::list(Shape::string())]],
                'validated', 'valid' => $object['data'] ?? ['k' => 'map', 'value' => Shape::unknown('validated input')],
                default => Shape::unknown("validator {$name}()"),
            },
            'closure' => in_array($m, ['call', '__invoke'], true) ? $this->callClosure($object, $args, $f->depth) : Shape::unknown('closure method'),
            'storage' => match ($m) {
                'disk', 'drive', 'build' => ['k' => 'storage'],
                'url', 'temporaryurl', 'path', 'get', 'put', 'putfile', 'putfileas', 'mimetype' => in_array($m, ['put'], true) ? Shape::bool() : ($m === 'get' ? Shape::scalar(['null', 'string']) : Shape::string()),
                'exists', 'missing', 'delete', 'copy', 'move', 'makedirectory', 'deletedirectory' => Shape::bool(),
                'size', 'lastmodified' => Shape::int(),
                'files', 'allfiles', 'directories', 'alldirectories' => Shape::list(Shape::string()),
                default => Shape::unknown("Storage {$name}()"),
            },
            // A cache hands back what its callback built (or built earlier): the callback's value.
            'cache' => match ($m) {
                'remember', 'flexible' => ($c = $a(2, 'callback')) !== null && $c['k'] === 'closure' ? $this->callClosure($c, [], $f->depth) : Shape::unknown('cached value'),
                'rememberforever', 'sear' => ($c = $a(1, 'callback')) !== null && $c['k'] === 'closure' ? $this->callClosure($c, [], $f->depth) : Shape::unknown('cached value'),
                'store', 'tags', 'driver', 'memo' => ['k' => 'cache'],
                'has', 'put', 'forever', 'forget', 'flush', 'add', 'missing' => Shape::bool(),
                default => Shape::unknown("cache {$name}() (whatever was stored earlier)"),
            },
            'container' => match (true) {
                in_array($m, ['make', 'get', 'makewith'], true) => $this->resolveClass(Shape::toPhp($a(0) ?? Shape::null()), $f),
                // app()->call($closure) / app()->call([$object, 'method'])
                $m === 'call' && ($c = $a(0)) !== null && $c['k'] === 'closure' => $this->callClosure($c, [], $f->depth),
                $m === 'call' && ($c = $a(0)) !== null && $c['k'] === 'list' && count($c['values'] ?? []) === 2 && is_string($method = Shape::toPhp($c['values'][1])) => $this->callOn($c['values'][0], $method, [], $f),
                default => Shape::unknown("app()->{$name}()"),
            },
            'instance' => $this->instanceCall($object, $name, $args, $f),
            'exception' => match ($m) {
                'getmessage' => $object['message'],
                'getstatuscode' => $object['status'] === null ? Shape::int() : Shape::literal($object['status']),
                'getcode' => Shape::int(),
                'errors' => $object['errors'] ?? ['k' => 'map', 'value' => Shape::list(Shape::string())],
                'getresponse' => $object['response'] ?? Shape::unknown('exception response'),
                'getmodel' => Shape::string(),
                'getheaders' => ['k' => 'map', 'value' => Shape::string()],
                default => Shape::unknown("exception {$name}()"),
            },
            // Calling ->map()/->get()/->all() means it is a Collection at runtime (collect(), a relation, a query result).
            'object', 'list', 'map' => $this->collectionCall(['k' => 'collection', 'item' => $this->itemOf($object)], $m, $args, $f),
            'resource_collection' => in_array($m, ['toarray', 'resolve', 'jsonserialize', 'all'], true) ? $this->json($object)
                : (in_array($m, ['response', 'toresponse'], true) ? $this->response($this->resourceDocument($object, $f->depth), Shape::literal(200)) : $object),
            'unknown' => $object,
            default => Shape::unknown("{$name}() on a {$object['k']}"),
        };
    }

    private function guardModel(?string $guard): string
    {
        $provider = $this->config('auth.guards.'.($guard ?? 'web').'.provider');
        $providerName = Shape::toPhp($provider);
        $model = is_string($providerName) ? Shape::toPhp($this->config("auth.providers.{$providerName}.model")) : null;

        return is_string($model) ? $model : 'App\Models\User';
    }

    private function requestCall(string $m, array $args, array $request = [], ?Frame $f = null): array
    {
        $key = isset($args[0]) ? Shape::toPhp($args[0]) : null;
        // A method the app's FormRequest defines (payload(), filters(), …): follow it.
        if (isset($request['class']) && $f !== null && ($info = $this->index->find($request['class'])) !== null
            && ($found = $this->index->method($info, $m)) !== null && $found[0]->fqcn !== 'Illuminate\Http\Request') {
            return $this->callMethod($found[0], $found[1], $args, $request, $f->depth);
        }

        return match ($m) {
            'input', 'get', 'query', 'post', 'string', 'str' => is_string($key) ? Shape::union([$this->input($key), ...(isset($args[1]) ? [$args[1]] : [])]) : ['k' => 'map', 'value' => Shape::unknown('request input')],
            'integer', 'float' => $m === 'integer' ? Shape::int() : Shape::scalar(['number']),
            // The documented request is a JSON API call (Accept: application/json) to this endpoint's path.
            'expectsjson', 'wantsjson', 'acceptsjson' => Shape::literal(true),
            'is' => $this->requestIs($args),
            'boolean', 'has', 'filled', 'missing', 'hasfile', 'hasheader', 'ajax', 'isjson', 'secure', 'routeis' => Shape::bool(),
            'all', 'validated', 'only', 'except', 'safe' => ['k' => 'map', 'value' => Shape::unknown('request input')],
            'user' => Shape::nullable(['k' => 'model', 'class' => $this->guardModel(null), 'with' => []]),
            'header', 'bearertoken', 'ip', 'url', 'fullurl', 'path', 'method', 'getmethod', 'route', 'cookie', 'getlocale', 'segment', 'useragent', 'getclientip' => Shape::scalar(['null', 'string']),
            'file' => Shape::nullable(['k' => 'instance', 'class' => 'Illuminate\Http\UploadedFile', 'props' => []]),
            'merge', 'replace', 'mergeifmissing' => $this->mergeIntoRequest($args[0] ?? null),
            'offsetset' => Shape::null(),
            default => Shape::unknown("\$request->{$m}()"),
        };
    }

    /** @param array<int|string, array<string, mixed>> $args */
    private function requestIs(array $args): array
    {
        if ($this->requestPath === '') {
            return Shape::bool();
        }
        foreach ($args as $arg) {
            $patterns = Shape::toPhp($arg);
            foreach (is_array($patterns) ? $patterns : [$patterns] as $pattern) {
                if (! is_string($pattern)) {
                    return Shape::bool();
                }
                if (Str::is($pattern, $this->requestPath)) {
                    return Shape::literal(true);
                }
            }
        }

        return Shape::literal(false);
    }

    private function mergeIntoRequest(?array $values): array
    {
        if ($values !== null && $values['k'] === 'object') {
            foreach ($values['props'] as $k => $v) {
                $this->requestAttributes[(string) $k] = isset($this->requestAttributes[(string) $k]) ? Shape::union([$this->requestAttributes[(string) $k], $v]) : $v;
            }
        }

        return Shape::null();
    }

    /** @var array<string, array<string, mixed>> values middleware put on the request ($request['x'] = …, merge()) */
    public array $requestAttributes = [];

    /** A request value: one a middleware attached, else typed by the endpoint's validation rules. */
    private function input(string $key): array
    {
        return $this->requestAttributes[$key] ?? $this->inputs[$key] ?? Shape::scalar(['null', 'string']);
    }

    private function instanceCall(array $object, string $name, array $args, Frame $f): array
    {
        $info = $this->index->find($object['class']);
        // JsonResource helpers used inside toArray()
        if ($this->isA($object['class'], self::JSON_RESOURCE)) {
            $m = strtolower($name);
            $a = fn (int $i) => array_values($args)[$i] ?? null;
            if (in_array($m, ['when', 'unless'], true)) {
                return ['k' => 'optional', 'value' => $a(1) !== null && $a(1)['k'] === 'closure' ? $this->branch(fn () => $this->callClosure($a(1), [], $f->depth), $this->truth($a(0) ?? Shape::bool()) === null) : ($a(1) ?? Shape::null()),
                    'when' => $this->truth($a(0) ?? Shape::bool()), 'unless' => $m === 'unless', 'default' => $a(2)];
            }
            if (in_array($m, ['whenloaded', 'whennotnull', 'whenhas', 'whencounted', 'whenaggregated'], true)) {
                $relation = $a(0) === null ? null : Shape::toPhp($a(0));
                $resource = $object['props']['resource'] ?? null;
                $value = $a(1) !== null ? ($a(1)['k'] === 'closure' ? $this->branch(fn () => $this->callClosure($a(1), [], $f->depth)) : $a(1))
                    : ($m === 'whenloaded' && is_string($relation) && $resource !== null ? $this->propertyOf($resource, $relation, $f) : ($a(0) ?? Shape::null()));

                return ['k' => 'optional', 'value' => $m === 'whencounted' ? Shape::int() : $value, 'when' => null, 'unless' => false, 'default' => $a(2)];
            }
            if (in_array($m, ['mergewhen', 'mergeunless', 'merge'], true)) {
                $value = $m === 'merge' ? $a(0) : $a(1);
                $value = $value !== null && $value['k'] === 'closure' ? $this->branch(fn () => $this->callClosure($value, [], $f->depth), $m !== 'merge' && $this->truth($a(0) ?? Shape::bool()) === null) : $value;

                return ['k' => 'merge', 'value' => $value ?? Shape::object([]), 'when' => $m === 'merge' ? true : $this->truth($a(0) ?? Shape::bool())];
            }
            if (in_array($m, ['resolve', 'jsonserialize'], true) || ($m === 'toarray' && $f->self !== $object)) {
                return $this->resourceArray($object, $f->depth);
            }
            if (in_array($m, ['response', 'toresponse'], true)) {
                return $this->response($this->resourceDocument($object, $f->depth), Shape::literal(200));
            }
            if (in_array($m, ['additional', 'withresponse', 'preservekeys'], true)) {
                return $object;
            }
        }

        if ($info === null) {
            $this->recorder !== null && $this->recordOpaque($object['class'], $name);

            return $this->frameworkObjectCall($object, $name, $args);
        }
        $found = $this->index->method($info, $name);
        if ($found === null) {
            $this->recorder !== null && $this->recordOpaque($object['class'], $name, true);

            return $this->frameworkObjectCall($object, $name, $args);
        }

        return $this->callMethod($found[0], $found[1], $args, $object, $f->depth);
    }

    private function frameworkObjectCall(array $object, string $name, array $args): array
    {
        $class = strtolower($object['class']);
        if (str_ends_with($class, 'messagebag')) {
            return match (strtolower($name)) {
                'first' => Shape::string(), 'all' => Shape::list(Shape::string()),
                'toarray', 'getmessages', 'messages', 'jsonserialize' => $object['messages'] ?? ['k' => 'map', 'value' => Shape::list(Shape::string())],
                'has', 'any', 'isempty', 'isnotempty' => Shape::bool(), default => Shape::unknown("MessageBag {$name}()"),
            };
        }
        if (str_ends_with($class, 'uploadedfile')) {
            return Shape::string();
        }
        if (str_contains($class, 'carbon') || str_ends_with($class, 'datetime') || str_ends_with($class, 'datetimeimmutable')) {
            $m = strtolower($name);
            if (in_array($m, ['format', 'toformatteddatestring', 'todatestring', 'todatetimestring', 'totimestring', 'toisostring', 'tojson', 'diffforhumans', 'isoformat', 'translatedformat', 'toiso8601string', 'tostring', '__tostring', 'daylabel', 'monthname', 'dayname'], true)) {
                return Shape::string();
            }

            return in_array($m, ['timestamp', 'gettimestamp', 'diffinseconds', 'diffinminutes', 'diffindays', 'diffinhours', 'year', 'month', 'day', 'hour', 'minute', 'dayofweek'], true) ? Shape::int()
                : (str_starts_with(strtolower($name), 'is') || str_starts_with(strtolower($name), 'gt') || str_starts_with(strtolower($name), 'lt') ? Shape::bool() : ['k' => 'instance', 'class' => 'Carbon\Carbon', 'props' => []]);
        }

        return Shape::unknown(class_basename($object['class'])."::{$name}() is not in the snapshot");
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $args
     * @param  array<string, mixed>|null  $self
     */
    private function callMethod(ClassInfo $owner, Stmt\ClassMethod $method, array $args, ?array $self, int $depth, string $edge = 'calls'): array
    {
        $to = null;
        $declared = [];
        if ($this->recorder !== null) {
            $to = $this->methodNode($owner, $method->name->toString());
            // No call site (a resource serialized on the way out): cite the method itself.
            $declared = ['fallback' => [$owner->file->path, $owner->sha256, $to, $method->getStartLine(), $method->getStartLine()]];
        }
        if ($depth >= self::MAX_DEPTH) {
            $to !== null && $this->recorder->edge($to, $edge, $declared + ['reason' => 'bound_depth', 'note' => 'Bound reached: call depth '.self::MAX_DEPTH.'; not followed further']);

            return Shape::unknown('call chain too deep ('.$owner->symbol($method->name->toString()).')');
        }
        $key = $owner->fqcn.'::'.$method->name->toString().'#'.md5(serialize([$this->memoArgs($args), $this->memoArgs([$self ?? []])]));
        if (isset($this->active[$key])) {
            $to !== null && $this->recorder->edge($to, $edge, $declared + ['reason' => 'cycle', 'note' => 'Recursive call: already on the call path, so it is not followed again']);

            return Shape::unknown('recursive call');
        }
        if ($to !== null) {
            $method->stmts === null
                ? $this->recorder->unresolved('abstract_method', "Polymorphic call: {$owner->fqcn}::{$method->name->toString()}() is abstract; the class that runs is chosen at runtime")
                : $this->recorder->edge($to, $edge, $declared);
        }
        if (! isset($this->memo[$key])) {
            $this->active[$key] = 1;
            $this->recorder?->begin($key, (string) $to);
            $this->memo[$key] = $this->run($owner, $method, $args, $self, $depth + 1);
            $this->recorder?->end();
            unset($this->active[$key]);
        }
        $this->recorder?->replay($key);
        foreach ($this->memo[$key]['throws'] as $t) {
            $this->throwShape($t);
        }

        return Shape::union($this->memo[$key]['returns']);
    }

    private function memoArgs(array $args): array
    {
        return array_map(fn ($a) => array_diff_key($a, ['node' => 1, 'vars' => 1, 'class' => 1]) + ['c' => isset($a['class']) && is_string($a['class']) ? $a['class'] : null], $args);
    }

    private function staticCall(Expr\StaticCall $e, Frame $f): array
    {
        if ($e->name instanceof Node\Identifier && $e->class instanceof Expr) {
            $class = Shape::toPhp($this->eval($e->class, $f));
            if (is_string($class)) {
                return $this->staticCall(new Expr\StaticCall(new Node\Name\FullyQualified(ltrim($class, '\\')), $e->name, $e->args), $f);
            }
            $object = $this->eval($e->class, $f);
            in_array($object['k'], ['instance', 'model'], true)
                || $this->recorder?->unresolved('dynamic_class', 'Dynamic class: the class is chosen at runtime', $this->source($e));

            return in_array($object['k'], ['instance', 'model'], true) ? $this->callOn($object, $e->name->toString(), $this->args($e->args, $f), $f) : $this->debugComputed($f, $object);
        }
        if (! $e->name instanceof Node\Identifier) {
            $name = Shape::toPhp($this->eval($e->name, $f));
            is_string($name) || $this->recorder?->unresolved('dynamic_method', 'Dynamic method name: the method is chosen at runtime', $this->source($e));

            return is_string($name) ? $this->staticCall(new Expr\StaticCall($e->class, new Node\Identifier($name), $e->args), $f)
                : Shape::unknown('static call with a computed method name');
        }
        $name = $e->name->toString();
        $m = strtolower($name);
        $args = $this->args($e->args, $f);
        $raw = strtolower($e->class->toString());
        $class = $this->className($e->class, $f);
        $base = strtolower(class_basename($class));
        $a = fn (int $i, ?string $named = null) => $args[$named] ?? $args[$i] ?? null;
        $this->recorder !== null && $this->recordStatic($class, $base, $m, $args);

        // parent::toArray() in a JsonResource returns the wrapped resource as an array
        if ($raw === 'parent' && $m === 'toarray' && $f->self !== null && $this->isA($f->self['class'] ?? '', self::JSON_RESOURCE)
            && ($this->index->find($class) === null || strcasecmp($class, self::JSON_RESOURCE) === 0)) {
            return $this->json($f->self['props']['resource'] ?? Shape::unknown('resource'));
        }

        if (in_array($raw, ['self', 'static', 'parent'], true) || $this->index->find($class) !== null) {
            $info = $this->index->find($class);
            if ($info !== null) {
                if ($this->isA($class, self::JSON_RESOURCE) && in_array($m, ['make', 'collection'], true)) {
                    return $m === 'make' ? $this->newResource($class, $args)
                        : ['k' => 'resource_collection', 'class' => $class, 'source' => $a(0) ?? Shape::emptyList()];
                }
                if ($this->isA($class, self::MODEL) && ($found = $this->index->method($info, $name)) === null) {
                    return $this->builderCall(['k' => 'builder', 'model' => $class, 'with' => []], $m, $args, $f);
                }
                $found ??= $this->index->method($info, $name);
                if ($found !== null) {
                    // static call: $this stays the caller's when calling up the hierarchy (parent::/self::)
                    $self = in_array($raw, ['self', 'static', 'parent'], true) ? $f->self : null;

                    return $this->callMethod($found[0], $found[1], $args, $self, $f->depth);
                }
            }
        }

        return match (true) {
            $base === 'response' && in_array($m, ['json', 'make', 'nocontent'], true) => $this->callOn(['k' => 'factory'], $name, $args, $f),
            $base === 'jsonresponse' => $this->response($a(0) ?? Shape::emptyList(), $a(1) ?? Shape::literal(200)),
            $base === 'str' => in_array($m, ['contains', 'startswith', 'endswith', 'is', 'isuuid', 'isjson', 'isurl', 'isascii'], true) ? Shape::bool()
                : (in_array($m, ['length', 'wordcount', 'position'], true) ? Shape::int() : $this->stringFn($m === 'lower' ? 'strtolower' : ($m === 'upper' ? 'strtoupper' : ($m === 'ucfirst' ? 'ucfirst' : 'x')), array_values($args))),
            in_array($base, ['carbon', 'date', 'carbonimmutable'], true) => ['k' => 'instance', 'class' => 'Carbon\Carbon', 'props' => []],
            $base === 'validator' && $m === 'make' => ['k' => 'validator', 'data' => $a(0)],
            $base === 'auth' => $this->callOn(['k' => 'guard', 'name' => null], $name, $args, $f),
            $base === 'cache' => $this->callOn(['k' => 'cache'], $name, $args, $f),
            $base === 'db' && $m === 'table' => ['k' => 'builder', 'model' => null, 'table' => Shape::toPhp($a(0) ?? Shape::null()), 'with' => []],
            $base === 'db' && in_array($m, ['transaction'], true) => ($c = $a(0)) !== null && $c['k'] === 'closure' ? $this->callClosure($c, [], $f->depth) : Shape::null(),
            $base === 'storage' => $this->callOn(['k' => 'storage'], $name, $args, $f),
            $base === 'ratelimiter' => match ($m) {
                'availablein', 'hit', 'increment', 'decrement', 'attempts', 'remaining', 'retriesleft' => Shape::int(),
                'toomanyattempts', 'attempt' => Shape::bool(),
                default => Shape::null(), // clear, resetAttempts, for
            },
            in_array($base, ['log', 'event', 'mail', 'notification', 'queue', 'bus', 'session', 'artisan'], true) => Shape::null(),
            $base === 'arr' && $m === 'get' => $this->dataGet($a(0), Shape::toPhp($a(1) ?? Shape::null()), $a(2)),
            default => Shape::unknown("{$class}::{$name}(): the class {$this->index->missingReason($class)}"),
        };
    }

    private function newObject(Expr\New_ $e, Frame $f): array
    {
        if (! $e->class instanceof Node\Name) {
            $this->recorder?->unresolved('dynamic_class', 'Dynamic class: new with a class chosen at runtime', $this->source($e));

            return Shape::unknown('dynamic class');
        }
        $class = $this->className($e->class, $f);
        $args = $this->args($e->args, $f);

        if ($this->isA($class, self::JSON_RESOURCE)) {
            return $this->isA($class, self::RESOURCE_COLLECTION) && ! $this->isA($class, self::JSON_RESOURCE.'Single')
                ? ['k' => 'resource_collection', 'class' => $class, 'source' => $args[0] ?? Shape::emptyList(), 'custom' => true]
                : $this->newResource($class, $args);
        }
        if ($this->isA($class, 'Throwable') || str_ends_with(strtolower($class), 'exception')) {
            return $this->newException($class, $args, $f);
        }
        if (in_array(strtolower(class_basename($class)), ['jsonresponse'], true)) {
            return $this->response($args[0] ?? Shape::emptyList(), $args[1] ?? Shape::literal(200));
        }
        if ($this->isA($class, self::MODEL)) {
            return ['k' => 'model', 'class' => $class, 'with' => []];
        }
        $short = strtolower(class_basename($class));
        if (in_array($short, ['collection', 'lazycollection'], true) && str_starts_with(strtolower($class), 'illuminate\\')) {
            return ['k' => 'collection', 'item' => $this->itemOf($args[0] ?? $args['items'] ?? Shape::emptyList())];
        }
        if (in_array($short, ['lengthawarepaginator', 'paginator', 'cursorpaginator'], true) && str_starts_with(strtolower($class), 'illuminate\\')) {
            return ['k' => 'paginator', 'item' => $this->itemOf($args[0] ?? $args['items'] ?? Shape::emptyList())];
        }

        $object = ['k' => 'instance', 'class' => $class, 'props' => []];
        $info = $this->index->find($class);
        $ctor = $info === null ? null : $this->index->method($info, '__construct');
        if ($ctor !== null && $f->depth < self::MAX_DEPTH) {
            $frame = new Frame($ctor[0], [], $object, $f->depth + 1);
            $this->bindParams($ctor[1]->params, $args, $frame);
            $this->recorder?->begin(null, $class.'::__construct'); // constructor wiring is not part of the workflow
            $this->exec($ctor[1]->stmts ?? [], $frame);
            $this->recorder?->end();
            $object = $frame->self ?? $object;
        }

        return $object;
    }

    private function newResource(string $class, array $args): array
    {
        return ['k' => 'instance', 'class' => $class, 'props' => ['resource' => $args[0] ?? $args['resource'] ?? Shape::null()]];
    }

    private function newException(string $class, array $args, Frame $f): array
    {
        $info = $this->index->find($class);
        $exception = ['k' => 'exception', 'class' => $class, 'status' => null, 'message' => Shape::toPhp($args[0] ?? Shape::null()) instanceof Unresolved ? Shape::string() : ($args[0] ?? Shape::literal('')), 'args' => $args];
        $short = strtolower(class_basename($class));
        if ($short === 'httpexception' || $short === 'httpresponseexception') {
            $exception['status'] = $short === 'httpexception' && is_int($s = Shape::toPhp($args[0] ?? Shape::null())) ? $s : null;
            $exception['message'] = $args[1] ?? Shape::literal('');
            $short === 'httpresponseexception' && $exception['response'] = $args[0] ?? Shape::unknown('response');
        }
        // An app exception's constructor may set its message/status: read it.
        if ($info !== null && ($ctor = $this->index->method($info, '__construct')) !== null && $f->depth < self::MAX_DEPTH) {
            $frame = new Frame($ctor[0], [], ['k' => 'instance', 'class' => $class, 'props' => []], $f->depth + 1);
            $this->bindParams($ctor[1]->params, $args, $frame);
            $this->recorder?->begin(null, $class.'::__construct');
            $this->exec($ctor[1]->stmts ?? [], $frame);
            $this->recorder?->end();
            $exception['props'] = $frame->self['props'] ?? [];
        }

        return $exception;
    }

    private function abort(array $status, array $message): array
    {
        $code = Shape::toPhp($status);
        $exception = ['k' => 'exception', 'class' => $code === 404 ? 'Symfony\Component\HttpKernel\Exception\NotFoundHttpException' : 'Symfony\Component\HttpKernel\Exception\HttpException',
            'status' => is_int($code) ? $code : null, 'message' => $message];
        $this->recorder !== null && $this->recordThrow($exception + ['abort' => true]);
        $this->throwShape($exception);

        return Shape::unknown('never: aborts');
    }

    private function abortSide(array $status, array $message): array
    {
        $this->abort($status, $message);

        return Shape::null();
    }

    // ───────────────────────────── responses & resources ─────────────────────────────

    public function response(array $body, array $status, ?array $headers = null): array
    {
        return ['k' => 'response', 'status' => $status, 'body' => $body];
    }

    /** JSON of an instance: resources run toArray(); other objects their toArray()/jsonSerialize(). */
    private function instanceJson(array $s, int $depth): array
    {
        if ($this->isA($s['class'], self::JSON_RESOURCE)) {
            return $this->resourceArray($s, $depth);
        }
        $info = $this->index->find($s['class']);
        foreach (['toArray', 'jsonSerialize'] as $method) {
            if ($info !== null && ($found = $this->index->method($info, $method)) !== null) {
                return $this->json($this->callMethod($found[0], $found[1], [], $s, $depth), $depth + 1);
            }
        }
        if (str_contains(strtolower($s['class']), 'carbon')) {
            return Shape::string(); // dates serialize as ISO-8601 strings
        }

        return $s['props'] === [] ? Shape::unknown('object of '.class_basename($s['class'])) : Shape::object(array_map(fn ($v) => $this->json($v, $depth + 1), $s['props']));
    }

    /** A resource's toArray() as JSON, with when()/whenLoaded()/mergeWhen() applied. */
    public function resourceArray(array $resource, int $depth): array
    {
        $inner = $resource['props']['resource'] ?? Shape::null();
        if ($inner['k'] === 'scalar' && $inner['types'] === ['null']) {
            return Shape::null(); // a resource around null serializes as null
        }
        $info = $this->index->find($resource['class']);
        $found = $info === null ? null : $this->index->method($info, 'toArray');
        if ($found === null || ! $this->index->find($found[0]->fqcn)) {
            return $this->json($inner, $depth + 1);
        }

        $value = $this->applyConditionals($this->callMethod($found[0], $found[1], [['k' => 'request']], $resource, $depth, 'responds_with'));
        $this->recorder !== null && $this->recordFields($found[0], $found[1], $value);

        return $this->json($value, $depth + 1);
    }

    /** Resolve the markers JsonResource::filter() handles: optional values and merges. */
    private function applyConditionals(array $s): array
    {
        if ($s['k'] === 'union') {
            return Shape::union(array_map(fn ($x) => $this->applyConditionals($x), $s['of']));
        }
        if ($s['k'] !== 'object') {
            return $s;
        }
        $props = [];
        $optional = $s['optional'];
        foreach ($s['props'] as $key => $value) {
            if (($value['k'] ?? null) === 'optional') {
                $when = $value['unless'] ? ($value['when'] === null ? null : ! $value['when']) : $value['when'];
                if ($when === false) {
                    if ($value['default'] !== null) {
                        $props[$key] = $value['default'];
                    }

                    continue;
                }
                $props[$key] = $value['value'];
                $when === null && $optional[] = (string) $key;

                continue;
            }
            if (($value['k'] ?? null) === 'merge' && is_int($key)) {
                if ($value['when'] !== false && $value['value']['k'] === 'object') {
                    foreach ($value['value']['props'] as $k => $v) {
                        $props[$k] = $v;
                        $value['when'] === null && $optional[] = (string) $k;
                    }
                }

                continue;
            }
            $props[$key] = ($value['k'] ?? null) === 'object' ? $this->applyConditionals($value) : $value;
        }

        return Shape::object($props, $optional);
    }

    /**
     * What Laravel's router sends for a value a controller returned.
     *
     * @return array{status: array<string, mixed>, body: array<string, mixed>, kind: string}
     */
    public function toResponse(array $value, int $depth = 0): array
    {
        return match ($value['k']) {
            'response' => ['status' => $value['status'], 'body' => $this->json($value['body'], $depth), 'kind' => 'json'],
            'instance' => $this->isA($value['class'], self::JSON_RESOURCE)
                ? ['status' => Shape::literal(200), 'body' => $this->resourceDocument($value, $depth, ! $this->withoutWrapping()), 'kind' => 'json']
                : ['status' => Shape::literal(200), 'body' => $this->json($value, $depth), 'kind' => 'json'],
            'resource_collection' => ['status' => Shape::literal(200), 'body' => $this->resourceDocument($value, $depth, ! $this->withoutWrapping()), 'kind' => 'json'],
            'object', 'list', 'map', 'model', 'collection', 'paginator' => ['status' => Shape::literal(200), 'body' => $this->json($value, $depth), 'kind' => 'json'],
            'scalar' => ['status' => Shape::literal(200), 'body' => $value, 'kind' => $value['types'] === ['null'] ? 'empty' : 'text'],
            default => ['status' => Shape::literal(200), 'body' => $this->json($value, $depth), 'kind' => 'json'],
        };
    }

    private ?bool $withoutWrapping = null;

    /** JsonResource::withoutWrapping() called in a service provider turns off the "data" wrapper. */
    private function withoutWrapping(): bool
    {
        if ($this->withoutWrapping === null) {
            $this->withoutWrapping = false;
            foreach ($this->reader->paths($this->snapshot, SnapshotPaths::join($this->root, 'app/Providers'), like: '%.php') as $path) {
                if (str_contains((string) $this->reader->read($this->snapshot, $path), 'withoutWrapping()')) {
                    $this->withoutWrapping = true;
                }
            }
        }

        return $this->withoutWrapping;
    }

    /** The document Laravel sends when a resource is returned directly: {"data": …} unless wrapping is off. */
    public function resourceDocument(array $value, int $depth, bool $wrap = true): array
    {
        if ($value['k'] === 'resource_collection') {
            $source = $value['source'];
            $item = ['k' => 'instance', 'class' => $value['class'], 'props' => ['resource' => $this->itemOf($source)]];
            $custom = $value['custom'] ?? false;
            $items = $custom ? $this->json(['k' => 'collection', 'item' => $this->itemOf($source)], $depth) : Shape::list($this->resourceArray($item, $depth));
            if ($source['k'] === 'paginator') {
                return Shape::object(['data' => $items, 'links' => self::resourceLinks(), 'meta' => self::resourceMeta()]);
            }

            return $wrap ? Shape::object(['data' => $items]) : $items;
        }
        $array = $this->resourceArray($value, $depth);

        return $wrap ? Shape::object(['data' => $array]) : $array;
    }

    private static function paginatorJson(array $item): array
    {
        $nullableString = Shape::scalar(['null', 'string']);
        $nullableInt = Shape::scalar(['integer', 'null']);

        return Shape::object([
            'current_page' => Shape::int(),
            'data' => Shape::list($item),
            'first_page_url' => Shape::string(),
            'from' => $nullableInt,
            'last_page' => Shape::int(),
            'last_page_url' => Shape::string(),
            'links' => Shape::list(Shape::object(['url' => $nullableString, 'label' => Shape::string(), 'page' => $nullableInt, 'active' => Shape::bool()])),
            'next_page_url' => $nullableString,
            'path' => $nullableString,
            'per_page' => Shape::int(),
            'prev_page_url' => $nullableString,
            'to' => $nullableInt,
            'total' => Shape::int(),
        ]);
    }

    private static function resourceLinks(): array
    {
        $s = Shape::scalar(['null', 'string']);

        return Shape::object(['first' => $s, 'last' => $s, 'prev' => $s, 'next' => $s]);
    }

    private static function resourceMeta(): array
    {
        $nullableString = Shape::scalar(['null', 'string']);
        $nullableInt = Shape::scalar(['integer', 'null']);

        return Shape::object([
            'current_page' => Shape::int(), 'from' => $nullableInt, 'last_page' => Shape::int(),
            'links' => Shape::list(Shape::object(['url' => $nullableString, 'label' => Shape::string(), 'page' => $nullableInt, 'active' => Shape::bool()])),
            'path' => $nullableString, 'per_page' => Shape::int(), 'to' => $nullableInt, 'total' => Shape::int(),
        ]);
    }

    // ───────────────────────────── collections, queries, models ─────────────────────────────

    private function itemOf(array $s): array
    {
        return match ($s['k']) {
            'list' => $s['item'],
            'object' => Shape::union(array_values($s['props']) ?: [Shape::unknown('empty array')]),
            'map' => $s['value'],
            'collection', 'paginator' => $s['item'],
            'resource_collection' => ['k' => 'instance', 'class' => $s['class'], 'props' => ['resource' => $this->itemOf($s['source'])]],
            'union' => Shape::union(array_map(fn ($x) => $this->itemOf($x), array_values(array_filter($s['of'], fn ($x) => ! ($x['k'] === 'scalar' && $x['types'] === ['null']))) ?: [Shape::null()])),
            'optional', 'merge' => $this->itemOf($s['value']),
            'unknown' => $s,
            'scalar' => $s['types'] === ['null'] ? Shape::unknown('iterating null') : Shape::unknown('items of a scalar'),
            default => Shape::unknown('items of '.$s['k']),
        };
    }

    private function keyOf(array $s): array
    {
        if ($s['k'] === 'object' && $s['props'] !== []) {
            // The keys are known (e.g. validation fields): each is one of them.
            return Shape::union(array_map(fn ($k) => Shape::literal($k), array_keys($s['props'])));
        }

        return Shape::scalar(['integer', 'string']);
    }

    private function collectionCall(array $c, string $m, array $args, Frame $f): array
    {
        $item = $c['item'];
        $fn = array_values($args)[0] ?? null;
        $a1 = array_values($args)[1] ?? null;
        $mapped = fn () => $fn !== null && $fn['k'] === 'closure' ? $this->branch(fn () => $this->callClosure($fn, [$item, Shape::scalar(['integer', 'string'])], $f->depth)) : $item;

        return match ($m) {
            'map', 'transform', 'mapinto' => ['k' => 'collection', 'item' => $mapped()],
            'flatmap' => ['k' => 'collection', 'item' => $this->itemOf($mapped())],
            'filter', 'reject', 'values', 'sortby', 'sortbydesc', 'sort', 'unique', 'reverse', 'take', 'skip', 'slice', 'where', 'wherein', 'wherenotnull', 'each', 'push', 'merge', 'concat', 'shuffle', 'collect',
            'forpage', 'chunk', 'splice', 'diff', 'intersect', 'whereNull', 'wherenull', 'sortkeys', 'uniquestrict', 'wherestrict', 'except', 'only' => ['k' => 'collection', 'item' => $item],
            'first', 'last', 'firstwhere', 'find', 'pop', 'shift' => Shape::nullable($item),
            'pluck' => ['k' => 'collection', 'item' => is_string($k = Shape::toPhp($fn ?? Shape::null())) ? $this->propertyOf($item, $k, $f) : Shape::unknown('pluck')],
            'keyby', 'groupby', 'mapwithkeys' => ['k' => 'map', 'value' => $m === 'groupby' ? Shape::list($item) : ($m === 'mapwithkeys' ? $this->itemOf($mapped()) : $item)],
            'toarray', 'all' => Shape::list($this->json($item)),
            'count', 'sum', 'avg', 'max', 'min' => $m === 'count' ? Shape::int() : Shape::scalar(['integer', 'number']),
            'isempty', 'isnotempty', 'contains', 'has' => Shape::bool(),
            'implode', 'tojson' => Shape::string(),
            'get' => $fn === null ? ['k' => 'collection', 'item' => $item] : Shape::union([$item, $a1 ?? Shape::null()]),
            'paginate', 'simplepaginate' => ['k' => 'paginator', 'item' => $item],
            // A query passed through helpers can reach here as a collection; its chain methods keep the rows' type.
            default => $this->isChainMethod($m, null) ? $c : Shape::unknown("collection {$m}()"),
        };
    }

    private function builderCall(array $b, string $m, array $args, Frame $f): array
    {
        $model = $b['model'];
        $row = $model === null ? Shape::unknown('row of table '.($b['table'] ?? '?').' (DB::table rows are not modelled)') : ['k' => 'model', 'class' => $model, 'with' => $b['with']];
        $a = fn (int $i) => array_values($args)[$i] ?? null;
        $this->recorder !== null && $this->recordQuery($model, $b['table'] ?? null, $m);

        if (in_array($m, ['with', 'load'], true)) {
            $relations = [];
            foreach ($args as $arg) {
                $value = Shape::toPhp($arg);
                foreach (is_array($value) ? $value : [$value] as $k => $v) {
                    $relations[] = is_string($k) ? $k : (is_string($v) ? $v : null);
                }
            }
            $b['with'] = array_values(array_unique(array_filter([...$b['with'], ...$relations])));

            return $b;
        }

        return match ($m) {
            'first', 'find', 'sole' => $m === 'sole' ? $row : Shape::nullable($row),
            'firstorfail', 'findorfail', 'firstorcreate', 'firstornew', 'updateorcreate', 'create', 'forcecreate', 'make' => $this->failsAs404($m, $row, $model),
            'get', 'all', 'cursor', 'lazy' => ['k' => 'collection', 'item' => $row],
            'paginate', 'simplepaginate', 'cursorpaginate' => ['k' => 'paginator', 'item' => $row],
            'count', 'increment', 'decrement', 'update', 'delete', 'insertgetid', 'upsert' => Shape::int(),
            'exists', 'doesntexist', 'insert' => Shape::bool(),
            'sum', 'avg', 'max', 'min' => Shape::scalar(['integer', 'null', 'number', 'string']),
            'value' => is_string($col = Shape::toPhp($a(0) ?? Shape::null())) && $model !== null ? Shape::nullable($this->schema->column($model, $col) ?? Shape::unknown("column {$col}")) : Shape::unknown('value()'),
            'pluck' => ['k' => 'collection', 'item' => is_string($col = Shape::toPhp($a(0) ?? Shape::null())) && $model !== null ? ($this->schema->column($model, $col) ?? Shape::unknown("column {$col}")) : Shape::unknown('pluck')],
            'chunk', 'each', 'chunkbyid', 'eachbyid' => Shape::bool(),
            'tosql' => Shape::string(),
            'firstwhere', 'firstor' => Shape::nullable($row),
            'findmany', 'lazybyid' => ['k' => 'collection', 'item' => $row],
            default => $this->isChainMethod($m, $model) ? $b : Shape::unknown("query {$m}()"),
        };
    }

    /** Query-builder methods that return the builder itself (where…, orderBy…, with…, scopes). */
    private function isChainMethod(string $m, ?string $model): bool
    {
        if (preg_match('/^(where|orwhere|with|without|has|doesnthave|orderby|order|group|having|orhaving|join|leftjoin|rightjoin|crossjoin|select|addselect|from|union|lock|shared|apply|for|skip|take|limit|offset|latest|oldest|inrandomorder|reorder|distinct|when|unless|tap|query|newquery|tobase|active|filter|search)/', $m) === 1) {
            return true;
        }
        // a local scope: scopeActive() on the model is called as ->active()
        $info = $model === null ? null : $this->index->find($model);

        return $info !== null && $this->index->method($info, 'scope'.Str::studly($m)) !== null;
    }

    private function failsAs404(string $m, array $row, ?string $model): array
    {
        if (in_array($m, ['firstorfail', 'findorfail'], true)) {
            $exception = ['k' => 'exception', 'class' => 'Illuminate\Database\Eloquent\ModelNotFoundException', 'status' => 404, 'message' => Shape::string(), 'model' => $model];
            $this->recorder !== null && $this->branch(fn () => $this->recordThrow($exception)); // only when no row matches
            $this->throwShape($exception);
        }

        return $row;
    }

    private function modelCall(array $model, string $name, array $args, Frame $f): array
    {
        $m = strtolower($name);
        $info = $this->index->find($model['class']);
        $this->recorder !== null && $this->recordModelCall($model['class'], $m);

        if (in_array($m, ['load', 'loadmissing'], true)) {
            return $this->builderCall(['k' => 'builder', 'model' => $model['class'], 'with' => $model['with']], 'with', $args, $f)['with'] === []
                ? $model : ['k' => 'model', 'class' => $model['class'], 'with' => $this->builderCall(['k' => 'builder', 'model' => $model['class'], 'with' => $model['with']], 'with', $args, $f)['with']];
        }
        if (in_array($m, ['toarray', 'jsonserialize', 'attributestoarray'], true)) {
            return $this->json($model);
        }
        if (in_array($m, ['save', 'update', 'delete', 'forcedelete', 'restore', 'push', 'touch', 'exists', 'isdirty', 'wasrecentlycreated'], true)) {
            return Shape::bool();
        }
        if (in_array($m, ['fresh', 'refresh', 'replicate', 'makehidden', 'makevisible', 'append', 'settable'], true)) {
            return $model;
        }
        $first = Shape::toPhp(array_values($args)[0] ?? Shape::null());
        if ($m === 'getrelation' && is_string($first)) {
            return $this->modelAttribute($model, $first);
        }
        if (in_array($m, ['relationloaded', 'isrelation', 'isdirty', 'isclean', 'waschanged', 'trashed', 'is', 'isnot', 'hasattribute', 'isfillable'], true)) {
            return Shape::bool();
        }
        if (in_array($m, ['setrelation', 'setattribute', 'fill', 'forcefill', 'unsetrelation', 'withoutrelations', 'setappends', 'sethidden', 'setvisible'], true)) {
            return $model;
        }
        if (in_array($m, ['gettable', 'getkeyname', 'getmorphclass', 'tojson', 'getroutekeyname'], true)) {
            return Shape::string();
        }
        if (in_array($m, ['only', 'getattributes', 'getoriginal', 'getrelations', 'getdirty', 'getchanges'], true)) {
            return $m === 'only' ? $this->json($model) : ['k' => 'map', 'value' => Shape::unknown('model attributes')];
        }
        if ($m === 'createtoken') {
            // Passport: PersonalAccessTokenResult->accessToken; Sanctum: NewAccessToken->plainTextToken
            return ['k' => 'instance', 'class' => 'Laravel\\Passport\\PersonalAccessTokenResult', 'props' => ['accessToken' => Shape::string(), 'plainTextToken' => Shape::string(), 'token' => Shape::unknown('token model')]];
        }
        if (in_array($m, ['getkey', 'getattribute'], true)) {
            return $m === 'getkey' ? Shape::int() : (is_string($k = Shape::toPhp(array_values($args)[0] ?? Shape::null())) ? $this->modelAttribute($model, $k) : Shape::unknown('attribute'));
        }
        if ($info !== null && ($relation = $this->relationTarget($info, $name)) !== null) {
            return ['k' => 'builder', 'model' => $relation[0], 'with' => []];
        }
        if ($info !== null && ($found = $this->index->method($info, $name)) !== null) {
            return $this->callMethod($found[0], $found[1], $args, $model, $f->depth);
        }

        // scopes and builder methods called on a model
        return $this->builderCall(['k' => 'builder', 'model' => $model['class'], 'with' => []], $m, $args, $f);
    }

    // ───────────────────────────── config & lang ─────────────────────────────

    /** config('file.a.b'), read from config/file.php without running it. */
    public function config(string $key, ?array $default = null): array
    {
        if ($key === 'app.debug') {
            return Shape::literal(false); // documented behavior is production: APP_DEBUG off
        }
        $parts = explode('.', $key);
        $file = array_shift($parts);
        if (! array_key_exists($file, $this->configFiles)) {
            $this->configFiles[$file] = null;
            $path = SnapshotPaths::join($this->root, "config/{$file}.php");
            $code = $this->reader->read($this->snapshot, $path);
            if ($code !== null && strlen($code) <= 262144) {
                $parsed = ParsedFile::parse($path, mb_scrub($code, 'UTF-8'), $this->root);
                $return = (new NodeFinder)->findFirstInstanceOf($parsed->stmts, Stmt\Return_::class);
                if ($return?->expr !== null) {
                    $class = new ClassInfo('config', $parsed, new Stmt\Class_('Config'), null);
                    $this->configFiles[$file] = $this->eval($return->expr, new Frame($class));
                }
            }
        }

        $value = $this->configFiles[$file] ?? null;
        if ($value === null) {
            return $default ?? Shape::unknown("config file {$file}.php was not found");
        }
        foreach ($parts as $part) {
            $value = $this->dim($value, Shape::literal($part));
        }

        return $default !== null && $this->mayBeNull($value) ? Shape::union([self::withoutNull($value), $default]) : $value;
    }

    /** The English text for a translation key, from the app's lang files. */
    private function translate(mixed $key): array
    {
        if (! is_string($key)) {
            return Shape::string();
        }
        foreach ([['messages', $key], ...(str_contains($key, '.') ? [explode('.', $key, 2)] : [])] as [$file, $k]) {
            if (! isset($this->lang[$file])) {
                $this->lang[$file] = Shape::object([]);
                foreach (['resources/lang/en', 'lang/en'] as $dir) {
                    $path = SnapshotPaths::join($this->root, "{$dir}/{$file}.php");
                    $code = $this->reader->read($this->snapshot, $path);
                    if ($code !== null && strlen($code) <= 4194304) {
                        $parsed = ParsedFile::parse($path, mb_scrub($code, 'UTF-8'), $this->root);
                        $return = (new NodeFinder)->findFirstInstanceOf($parsed->stmts, Stmt\Return_::class);
                        if ($return?->expr instanceof Expr\Array_) {
                            $this->lang[$file] = $this->eval($return->expr, new Frame(new ClassInfo('lang', $parsed, new Stmt\Class_('Lang'), null)));
                        }

                        break;
                    }
                }
            }
            $found = $this->lang[$file]['props'][$k] ?? null;
            if ($found !== null && array_key_exists('value', $found) && is_string($found['value'])) {
                return Shape::literal($found['value']);
            }
        }

        // Not in the English lang file: shown as the key text itself, typed string.
        return Shape::scalar(['string'], $key, true) + ['note' => 'translated at runtime; English shown when the key is missing from lang files'];
    }

    // ───────────────────────────── types ─────────────────────────────

    public function isA(string $class, string $ancestor): bool
    {
        if (strcasecmp($class, $ancestor) === 0) {
            return true;
        }
        if (str_ends_with(strtolower($ancestor), '\\httpexceptioninterface')) {
            return $this->isA($class, 'Symfony\Component\HttpKernel\Exception\HttpException');
        }
        static $framework = [
            'illuminate\http\resources\json\resourcecollection' => 'Illuminate\Http\Resources\Json\JsonResource',
            'illuminate\http\resources\json\anonymousresourcecollection' => 'Illuminate\Http\Resources\Json\ResourceCollection',
            'illuminate\foundation\http\formrequest' => 'Illuminate\Http\Request',
            'illuminate\database\eloquent\modelnotfoundexception' => 'RuntimeException',
            'symfony\component\httpkernel\exception\notfoundhttpexception' => 'Symfony\Component\HttpKernel\Exception\HttpException',
            'symfony\component\httpkernel\exception\accessdeniedhttpexception' => 'Symfony\Component\HttpKernel\Exception\HttpException',
            'symfony\component\httpkernel\exception\unauthorizedhttpexception' => 'Symfony\Component\HttpKernel\Exception\HttpException',
            'symfony\component\httpkernel\exception\httpexception' => 'RuntimeException',
            'illuminate\validation\validationexception' => 'Exception',
            'illuminate\auth\authenticationexception' => 'Exception',
            'illuminate\auth\access\authorizationexception' => 'Exception',
            'illuminate\http\exceptions\httpresponseexception' => 'RuntimeException',
            'illuminate\http\exceptions\throttlerequestsexception' => 'Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException',
            'symfony\component\httpkernel\exception\toomanyrequestshttpexception' => 'Symfony\Component\HttpKernel\Exception\HttpException',
            'symfony\component\httpkernel\exception\methodnotallowedhttpexception' => 'Symfony\Component\HttpKernel\Exception\HttpException',
            'runtimeexception' => 'Exception',
            'exception' => 'Throwable',
            'error' => 'Throwable',
            'illuminate\foundation\auth\user' => 'Illuminate\Database\Eloquent\Model',
            'illuminate\http\jsonresponse' => 'Symfony\Component\HttpFoundation\JsonResponse',
            'symfony\component\httpfoundation\jsonresponse' => 'Symfony\Component\HttpFoundation\Response',
            'illuminate\http\response' => 'Symfony\Component\HttpFoundation\Response',
        ];
        $current = $class;
        for ($i = 0; $i < 10; $i++) {
            $info = $this->index->find($current);
            $parent = $info?->parentName() ?? $framework[strtolower($current)] ?? null;
            if ($info !== null && $parent === null) {
                foreach ($info->node instanceof Stmt\Class_ ? $info->node->implements : [] as $interface) {
                    if (strcasecmp(ClassInfo::name($interface), $ancestor) === 0) {
                        return true;
                    }
                }
            }
            if ($parent === null) {
                return false;
            }
            if (strcasecmp($parent, $ancestor) === 0) {
                return true;
            }
            $current = $parent;
        }

        return false;
    }

    // ───────────────────────────── workflow recording (advanced profile) ─────────────────────────────

    private const READS = ['first', 'find', 'sole', 'firstorfail', 'findorfail', 'firstornew', 'get', 'all', 'cursor', 'lazy', 'paginate', 'simplepaginate', 'cursorpaginate',
        'count', 'exists', 'doesntexist', 'sum', 'avg', 'max', 'min', 'value', 'pluck', 'chunk', 'each', 'chunkbyid', 'eachbyid', 'firstwhere', 'firstor', 'findmany', 'lazybyid'];

    private const WRITES = ['create', 'forcecreate', 'createmany', 'update', 'delete', 'forcedelete', 'restore', 'increment', 'decrement', 'insert', 'insertgetid', 'insertorignore',
        'upsert', 'updateorcreate', 'firstorcreate', 'updateorinsert', 'save', 'savemany', 'attach', 'detach', 'sync', 'syncwithoutdetaching', 'toggle', 'associate', 'dissociate', 'truncate'];

    /** Framework and library code: calls into it are not part of the app's workflow. */
    private const FRAMEWORK = ['illuminate\\', 'symfony\\', 'carbon\\', 'psr\\', 'laravel\\', 'nesbot\\'];

    /**
     * Evaluates a call with its source location as the current site, so edges it records cite it.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function traced(Expr $e, Frame $f, callable $fn): mixed
    {
        if ($this->recorder === null) {
            return $fn();
        }
        $previous = $this->recorder->at($f->class->file->path, $f->class->sha256, $f->class->fqcn, $e->getStartLine(), $e->getEndLine());
        try {
            return $fn();
        } finally {
            $this->recorder->restore($previous);
        }
    }

    /**
     * Evaluates code that may not run on every request (a branch, a loop body, a catch).
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function branch(callable $fn, bool $conditional = true): mixed
    {
        if ($this->recorder === null || ! $conditional) {
            return $fn();
        }
        $this->recorder->enter();
        try {
            return $fn();
        } finally {
            $this->recorder->leave();
        }
    }

    /** The source text of an expression (shown only to roles that may read source). */
    private function source(Node $node): string
    {
        return mb_substr((new Standard)->prettyPrint([$node]), 0, 200);
    }

    public function methodNode(ClassInfo $owner, string $method, ?string $type = null): string
    {
        $id = $owner->fqcn.'::'.$method;
        $type ??= $this->typeOf($owner->fqcn);
        $this->recorder?->node($id, [
            'type' => $type,
            'label' => $type === 'resource' && strtolower($method) === 'toarray' ? class_basename($owner->fqcn) : class_basename($owner->fqcn).'::'.$method,
            'class' => $owner->fqcn,
            'method' => $method,
        ]);

        return $id;
    }

    private function classNode(string $class, string $type, ?string $label = null): string
    {
        $this->recorder?->node($class, ['type' => $type, 'label' => $label ?? class_basename($class), 'class' => $class]);

        return $class;
    }

    /**
     * The class, its parents, interfaces and traits, from classes already read (never loads a file:
     * the advanced profile must not change what the contract reads).
     *
     * @return list<string> lowercased
     */
    private function lineage(string $class): array
    {
        $names = [];
        $current = $class;
        for ($i = 0; $i < 8 && $current !== null; $i++) {
            $names[] = strtolower($current);
            $info = $this->index->cached($current);
            if ($info === null) {
                break;
            }
            foreach ($info->node instanceof Stmt\Class_ ? $info->node->implements : [] as $interface) {
                $names[] = strtolower(ClassInfo::name($interface));
            }
            foreach ($info->traitNames() as $trait) {
                $names[] = strtolower($trait);
            }
            $current = $info->parentName();
        }

        return $names;
    }

    /** The first parent of $class that wasn't read (from classes already read). */
    private function unreadAncestor(string $class): ?string
    {
        for ($i = 0, $info = $this->index->cached($class); $info !== null && $i < 8; $i++) {
            $parent = $info->parentName();
            if ($parent === null) {
                return null;
            }
            $info = $this->index->cached($parent);
            if ($info === null) {
                return $parent;
            }
        }

        return null;
    }

    /** Node type of a class: by what it extends or implements, else by its namespace. */
    public function typeOf(string $class): string
    {
        $lineage = $this->lineage($class);
        $has = fn (string ...$needles) => array_intersect($needles, $lineage) !== [];
        $ns = '\\'.strtolower($class);

        return match (true) {
            $has('illuminate\http\resources\json\jsonresource', 'illuminate\http\resources\json\resourcecollection') || str_contains($ns, '\resources\\') && str_ends_with($ns, 'resource') => 'resource',
            $has('illuminate\database\eloquent\model', 'illuminate\foundation\auth\user') || str_contains($ns, '\models\\') => 'model',
            $has('illuminate\foundation\http\formrequest') => 'form_request',
            $has('illuminate\mail\mailable') || str_contains($ns, '\mail\\') => 'mail',
            $has('illuminate\notifications\notification') || str_contains($ns, '\notifications\\') => 'notification',
            str_contains($ns, '\jobs\\') => 'job',
            str_contains($ns, '\events\\') => 'event',
            $has('throwable', 'exception') || str_ends_with($ns, 'exception') => 'exception',
            str_contains($ns, '\controllers\\') || str_ends_with($ns, 'controller') => 'controller',
            default => 'service',
        };
    }

    private function queued(string $class): bool
    {
        return in_array('illuminate\contracts\queue\shouldqueue', $this->lineage($class), true);
    }

    /** throw / abort / findOrFail: an exception path, with its status when the code states one. */
    private function recordThrow(array $exception, ?Frame $f = null, ?Node $at = null): void
    {
        foreach ($exception['k'] === 'union' ? $exception['of'] : [$exception] as $e) {
            if ($e['k'] !== 'exception' || ! is_string($e['class'] ?? null)) {
                continue;
            }
            $status = is_int($e['status'] ?? null) ? $e['status'] : null;
            if ($e['abort'] ?? false) {
                $id = $this->classNode('http:'.($status ?? 'runtime'), 'exception', $status === null ? 'HTTP error, status set at runtime (abort)' : "HTTP {$status} (abort)");
            } else {
                $id = $this->classNode($e['class'], 'exception', class_basename($e['class']).($status === null ? '' : " (HTTP {$status})"));
            }
            $previous = $f !== null && $at !== null ? $this->recorder?->at($f->class->file->path, $f->class->sha256, $f->class->fqcn, $at->getStartLine(), $at->getEndLine()) : false;
            $this->recorder?->edge($id, 'throws');
            $previous !== false && $this->recorder?->restore($previous);
        }
    }

    /**
     * dispatch()/event()/Job::dispatch()/Mail::send()/notify(): a job, event, mail or notification.
     * shortcut: an event's listeners are not traced (only the dispatch); trace EventServiceProvider/#[Listen] when needed.
     */
    private function recordDispatch(?array $target, string $via, bool $sync = false): void
    {
        $class = match ($target['k'] ?? null) {
            'instance', 'model' => $target['class'],
            'scalar' => is_string($target['value'] ?? null) ? $target['value'] : null, // event('order.placed')
            default => null,
        };
        if (! is_string($class)) {
            $this->recorder?->unresolved('dynamic_dispatch', 'Dispatched object built at runtime: its class is not known statically', null, null, 'dispatches');

            return;
        }
        $type = $this->typeOf($class);
        in_array($type, ['job', 'event', 'mail', 'notification'], true) || $type = $via;
        $async = match ($type) {
            'event' => false,
            'mail' => $via === 'mail_queue' || (! $sync && $this->queued($class)),
            default => ! $sync && $this->queued($class),
        };
        $note = match (true) {
            $type === 'event' => 'Event listeners are not traced; a queued listener runs later',
            $async => 'Queued: runs later on a worker; its order relative to other work is not known statically',
            default => null,
        };
        $this->recorder?->edge($this->classNode($class, $type), 'dispatches', ['async' => $async, 'note' => $note]);
    }

    /** ->notify(new X), Mail::to()->send(new X)/->queue(new X). */
    private function recordMethod(string $name, array $args): void
    {
        $m = strtolower($name);
        $first = array_values($args)[0] ?? null;
        $class = in_array($first['k'] ?? null, ['instance'], true) ? $first['class'] : null;
        if (in_array($m, ['notify', 'notifynow'], true) && $class !== null) {
            $this->recordDispatch($first, 'notification', $m === 'notifynow');
        } elseif (in_array($m, ['send', 'sendnow', 'queue', 'later'], true) && $class !== null && in_array($this->typeOf($class), ['mail', 'notification'], true)) {
            $this->recordDispatch($first, in_array($m, ['queue', 'later'], true) ? 'mail_queue' : 'mail', $m === 'sendnow');
        }
    }

    /** Job::dispatch(), Bus::/Event::/Mail::/Notification::/Queue::, the Http client. */
    private function recordStatic(string $class, string $base, string $m, array $args): void
    {
        $values = array_values($args);
        $known = $this->index->find($class) !== null; // the call below looks it up too: a cache hit
        if ($known && in_array($m, ['dispatch', 'dispatchsync', 'dispatchnow', 'dispatchif', 'dispatchunless', 'dispatchafterresponse', 'broadcast'], true)) {
            $this->branch(fn () => $this->recordDispatch(['k' => 'instance', 'class' => $class], $this->typeOf($class) === 'event' ? 'event' : 'job', in_array($m, ['dispatchsync', 'dispatchnow'], true)),
                in_array($m, ['dispatchif', 'dispatchunless'], true));

            return;
        }
        if ($known) {
            return;
        }
        $items = fn (?array $v) => ($v['k'] ?? null) === 'list' ? ($v['values'] ?? []) : ($v === null ? [] : [$v]);
        match (true) {
            $base === 'bus' && in_array($m, ['dispatch', 'dispatchsync', 'dispatchnow', 'dispatchtoqueue', 'chain', 'batch'], true) => array_map(fn ($job) => $this->recordDispatch($job, 'job', in_array($m, ['dispatchsync', 'dispatchnow'], true)), $items($values[0] ?? null)),
            $base === 'event' && in_array($m, ['dispatch', 'until'], true) => $this->recordDispatch($values[0] ?? null, 'event', true),
            $base === 'mail' && in_array($m, ['send', 'sendnow', 'queue', 'later'], true) => $this->recordDispatch($values[$m === 'later' ? 1 : 0] ?? null, in_array($m, ['queue', 'later'], true) ? 'mail_queue' : 'mail', $m === 'sendnow'),
            $base === 'notification' && in_array($m, ['send', 'sendnow'], true) => $this->recordDispatch($values[1] ?? null, 'notification', $m === 'sendnow'),
            $base === 'queue' && in_array($m, ['push', 'later', 'pushon'], true) => $this->recordDispatch($values[$m === 'push' ? 0 : 1] ?? null, 'job'),
            $base === 'http' => $this->recorder?->edge($this->classNode('external:http', 'external', 'Outgoing HTTP request (Http client)'), 'calls'),
            default => null,
        };
    }

    /** A query's terminal method reads or writes its model (or table). */
    private function recordQuery(?string $model, mixed $table, string $m): void
    {
        $reads = in_array($m, self::READS, true) || in_array($m, ['updateorcreate', 'firstorcreate'], true);
        $writes = in_array($m, self::WRITES, true);
        if (! $reads && ! $writes) {
            return;
        }
        $id = $model !== null ? $this->classNode($model, 'model')
            : $this->classNode('table:'.(is_string($table) ? $table : 'runtime'), 'model', is_string($table) ? "{$table} table (query builder)" : 'a table named at runtime (query builder)');
        $reads && $this->recorder?->edge($id, 'reads_from');
        $writes && $this->recorder?->edge($id, 'writes_to');
    }

    /** $model->save(), ->delete(), ->refresh() … (query methods reach recordQuery through the builder). */
    private function recordModelCall(string $model, string $m): void
    {
        if (in_array($m, ['save', 'savequietly', 'update', 'updatequietly', 'delete', 'deletequietly', 'forcedelete', 'restore', 'push', 'touch'], true)) {
            $this->recorder?->edge($this->classNode($model, 'model'), 'writes_to');
        } elseif (in_array($m, ['fresh', 'refresh', 'load', 'loadmissing', 'loadcount'], true)) {
            $this->recorder?->edge($this->classNode($model, 'model'), 'reads_from');
        }
    }

    /**
     * A call on a class the snapshot can't follow: an interface (the container picks the class),
     * a class not read, or a library outside the app (an integration boundary).
     */
    private function recordOpaque(string $class, ?string $method, bool $inherited = false): void
    {
        $call = $class.($method === null ? '' : "::{$method}()");
        $framework = array_filter(self::FRAMEWORK, fn ($p) => str_starts_with(strtolower(ltrim($class, '\\')), $p)) !== [];
        if ($framework || in_array(strtolower($class), ['request', 'carbon'], true)) {
            return;
        }
        if (($reason = $this->index->missingReason($class)) !== 'is not in the snapshot') {
            $this->recorder?->unresolved('class_limit', "Not read: {$class} {$reason}");

            return;
        }
        $status = $inherited ? 'class' : $this->index->interfaceStatus($class);
        if ($status === 'interface') {
            $this->recorder?->unresolved('container_binding', "Container binding resolved at runtime: {$call} ({$this->bindingNote($class)})");

            return;
        }
        if ($status === 'missing') {
            $this->recorder?->unresolved('missing_class', "Not in the uploaded source: {$call} (its namespace belongs to the app, but no file declares it)");
        } elseif ($status === 'unchecked') {
            $this->recorder?->unresolved('class_limit', "Not checked: {$call} (the per-analysis lookup limit was reached)");
        } elseif ($inherited) {
            // The method lives in an ancestor that isn't in the snapshot: framework ones (JsonResource's model proxy,
            // Controller::validate()) are not workflow; a library's is a boundary the trace can't see into.
            $ancestor = $this->unreadAncestor($class);
            $ancestor !== null && array_filter(self::FRAMEWORK, fn ($p) => str_starts_with(strtolower($ancestor), $p)) === []
                && $this->recorder?->unresolved('outside_snapshot', "Defined outside the uploaded source: {$call} is inherited from {$ancestor}, which is not in the snapshot");
        } else {
            $this->recorder?->edge($this->classNode('external:'.$class, 'external', class_basename($class).' (library outside the app)'), 'calls');
        }
    }

    /** @var array<string, array{string, string}>|null lowercased interface => [concrete, provider] from the app's and modules' providers */
    private ?array $bindings = null;

    /** @var array<string, true> providers that bind with a computed abstract name (e.g. a loop over files) */
    private array $computedBinders = [];

    private function bindingNote(string $interface): string
    {
        if ($this->bindings === null) {
            $this->bindings = [];
            $paths = [...$this->reader->paths($this->snapshot, SnapshotPaths::join($this->root, 'app/Providers'), like: '%.php'),
                ...$this->reader->paths($this->snapshot, SnapshotPaths::join($this->root, 'Modules'), like: '%/Providers/%.php')];
            foreach (array_slice($paths, 0, 200) as $path) { // shortcut: at most 200 provider files are scanned for bindings
                $code = $this->reader->read($this->snapshot, $path);
                if ($code === null || strlen($code) > 262144) {
                    continue;
                }
                $parsed = ParsedFile::parse($path, mb_scrub($code, 'UTF-8'), $this->root);
                $const = fn (?Node $n) => $n instanceof Expr\ClassConstFetch && $n->class instanceof Node\Name && $n->name instanceof Node\Identifier && strtolower($n->name->toString()) === 'class' ? ClassInfo::name($n->class) : null;
                foreach ((new NodeFinder)->find($parsed->stmts, fn (Node $n) => ($n instanceof Expr\MethodCall || $n instanceof Expr\StaticCall) && $n->name instanceof Node\Identifier
                    && in_array(strtolower($n->name->toString()), ['bind', 'singleton', 'scoped', 'bindif', 'singletonif', 'scopedif'], true)) as $call) {
                    /** @var Expr\MethodCall|Expr\StaticCall $call */
                    $first = ($call->args[0] ?? null) instanceof Node\Arg ? $call->args[0]->value : null;
                    $abstract = $const($first);
                    $concrete = ($call->args[1] ?? null) instanceof Node\Arg ? ($const($call->args[1]->value) ?? 'a factory closure') : $abstract;
                    if ($abstract !== null) {
                        $this->bindings[strtolower($abstract)] ??= [(string) $concrete, basename($path, '.php')];
                    } elseif ($first !== null && ! $first instanceof Scalar\String_) {
                        $this->computedBinders[basename($path, '.php')] = true; // bind($name, …) in a loop: the interface is named at runtime
                    }
                }
            }
        }
        $found = $this->bindings[strtolower(ltrim($interface, '\\'))] ?? null;

        return match (true) {
            $found !== null => "{$found[1]} binds it to {$found[0]}; not followed, since the binding can change at runtime",
            $this->computedBinders !== [] => 'no static binding found; providers bind classes by names computed at runtime: '.implode(', ', array_slice(array_keys($this->computedBinders), 0, 3)),
            default => 'no static binding found',
        };
    }

    /** Which keys of a resource's toArray() are always present, and when the others are. */
    private function recordFields(ClassInfo $owner, Stmt\ClassMethod $method, array $applied): void
    {
        $alts = array_values(array_filter($applied['k'] === 'union' ? $applied['of'] : [$applied], fn ($s) => $s['k'] === 'object'));
        if ($alts === []) {
            return;
        }
        $helpers = $this->resourceHelpers($method);
        $names = [];
        foreach ($alts as $alt) {
            foreach (array_keys($alt['props']) as $k) {
                $names[(string) $k] = true;
            }
        }
        $fields = [];
        foreach (array_slice(array_keys($names), 0, 100) as $name) {
            $always = true;
            foreach ($alts as $alt) {
                $always = $always && array_key_exists($name, $alt['props']) && ! in_array($name, $alt['optional'], true);
            }
            [$helper, $expression, $argument] = $helpers[$name] ?? [null, null, null];
            $fields[] = ['name' => $name, 'presence' => $always ? 'always' : 'conditional', 'condition' => $always ? null : self::presenceCondition($helper, $argument), 'expression' => $always ? null : $expression];
        }
        $this->recorder?->fields($this->methodNode($owner, $method->name->toString(), 'resource'), $fields);
    }

    /** @return array<string, array{string, string, string|null}> key => [helper, source, literal first argument] for when()/whenLoaded()/mergeWhen() in returned arrays */
    private function resourceHelpers(Stmt\ClassMethod $method): array
    {
        $out = [];
        $helper = fn (Expr $v) => $v instanceof Expr\MethodCall && $v->var instanceof Expr\Variable && $v->var->name === 'this' && $v->name instanceof Node\Identifier
            && preg_match('/^(when\w*|unless|merge\w+)$/i', $v->name->toString()) === 1 ? strtolower($v->name->toString()) : null;
        $first = fn (Expr\MethodCall $v) => ($v->args[0] ?? null) instanceof Node\Arg && $v->args[0]->value instanceof Scalar\String_ ? $v->args[0]->value->value : null;
        foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Stmt\Return_::class) as $return) {
            foreach ($return->expr instanceof Expr\Array_ ? $return->expr->items : [] as $item) {
                if (($name = $helper($item->value)) === null) {
                    continue;
                }
                /** @var Expr\MethodCall $call */
                $call = $item->value;
                if ($item->key instanceof Scalar\String_) {
                    $out[$item->key->value] = [$name, $this->source($call), $first($call)];
                } elseif ($item->key === null && str_starts_with($name, 'merge')) {
                    $merged = $call->args[$name === 'merge' ? 0 : 1] ?? null;
                    foreach ($merged instanceof Node\Arg && $merged->value instanceof Expr\Array_ ? $merged->value->items : [] as $inner) {
                        $inner->key instanceof Scalar\String_ && $out[$inner->key->value] = [$name, $this->source($call), null];
                    }
                }
            }
        }

        return $out;
    }

    private static function presenceCondition(?string $helper, ?string $argument): string
    {
        $what = $argument === null ? 'the relation' : "the {$argument} relation";

        return match ($helper) {
            'whenloaded' => "only when {$what} is loaded",
            'whencounted' => "only when {$what} count is loaded",
            'whenaggregated' => "only when {$what} aggregate is loaded",
            'whennotnull' => 'only when the value is not null',
            'whenhas' => $argument === null ? 'only when the model has the attribute' : "only when the model has the {$argument} attribute",
            'when' => 'only when a condition decided at runtime holds',
            'unless' => 'only when a condition decided at runtime does not hold',
            'mergewhen' => 'merged in only when a condition decided at runtime holds',
            'mergeunless' => 'merged in only when a condition decided at runtime does not hold',
            default => 'present on some code paths only',
        };
    }
}
