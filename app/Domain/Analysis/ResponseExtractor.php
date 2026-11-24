<?php

namespace App\Domain\Analysis;

use App\Domain\Discovery\ValueResolver;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Reads what a controller action returns, as written. A status code typed in the code is
 * "verified"; a shape read from an array literal or a Resource's toArray() is "inferred";
 * anything else is "unknown". No example payload is ever invented.
 */
final class ResponseExtractor
{
    /** @var list<array<string, mixed>> */
    public array $responses = [];

    /** @var list<string> Resource classes this endpoint returns */
    public array $resources = [];

    public function __construct(
        private readonly ClassIndex $index,
        private readonly Evidence $evidence,
    ) {}

    public function extract(ClassInfo $class, Stmt\ClassMethod $method): void
    {
        $symbol = $class->symbol($method->name->toString());

        foreach ($this->ownReturns($method->stmts ?? []) as $return) {
            $this->response($return->expr, $class, $symbol, $return);
        }

        // abort(404), abort_if($x, 403, '…'), throw ValidationException
        $finder = new NodeFinder;
        foreach ($finder->find($method->stmts ?? [], fn (Node $n) => $n instanceof Expr\FuncCall && $n->name instanceof Node\Name
            && in_array(strtolower($n->name->getLast()), ['abort', 'abort_if', 'abort_unless'], true)) as $call) {
            /** @var Expr\FuncCall $call */
            $fn = $call->name instanceof Node\Name ? strtolower($call->name->getLast()) : '';
            $statusArg = $fn === 'abort' ? 0 : 1;
            $status = $class->file->resolver()->arg($call->args, $statusArg);
            if (is_int($status)) {
                $this->add($status, 'verified', 'abort', null, $class, $symbol, $call);
            }
        }

        foreach ($finder->find($method->stmts ?? [], fn (Node $n) => $n instanceof Expr\StaticCall && $n->class instanceof Node\Name
            && str_ends_with(strtolower(ClassInfo::name($n->class)), 'validationexception')) as $throw) {
            $this->add(422, 'verified', 'validation_exception', null, $class, $symbol, $throw);
        }
    }

    /** Adds a response inferred from Laravel's own behavior (validation, auth, model binding). */
    public function standard(int $status, string $reason): void
    {
        foreach ($this->responses as $existing) {
            if ($existing['status'] === $status) {
                return; // the code already states it
            }
        }

        $this->responses[] = ['status' => $status, 'status_confidence' => 'inferred', 'kind' => 'framework', 'body' => null, 'reason' => $reason, 'evidence' => []];
    }

    /** Adds the FormRequest validation response, including an application-defined JSON envelope. */
    public function validation(ClassInfo $request): void
    {
        $found = $this->index->method($request, 'failedValidation');
        if ($found === null) {
            $this->standard(422, 'Laravel returns 422 when the FormRequest rules fail');

            return;
        }

        [$owner, $method] = $found;
        $finder = new NodeFinder;
        $configKey = null;
        foreach ($finder->findInstanceOf($method->stmts ?? [], Expr\FuncCall::class) as $call) {
            if ($call->name instanceof Node\Name && strtolower($call->name->getLast()) === 'config') {
                $value = $owner->file->resolver()->arg($call->args, 0);
                if (is_string($value) && str_starts_with($value, 'response.')) {
                    $configKey = substr($value, 9);
                    break;
                }
            }
        }

        $config = $this->index->config('response');
        $entry = $configKey === null ? null : ($config[$configKey] ?? null);
        $status = is_array($entry) ? ($entry['http_response_code'] ?? null) : null;
        $formatter = $this->index->method($request, 'responseFormatter');
        $envelopeCall = $formatter === null ? null : $finder->findFirst($formatter[1]->stmts ?? [], fn (Node $n) => $n instanceof Expr\StaticCall
            && $n->class instanceof Node\Name && $n->name instanceof Node\Identifier
            && str_ends_with(strtolower(ClassInfo::name($n->class)), '\\apienvelope')
            && strtolower($n->name->toString()) === 'make');
        $envelope = $envelopeCall instanceof Expr\StaticCall && $envelopeCall->class instanceof Node\Name
            ? $this->index->find(ClassInfo::name($envelopeCall->class)) : null;
        $make = $envelope === null ? null : $this->index->method($envelope, 'make');
        $makeReturn = $make === null ? null : $finder->findFirstInstanceOf($make[1]->stmts ?? [], Stmt\Return_::class);
        $makeArray = $makeReturn instanceof Stmt\Return_ && $makeReturn->expr instanceof Expr\Array_ ? $makeReturn->expr : null;
        $envelopeKeys = [];
        foreach ($makeArray === null ? [] : $makeArray->items as $item) {
            $key = $item->key === null ? null : $make[0]->file->resolver()->resolve($item->key);
            is_string($key) && $envelopeKeys[] = $key;
        }
        sort($envelopeKeys);
        $expectedEnvelopeKeys = ['content', 'errors', 'identical_code', 'message'];
        $formatterContentDefaultsToNull = $formatter !== null
            && isset($formatter[1]->params[1])
            && $formatter[1]->params[1]->default instanceof Expr\ConstFetch
            && strtolower($formatter[1]->params[1]->default->name->toString()) === 'null';

        if (! is_int($status) || $envelopeKeys !== $expectedEnvelopeKeys || ! $formatterContentDefaultsToNull) {
            $this->standard(422, 'Laravel returns 422 when the FormRequest rules fail; its custom error body could not be read exactly');

            return;
        }

        $messageIsRuntime = $finder->findFirst($method->stmts ?? [], fn (Node $n) => $n instanceof Expr\Assign
            && $n->var instanceof Expr\ArrayDimFetch
            && $n->var->dim instanceof Node\Scalar\String_
            && $n->var->dim->value === 'message') !== null;
        $identicalCode = is_string($entry['identical_code'] ?? null) ? $entry['identical_code'] : null;
        $configuredMessage = is_string($entry['message'] ?? null) ? $entry['message'] : null;

        $this->responses[] = [
            'status' => $status,
            'status_confidence' => 'verified',
            'kind' => 'validation_error',
            'body' => ['fields' => [
                ['name' => 'identical_code', 'type' => $identicalCode === null ? 'string|null' : 'string', 'value' => $identicalCode, 'value_known' => true],
                [
                    'name' => 'message', 'type' => 'string', 'value' => $messageIsRuntime ? null : $configuredMessage,
                    'value_known' => ! $messageIsRuntime && $configuredMessage !== null,
                    'description' => $messageIsRuntime ? 'The first validation error message for this request; text depends on the failing field and active locale.' : null,
                ],
                ['name' => 'content', 'type' => 'null', 'value' => null, 'value_known' => true],
                [
                    'name' => 'errors', 'type' => 'array', 'value' => null, 'value_known' => false,
                    'description' => 'One entry per failing field.',
                    'items' => [
                        ['name' => 'code', 'type' => 'string', 'value' => null, 'value_known' => false, 'description' => 'The failing validation field name.'],
                        ['name' => 'message', 'type' => 'string', 'value' => null, 'value_known' => false, 'description' => 'The first validation message for that field in the active locale.'],
                    ],
                ],
            ]],
            'reason' => 'Validation failure envelope read from '.$owner->symbol('failedValidation').' and config/response.php.',
            'evidence' => array_values(array_filter([
                $this->evidence->add($owner->file->path, $owner->sha256, $owner->symbol('failedValidation'), $method, 'response'),
                $make === null ? null : $this->evidence->add($make[0]->file->path, $make[0]->sha256, $make[0]->symbol('make'), $make[1], 'response'),
            ])),
        ];
    }

    /**
     * Return statements of the method itself, not of closures inside it.
     *
     * @param  array<Node>  $stmts
     * @return list<Stmt\Return_>
     */
    private function ownReturns(array $stmts): array
    {
        $out = [];
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Return_ && $stmt->expr !== null) {
                $out[] = $stmt;

                continue;
            }
            foreach ($stmt->getSubNodeNames() as $name) {
                $child = $stmt->$name;
                $children = is_array($child) ? $child : [$child];
                foreach ($children as $c) {
                    // Stmts and their parts (else, case, catch…) — never expressions, so closures are skipped.
                    if ($c instanceof Node && ! $c instanceof Expr) {
                        array_push($out, ...$this->ownReturns([$c]));
                    }
                }
            }
        }

        return $out;
    }

    private function response(Expr $expr, ClassInfo $class, string $symbol, Node $at): void
    {
        $resolver = $class->file->resolver();

        // Unwrap ->header(...), ->setStatusCode(n), ->withHeaders(...) chains.
        $status = null;
        while ($expr instanceof Expr\MethodCall && $expr->name instanceof Node\Identifier
            && in_array(strtolower($expr->name->toString()), ['header', 'withheaders', 'setstatuscode', 'cookie', 'withcookie', 'response'], true)) {
            if (strtolower($expr->name->toString()) === 'setstatuscode') {
                $value = $resolver->arg($expr->args, 0);
                is_int($value) && $status = $value;
            }
            if (strtolower($expr->name->toString()) === 'response' && $expr->var instanceof Expr\New_) {
                // (new XResource($m))->response()->setStatusCode(201)
                $this->resource($expr->var, $class, $symbol, $at, $status ?? 200, $status !== null ? 'verified' : 'inferred');

                return;
            }
            $expr = $expr->var;
        }

        // response()->json($data, 201) / response()->noContent() / Response::json(...)
        if ($expr instanceof Expr\MethodCall && $expr->name instanceof Node\Identifier && $this->isResponseFactory($expr->var)) {
            $method = strtolower($expr->name->toString());

            if ($method === 'json') {
                $code = $expr->args === [] || count($expr->args) < 2 ? 200 : $resolver->arg($expr->args, 1);
                $this->add(
                    is_int($code) ? ($status ?? $code) : ($status ?? 200),
                    is_int($code) || $status !== null ? 'verified' : 'unknown',
                    'json',
                    $this->shape($expr->args[0] ?? null, $resolver),
                    $class, $symbol, $at,
                );

                return;
            }

            if ($method === 'nocontent') {
                $this->add(204, 'verified', 'empty', null, $class, $symbol, $at);

                return;
            }
        }

        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name) {
            $fn = strtolower($expr->name->getLast());

            if ($fn === 'response' && $expr->args !== []) {
                $code = count($expr->args) > 1 ? $resolver->arg($expr->args, 1) : 200;
                $this->add(is_int($code) ? $code : 200, is_int($code) ? 'verified' : 'unknown', 'json', $this->shape($expr->args[0] ?? null, $resolver), $class, $symbol, $at);

                return;
            }

            if (in_array($fn, ['redirect', 'back', 'to_route'], true)) {
                $this->add($status ?? 302, 'inferred', 'redirect', null, $class, $symbol, $at);

                return;
            }

            if ($fn === 'view') {
                $this->add(200, 'inferred', 'html', null, $class, $symbol, $at);

                return;
            }
        }

        // new XResource($m), XResource::make($m), XResource::collection($items)
        if ($expr instanceof Expr\New_ || ($expr instanceof Expr\StaticCall && $expr->name instanceof Node\Identifier
            && in_array(strtolower($expr->name->toString()), ['make', 'collection'], true))) {
            if ($this->resource($expr, $class, $symbol, $at, $status ?? 200, $status !== null ? 'verified' : 'inferred')) {
                return;
            }
        }

        if ($expr instanceof Expr\Array_) {
            $this->add($status ?? 200, 'inferred', 'json', $this->shape($expr, $resolver), $class, $symbol, $at);

            return;
        }

        $this->responses[] = [
            'status' => $status, 'status_confidence' => 'unknown', 'kind' => 'unknown', 'body' => null,
            'reason' => 'the returned value is computed at runtime',
            'evidence' => [$this->evidence->add($class->file->path, $class->sha256, $symbol, $at, 'response')],
        ];
    }

    private function isResponseFactory(Expr $expr): bool
    {
        return ($expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name && strtolower($expr->name->getLast()) === 'response' && $expr->args === [])
            || ($expr instanceof Expr\StaticCall && $expr->class instanceof Node\Name && str_ends_with(strtolower(ClassInfo::name($expr->class)), 'response'));
    }

    private function resource(Expr $expr, ClassInfo $class, string $symbol, Node $at, int $status, string $confidence): bool
    {
        $name = $expr instanceof Expr\New_ ? $expr->class : ($expr instanceof Expr\StaticCall ? $expr->class : null);
        if (! $name instanceof Node\Name) {
            return false;
        }

        $fqcn = ClassInfo::name($name);
        $collection = $expr instanceof Expr\StaticCall && $expr->name instanceof Node\Identifier && strtolower($expr->name->toString()) === 'collection';
        $resource = $this->index->find($fqcn);

        if ($resource === null || $this->index->extends($resource, 'Illuminate\Http\Resources\Json\JsonResource') === false) {
            return false; // not a resource we can read (could be a model or a DTO)
        }

        $this->resources[] = $fqcn;
        $fields = null;
        $evidence = [$this->evidence->add($class->file->path, $class->sha256, $symbol, $at, 'response')];

        if ($toArray = $resource->method('toArray')) {
            foreach ($this->ownReturns($toArray->stmts ?? []) as $return) {
                if ($return->expr instanceof Expr\Array_) {
                    $fields = $this->shape($return->expr, $resource->file->resolver());
                    $evidence[] = $this->evidence->add($resource->file->path, $resource->sha256, $resource->symbol('toArray'), $return, 'resource');

                    break;
                }
            }
        }

        $this->responses[] = [
            'status' => $status,
            'status_confidence' => $confidence,
            'kind' => $collection ? 'resource_collection' : 'resource',
            'resource' => $fqcn,
            // Laravel wraps resources in "data" unless the app disables wrapping; that is runtime config.
            'body' => $fields === null ? null : ['wrapped_in' => 'data (unless wrapping is disabled)', 'collection' => $collection, 'fields' => $fields['fields']],
            'reason' => $fields === null ? 'the resource builds its array dynamically' : null,
            'evidence' => $evidence,
        ];

        return true;
    }

    /**
     * Keys of an array literal, with a value type when the value is a literal.
     *
     * @return array{fields: list<array<string, mixed>>}|null
     */
    private function shape(?Node $arg, ValueResolver $resolver): ?array
    {
        $expr = $arg instanceof Node\Arg ? $arg->value : $arg;

        if (! $expr instanceof Expr\Array_) {
            return null;
        }

        $fields = [];
        foreach ($expr->items as $item) {
            $key = $item->key === null ? null : $resolver->resolve($item->key);
            if (! is_string($key) && ! is_int($key)) {
                continue;
            }

            $value = $resolver->resolve($item->value);
            $fields[] = [
                'name' => (string) $key,
                'type' => match (true) {
                    is_string($value) => 'string', is_int($value) => 'integer', is_float($value) => 'number', is_bool($value) => 'boolean',
                    // [] and ['a', 'b'] are JSON lists; keyed arrays are objects.
                    $item->value instanceof Expr\Array_ => array_filter($item->value->items, fn ($i) => $i->key !== null) === [] ? 'array' : 'object',
                    default => 'unknown',
                },
                // Literal values are shown as-is; computed ones are never guessed.
                'value' => is_scalar($value) ? $value : null,
            ];
        }

        return ['fields' => $fields];
    }

    /** @param  array<string, mixed>|null  $body */
    private function add(int $status, string $confidence, string $kind, ?array $body, ClassInfo $class, string $symbol, Node $at): void
    {
        $this->responses[] = [
            'status' => $status,
            'status_confidence' => $confidence,
            'kind' => $kind,
            'body' => $body,
            'reason' => null,
            'evidence' => [$this->evidence->add($class->file->path, $class->sha256, $symbol, $at, 'response')],
        ];
    }
}
