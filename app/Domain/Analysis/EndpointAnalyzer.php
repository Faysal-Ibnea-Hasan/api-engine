<?php

namespace App\Domain\Analysis;

use App\Domain\Analysis\Shape\ContractBuilder;
use App\Domain\Analysis\Shape\Interpreter;
use App\Domain\Analysis\Shape\Recorder;
use App\Domain\Discovery\ParsedFile;
use App\Domain\Discovery\SnapshotPaths;
use App\Domain\Discovery\ValueResolver;
use App\Domain\Sources\SnapshotReader;
use App\Models\ApiEndpoint;
use App\Models\SourceFile;
use App\Models\SourceSnapshot;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Builds one AnalysisResultV1 for one endpoint from the immutable snapshot, statically.
 * Uploaded code is parsed, never loaded or run. Every fact names its evidence; anything
 * the code decides at runtime goes to "unresolved" instead of being guessed.
 */
class EndpointAnalyzer // not final: tests swap in a broken result
{
    public const VERSION = 'laravel-ast-v6';

    public const SCHEMA_VERSION = '1.0';

    private const FORM_REQUEST = 'Illuminate\Foundation\Http\FormRequest';

    private const INPUT_METHODS = ['input', 'get', 'query', 'post', 'file', 'hasfile', 'boolean', 'integer', 'float', 'string', 'str', 'date', 'enum', 'has', 'filled', 'missing', 'only', 'except', 'header', 'bearertoken', 'cookie'];

    /** Symfony bags and Request helpers that are not input fields when read as $request->x. */
    private const NOT_FIELDS = ['headers', 'query', 'request', 'files', 'cookies', 'attributes', 'server', 'route', 'user', 'session', 'json'];

    /** @var array<string, array{verdict: string, kind: string, status: string, credentials: list<string>, note: string|null, rejects: list<int>, evidence: list<string>}> per analysis */
    private array $inspected = [];

    /**
     * Recorder bound overrides (maxFanout, maxNodes, maxSeconds) for the advanced profile.
     *
     * @var array<string, int|float>
     */
    public array $graphLimits = [];

    public function __construct(private readonly SnapshotReader $reader) {}

    /**
     * @param  string  $profile  basic | advanced (advanced adds the optional `graph`; every other field is the same)
     * @return array<string, mixed> the AnalysisResultV1 envelope plus 'status' (completed | partial)
     */
    public function analyze(SourceSnapshot $snapshot, ApiEndpoint $endpoint, string $root, string $profile = EndpointAnalyses::BASIC): array
    {
        $evidence = new Evidence;
        $recorder = $profile === EndpointAnalyses::ADVANCED ? new Recorder($evidence) : null;
        foreach ($recorder === null ? [] : $this->graphLimits as $bound => $value) {
            $recorder->{$bound} = $value;
        }
        $unresolved = [];
        $fields = [];
        $headers = [];
        $signals = [];
        $dependencies = [];

        $routeSha = $this->sha($snapshot, $endpoint->source_path);
        $routeEv = $evidence->lines($endpoint->source_path, $routeSha, null, $endpoint->source_line, $endpoint->source_line, 'route');

        // Path parameters come straight from the URI the route registers.
        preg_match_all('/\{(\w+)(\?)?(?::[^}]*)?\}/', ($endpoint->domain ?? '').$endpoint->uri, $params, PREG_SET_ORDER);
        $pathParams = [];
        foreach ($params as $param) {
            $pathParams[] = $name = $param[1];
            $fields[] = self::field($name, 'path', isset($param[2]) ? 'optional' : 'required', 'verified', 'route', [$routeEv]);
        }

        $responses = new ResponseExtractor($index = new ClassIndex($this->reader, $snapshot, $root), $evidence);

        // Every middleware that runs for this route — global, groups, aliases, route — read from source.
        $this->inspected = [];
        $map = new MiddlewareMap($this->reader, $snapshot, $root, $evidence);
        $inspector = new MiddlewareInspector($index, $evidence, ...$this->authConfig($snapshot, $root));
        $without = $endpoint->without_middleware;
        $stack = $map->stack($endpoint->middleware, $without ?? []);
        foreach ($stack as $entry) {
            $signals[] = $this->signal($entry, $inspector, [$routeEv], false);
        }
        if ($without === null && str_contains((string) $endpoint->expression, 'withoutMiddleware')) {
            $signals[] = self::unknownSignal('withoutMiddleware()', 'route', 'this route removes middleware, and its route scan predates recording which; rescan the project', [$routeEv]);
        }

        $method = null;
        $controller = null;

        if ($endpoint->handler_type === 'closure') {
            $unresolved[] = 'The route uses an inline closure; only controller actions are analyzed.';
        } elseif ($endpoint->controller === null) {
            $unresolved[] = "The handler ({$endpoint->handler_type}) has no controller action to analyze.";
        } elseif (($controller = $index->find($endpoint->controller)) === null) {
            $unresolved[] = "Controller {$endpoint->controller} was not found in this snapshot.";
        } else {
            $dependencies[] = ['kind' => 'controller', 'class' => $controller->fqcn];
            $found = $index->method($controller, $endpoint->action ?? '__invoke');

            if ($found === null) {
                $unresolved[] = "Action {$endpoint->controller}::".($endpoint->action ?? '__invoke').' was not found (checked the class, its traits and parents).';
            } else {
                [$owner, $method] = $found;
                foreach ($owner->file->parseErrors() as $error) {
                    $unresolved[] = "{$owner->fqcn} has a PHP syntax problem ({$error['message']}); facts from it may be incomplete.";
                }
                $this->controllerMiddleware($controller, $method->name->toString(), $evidence, $map, $inspector, $signals, $unresolved, $stack);
                $requestVars = $this->parameters($owner, $method, $index, $evidence, $pathParams, $fields, $signals, $dependencies, $unresolved, $responses);
                if (($check = $inspector->action($owner, $method, $requestVars[0] ?? null)) !== null) {
                    $signals[] = [
                        'kind' => $check['kind'], 'verdict' => $check['verdict'], 'middleware' => null, 'class' => $owner->symbol($method->name->toString()), 'from' => 'action',
                        'status' => $check['status'], 'conditional' => false, 'credentials' => $check['credentials'], 'rejects' => $check['rejects'], 'note' => $check['note'], 'evidence' => $check['evidence'],
                    ];
                }
                $this->inlineValidation($controller, $owner, $method, $index, $evidence, $requestVars, $fields, $unresolved, $responses);
                $this->inputReads($owner, $method, $evidence, $requestVars, $fields, $headers, $unresolved);
                $responses->extract($owner, $method);

                foreach (array_unique($responses->resources) as $resource) {
                    $dependencies[] = ['kind' => 'resource', 'class' => $resource];
                }
                if ($responses->responses === []) {
                    $unresolved[] = 'No return value of the action could be read; the response is unknown.';
                }
            }
        }

        [$authStatus, $authReason] = self::authStatus($signals);
        $this->credentialsToContract($signals, $headers, $fields);
        $this->standardResponses($signals, $responses);
        $stated = array_column(array_filter($responses->responses, fn ($r) => $r['kind'] !== 'framework'), 'status');
        // A status the code states itself replaces Laravel's inferred one (e.g. abort(404) and a model binding).
        $sorted = array_values(array_filter($responses->responses, fn ($r) => $r['kind'] !== 'framework' || ! in_array($r['status'], $stated, true)));
        // By status, code-stated before framework-inferred, unknown statuses last.
        usort($sorted, fn ($a, $b) => [$a['status'] ?? 999, $a['kind'] === 'framework'] <=> [$b['status'] ?? 999, $b['kind'] === 'framework']);

        // The full contract: payload, curl, and every success and error body, followed through the app.
        $formRequest = array_values(array_filter($dependencies, fn ($d) => $d['kind'] === 'form_request'))[0]['class'] ?? null;
        $contract = (new ContractBuilder($index, $this->reader, $snapshot, $root))
            ->build($endpoint, $method === null ? null : $owner, $method, $controller, $fields, array_values($headers), $stack, $signals, $formRequest, $recorder);

        $graph = null;
        if ($recorder !== null) {
            // The route and its FormRequest, as the router sees them; the rest was recorded while following the action.
            $routeId = 'route:'.$endpoint->route_identity;
            $recorder->node($routeId, ['type' => 'route', 'label' => trim(implode('|', (array) $endpoint->methods).' '.$endpoint->uri)]);
            $recorder->root($routeId);
            $recorder->at($endpoint->source_path, $routeSha, null, max(1, (int) $endpoint->source_line), max(1, (int) $endpoint->source_line));
            if ($controller !== null && $method !== null) {
                $actionId = $controller->fqcn.'::'.$method->name->toString();
                $recorder->node($actionId, ['type' => 'controller', 'label' => class_basename($controller->fqcn).'::'.$method->name->toString(), 'class' => $controller->fqcn, 'method' => $method->name->toString()]);
                $recorder->edge($actionId, 'handled_by');
                $recorder->end();
                if ($formRequest !== null) {
                    $recorder->root($actionId);
                    $recorder->at($owner->file->path, $owner->sha256, $owner->symbol($method->name->toString()), $method->getStartLine(), $method->getStartLine());
                    $recorder->node($formRequest, ['type' => 'form_request', 'label' => class_basename($formRequest), 'class' => $formRequest]);
                    $recorder->edge($formRequest, 'validates_with');
                    $recorder->end();
                }
            } else {
                $recorder->unresolved('no_action', $endpoint->handler_type === 'closure' ? 'Inline closure route: only controller actions are traced' : 'No controller action was found to trace', null, null, 'handled_by');
                $recorder->end();
            }
            $graph = $recorder->graph((bool) ($contract['budget_reached'] ?? false), ['depth' => Interpreter::MAX_DEPTH, 'steps' => Interpreter::MAX_STEPS]);
            $graph['root'] = $routeId;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'snapshot_id' => $snapshot->id,
            'endpoint_id' => $endpoint->id,
            'route_identity' => $endpoint->route_identity,
            'analyzer_version' => self::VERSION,
            'request' => ['headers' => array_values($headers), 'fields' => $fields],
            'responses' => $sorted,
            'auth' => ['status' => $authStatus, 'reason' => $authReason, 'signals' => $signals],
            'dependencies' => $dependencies,
            'evidence' => $evidence->all(),
            'unresolved' => array_values(array_unique($unresolved)),
            'contract' => $contract,
            ...($graph === null ? [] : ['graph' => $graph]),
            'status' => $method !== null && $unresolved === [] ? 'completed' : 'partial',
        ];
    }

    /**
     * $this->middleware('auth') in the constructor, or the static middleware() of HasMiddleware.
     *
     * @param  list<array<string, mixed>>  $signals
     * @param  list<string>  $unresolved
     * @param  list<array<string, mixed>>  $stack  middleware layers; controller middleware that applies is appended
     */
    private function controllerMiddleware(ClassInfo $class, string $action, Evidence $evidence, MiddlewareMap $map, MiddlewareInspector $inspector, array &$signals, array &$unresolved, array &$stack): void
    {
        $resolver = $class->file->resolver();

        foreach (['__construct', 'middleware'] as $name) {
            $method = $class->method($name);
            if ($method === null) {
                continue;
            }

            $calls = (new NodeFinder)->find($method->stmts ?? [], fn (Node $n) => ($n instanceof Expr\MethodCall && $n->var instanceof Expr\Variable && $n->var->name === 'this'
                && $n->name instanceof Node\Identifier && strtolower($n->name->toString()) === 'middleware')
                || ($n instanceof Expr\New_ && $n->class instanceof Node\Name && strtolower($n->class->getLast()) === 'middleware')
                || ($n instanceof Stmt\Return_ && $n->expr instanceof Expr\Array_));

            foreach ($calls as $call) {
                $items = $call instanceof Stmt\Return_ && $call->expr instanceof Expr\Array_
                    ? array_filter(array_map(fn ($i) => $resolver->resolve($i->value), $call->expr->items), 'is_string')
                    : (array) ($call instanceof Expr\MethodCall || $call instanceof Expr\New_ ? $resolver->arg($call->args, 0) : null);
                $items = array_values(array_filter($items, 'is_string'));

                if ($items === []) {
                    $call instanceof Stmt\Return_ || $unresolved[] = "Controller middleware in {$class->symbol($name)} is computed at runtime (line {$call->getStartLine()}).";

                    continue;
                }

                $scoped = $this->appliesTo($call, $method->stmts ?? [], $action, $resolver);
                if ($scoped === false) {
                    continue;
                }

                $ev = [$evidence->add($class->file->path, $class->sha256, $class->symbol($name), $call, 'middleware')];
                foreach ($items as $item) {
                    foreach ($map->resolve($item, 'controller') as $entry) {
                        $signals[] = $this->signal($entry, $inspector, $ev, $scoped === null);
                        $scoped === true && $stack[] = $entry;
                    }
                }
            }
        }
    }

    /**
     * only/except scoping of controller middleware: true = applies, false = doesn't, null = can't tell.
     *
     * @param  array<Node>  $stmts
     */
    private function appliesTo(Node $call, array $stmts, string $action, ValueResolver $resolver): ?bool
    {
        $action = strtolower($action);

        // new Middleware('auth', only: ['store'])
        if ($call instanceof Expr\New_) {
            $only = $resolver->arg($call->args, 1, 'only');
            $except = $resolver->arg($call->args, 2, 'except');

            return self::scope($only, $except, $action);
        }

        // $this->middleware('auth')->only('store') — the chain wraps the call.
        foreach ((new NodeFinder)->find($stmts, fn (Node $n) => $n instanceof Expr\MethodCall && $n->var === $call) as $chain) {
            /** @var Expr\MethodCall $chain */
            $which = $chain->name instanceof Node\Identifier ? strtolower($chain->name->toString()) : '';
            $value = $resolver->arg($chain->args, 0);

            return self::scope($which === 'only' ? $value : null, $which === 'except' ? $value : null, $action);
        }

        return true;
    }

    private static function scope(mixed $only, mixed $except, string $action): ?bool
    {
        foreach (['only' => $only, 'except' => $except] as $kind => $list) {
            if ($list === null) {
                continue;
            }
            $names = array_map('strtolower', array_filter((array) $list, 'is_string'));
            if (count($names) !== count((array) $list)) {
                return null;
            }
            $listed = in_array($action, $names, true);

            return $kind === 'only' ? $listed : ! $listed;
        }

        return true;
    }

    /**
     * Typed parameters: a FormRequest's rules()/authorize(), route-model bindings, and which
     * variables hold the request.
     *
     * @param  list<string>  $pathParams
     * @param  list<array<string, mixed>>  $fields
     * @param  list<array<string, mixed>>  $signals
     * @param  list<array<string, string>>  $dependencies
     * @param  list<string>  $unresolved
     * @return list<string> variable names holding the request
     */
    private function parameters(ClassInfo $owner, Stmt\ClassMethod $method, ClassIndex $index, Evidence $evidence, array $pathParams, array &$fields, array &$signals, array &$dependencies, array &$unresolved, ResponseExtractor $responses): array
    {
        $requestVars = [];
        $symbol = $owner->symbol($method->name->toString());

        foreach ($method->params as $param) {
            $var = $param->var instanceof Expr\Variable && is_string($param->var->name) ? $param->var->name : null;
            $type = $param->type instanceof Node\Name ? ClassInfo::name($param->type) : null;
            if ($var === null || $type === null) {
                continue;
            }

            if (in_array($type, ['Illuminate\Http\Request', 'Request'], true)) {
                $requestVars[] = $var;

                continue;
            }

            $class = $index->find($type);

            if ($class !== null && $index->extends($class, self::FORM_REQUEST) === true) {
                $requestVars[] = $var;
                $dependencies[] = ['kind' => 'form_request', 'class' => $class->fqcn];
                $this->formRequest($class, $index, $evidence, $fields, $signals, $unresolved);
                $responses->validation($class);

                continue;
            }

            if (in_array($var, $pathParams, true)) {
                // Implicit route-model binding: Laravel answers 404 when no row matches.
                $ev = [$evidence->add($owner->file->path, $owner->sha256, $symbol, $param, 'binding')];
                foreach ($fields as &$f) {
                    if ($f['location'] === 'path' && $f['name'] === $var) {
                        $f['binds'] = $type;
                        $f['evidence'] = [...$f['evidence'], ...$ev];
                    }
                }
                unset($f);
                $responses->standard(404, "Laravel returns 404 when no {$type} matches {{$var}}");
            } elseif ($class === null && str_ends_with($type, 'Request')) {
                $unresolved[] = "Request class {$type} was not found in this snapshot; its rules are unknown.";
            }
        }

        return $requestVars;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  list<array<string, mixed>>  $signals
     * @param  list<string>  $unresolved
     */
    private function formRequest(ClassInfo $class, ClassIndex $index, Evidence $evidence, array &$fields, array &$signals, array &$unresolved): void
    {
        $rules = $index->method($class, 'rules');

        if ($rules === null) {
            $unresolved[] = "{$class->fqcn} has no rules() that can be read.";
        } else {
            [$owner, $m] = $rules;
            [$owner, $m] = $this->delegatedRulesMethod($owner, $m);
            $extractor = new RuleExtractor($owner->file, $evidence, $owner->sha256, $owner->symbol($m->name->toString()), 'form_request');
            foreach ($extractor->fromRulesMethod($m) as $f) {
                $fields[] = $f + ['location' => 'body'];
            }
            $unresolved = [...$unresolved, ...$extractor->unresolved];
        }

        $authorize = $index->method($class, 'authorize');
        if ($authorize !== null) {
            [$owner, $m] = $authorize;
            $returns = (new NodeFinder)->findInstanceOf($m->stmts ?? [], Stmt\Return_::class);
            $always = $returns !== [] && array_filter($returns, fn ($r) => ! ($r->expr instanceof Expr\ConstFetch && strtolower($r->expr->name->toString()) === 'true')) === [];

            if (! $always) {
                $signals[] = [
                    'kind' => 'authorization', 'verdict' => 'none', 'middleware' => null, 'class' => $class->fqcn, 'from' => 'form_request',
                    'status' => 'inferred', 'conditional' => true, 'credentials' => [], 'rejects' => [403],
                    'note' => "{$class->fqcn}::authorize() decides access at runtime",
                    'evidence' => [$evidence->add($owner->file->path, $owner->sha256, $owner->symbol('authorize'), $m, 'authorization')],
                ];
            }
        }
    }

    /**
     * Follow a simple `return self::helper($this)` / `return $this->helper()` rules delegate.
     *
     * @return array{ClassInfo, Stmt\ClassMethod}
     */
    private function delegatedRulesMethod(ClassInfo $owner, Stmt\ClassMethod $method): array
    {
        for ($depth = 0; $depth < 4; $depth++) {
            $stmts = $method->stmts ?? [];
            if (count($stmts) !== 1 || ! $stmts[0] instanceof Stmt\Return_ || ! $stmts[0]->expr instanceof Expr) {
                break;
            }

            $expr = $stmts[0]->expr;
            $name = null;
            if ($expr instanceof Expr\StaticCall && $expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier
                && in_array(strtolower($expr->class->toString()), ['self', 'static'], true)) {
                $name = $expr->name->toString();
            } elseif ($expr instanceof Expr\MethodCall && $expr->var instanceof Expr\Variable && $expr->var->name === 'this'
                && $expr->name instanceof Node\Identifier) {
                $name = $expr->name->toString();
            }

            $next = $name === null ? null : $owner->method($name);
            if ($next === null || $next === $method) {
                break;
            }
            $method = $next;
        }

        return [$owner, $method];
    }

    /**
     * $request->validate([...]), request()->validate, $this->validate($request, [...]),
     * Validator::make($data, [...]) and validator($data, [...]).
     *
     * @param  list<string>  $requestVars
     * @param  list<array<string, mixed>>  $fields
     * @param  list<string>  $unresolved
     */
    private function inlineValidation(ClassInfo $controller, ClassInfo $owner, Stmt\ClassMethod $method, ClassIndex $index, Evidence $evidence, array $requestVars, array &$fields, array &$unresolved, ResponseExtractor $responses): void
    {
        $symbol = $owner->symbol($method->name->toString());
        $finder = new NodeFinder;

        foreach ($finder->find($method->stmts ?? [], fn (Node $n) => $n instanceof Expr\MethodCall || $n instanceof Expr\StaticCall || $n instanceof Expr\FuncCall) as $call) {
            /** @var Expr\MethodCall|Expr\StaticCall|Expr\FuncCall $call */
            [$position, $throws] = match (true) {
                $call instanceof Expr\MethodCall && $call->name instanceof Node\Identifier && in_array(strtolower($call->name->toString()), ['validate', 'validatewithbag'], true) => match (true) {
                    $call->var instanceof Expr\Variable && $call->var->name === 'this' => [strtolower($call->name->toString()) === 'validate' ? 1 : 2, true],
                    $this->isRequest($call->var, $requestVars) => [strtolower($call->name->toString()) === 'validate' ? 0 : 1, true],
                    default => [null, false],
                },
                $call instanceof Expr\StaticCall && $call->class instanceof Node\Name && strtolower($call->class->getLast()) === 'validator'
                    && $call->name instanceof Node\Identifier && strtolower($call->name->toString()) === 'make' => [1, false],
                $call instanceof Expr\FuncCall && $call->name instanceof Node\Name && strtolower($call->name->toString()) === 'validator' && count($call->args) > 1 => [1, false],
                default => [null, false],
            };

            if ($position === null) {
                continue;
            }

            $arg = $call->args[$position] ?? null;
            if (! $arg instanceof Node\Arg) {
                $unresolved[] = "Validation call in {$symbol} has no readable rules argument (line {$call->getStartLine()}).";

                continue;
            }

            $source = $throws ? 'inline' : 'validator';
            $rules = $arg->value;

            // $this->rules() / $this->identifierRules(): a method of the controller returning the array.
            if ($rules instanceof Expr\MethodCall && $rules->var instanceof Expr\Variable && $rules->var->name === 'this'
                && $rules->name instanceof Node\Identifier && ($found = $index->method($controller, $rules->name->toString())) !== null) {
                [$ruleOwner, $ruleMethod] = $found;
                $extractor = new RuleExtractor($ruleOwner->file, $evidence, $ruleOwner->sha256, $ruleOwner->symbol($ruleMethod->name->toString()), $source);
                foreach ($extractor->fromRulesMethod($ruleMethod) as $f) {
                    $fields[] = $f + ['location' => 'body'];
                }
                $unresolved = [...$unresolved, ...$extractor->unresolved];
                $throws && $responses->standard(422, 'Laravel returns 422 when validate() fails');

                continue;
            }

            $names = array_values(array_unique(array_filter(array_map(
                fn (Expr\Variable $v) => is_string($v->name) ? $v->name : null,
                $finder->findInstanceOf([$rules], Expr\Variable::class),
            ))));
            $extractor = new RuleExtractor($owner->file, $evidence, $owner->sha256, $symbol, $source);
            $variables = $names === [] ? [] : $extractor->variablesIn($method->stmts ?? [], $names);
            foreach ($extractor->fromExpression($rules, $variables) as $f) {
                $fields[] = $f + ['location' => 'body'];
            }
            $unresolved = [...$unresolved, ...$extractor->unresolved];

            // validate() throws (422 for JSON); Validator::make leaves the answer to the code, which ResponseExtractor reads.
            $throws && $responses->standard(422, 'Laravel returns 422 when validate() fails');
        }
    }

    /**
     * Keys read from the request: $request->input('x'), ->query('x'), ->file('x'),
     * $request['x'], $request->x, request('x'), ->header('X').
     *
     * @param  list<string>  $requestVars
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, array<string, mixed>>  $headers
     * @param  list<string>  $unresolved
     */
    private function inputReads(ClassInfo $owner, Stmt\ClassMethod $method, Evidence $evidence, array $requestVars, array &$fields, array &$headers, array &$unresolved): void
    {
        $symbol = $owner->symbol($method->name->toString());
        $resolver = $owner->file->resolver();
        $known = array_map(fn ($f) => strtolower(explode('.', $f['name'])[0]), $fields);
        $read = [];
        $dynamic = 0;

        $add = function (string $name, string $location, string $status, Node $at, string $how) use (&$read, $known, $evidence, $owner, $symbol) {
            $key = strtolower($name);
            if (in_array($key, $known, true)) {
                return; // validation already describes it
            }
            $ev = $evidence->add($owner->file->path, $owner->sha256, $symbol, $at, 'input');
            if (isset($read[$key])) {
                $read[$key]['evidence'][] = $ev;
                // Read as both query() and input(): only the weaker claim holds.
                $read[$key]['location'] === $location || [$read[$key]['location'], $read[$key]['location_status']] = ['query_or_body', 'inferred'];

                return;
            }
            $read[$key] = self::field($name, $location, 'unknown', 'unknown', 'input_read', [$ev]) + ['location_status' => $status, 'read_via' => $how];
        };

        foreach ((new NodeFinder)->find($method->stmts ?? [], fn (Node $n) => $n instanceof Expr\MethodCall || $n instanceof Expr\FuncCall || $n instanceof Expr\ArrayDimFetch || $n instanceof Expr\PropertyFetch) as $node) {
            if ($node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier && $this->isRequest($node->var, $requestVars)) {
                $how = strtolower($node->name->toString());
                if (! in_array($how, self::INPUT_METHODS, true)) {
                    continue;
                }

                if ($how === 'bearertoken') {
                    $headers['authorization'] ??= ['name' => 'Authorization', 'value' => 'Bearer <token>', 'required' => 'unknown', 'status' => 'verified', 'reason' => 'read with bearerToken()', 'evidence' => [$evidence->add($owner->file->path, $owner->sha256, $symbol, $node, 'input')]];

                    continue;
                }

                $value = $resolver->arg($node->args, 0);
                $names = in_array($how, ['only', 'except'], true) && is_array($value) ? $value : [$value];
                foreach ($names as $name) {
                    if (! is_string($name)) {
                        $node->args === [] || $dynamic++;

                        continue;
                    }
                    if ($how === 'header') {
                        $headers[strtolower($name)] ??= ['name' => $name, 'value' => null, 'required' => 'unknown', 'status' => 'verified', 'reason' => 'read with header()', 'evidence' => [$evidence->add($owner->file->path, $owner->sha256, $symbol, $node, 'input')]];
                    } elseif ($how !== 'cookie') {
                        [$location, $status] = match ($how) {
                            'query' => ['query', 'verified'],
                            'post' => ['body', 'verified'],
                            'file', 'hasfile' => ['body', 'verified'],
                            default => ['query_or_body', 'inferred'],
                        };
                        $add($name, $location, $status, $node, $how);
                    }
                }
            } elseif ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name && strtolower($node->name->toString()) === 'request' && $node->args !== []) {
                $name = $resolver->arg($node->args, 0);
                is_string($name) ? $add($name, 'query_or_body', 'inferred', $node, 'request()') : $dynamic++;
            } elseif ($node instanceof Expr\ArrayDimFetch && $this->isRequest($node->var, $requestVars)) {
                $name = $node->dim === null ? null : $resolver->resolve($node->dim);
                is_string($name) ? $add($name, 'query_or_body', 'inferred', $node, 'array access') : $dynamic++;
            } elseif ($node instanceof Expr\PropertyFetch && $this->isRequest($node->var, $requestVars)) {
                if (! $node->name instanceof Node\Identifier) {
                    $dynamic++; // $request->$field / $request->{$expr}
                } elseif (! in_array(strtolower($node->name->toString()), self::NOT_FIELDS, true)) {
                    // $request->email also matches route parameters; Laravel checks input first.
                    $add($node->name->toString(), 'query_or_body', 'inferred', $node, 'property');
                }
            }
        }

        $dynamic > 0 && $unresolved[] = "{$symbol} reads {$dynamic} request value(s) by a computed name.";

        foreach ($read as $field) {
            $fields[] = $field;
        }
    }

    /** @param list<string> $requestVars */
    private function isRequest(Expr $expr, array $requestVars): bool
    {
        return ($expr instanceof Expr\Variable && is_string($expr->name) && in_array($expr->name, $requestVars, true))
            || ($expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name && strtolower($expr->name->toString()) === 'request' && $expr->args === []);
    }

    /**
     * @param  array{entry: string, class: string|null, params: list<string>, from: string, problem: string|null, evidence: list<string>}  $entry
     * @param  list<string>  $ev
     * @return array<string, mixed>
     */
    private function signal(array $entry, MiddlewareInspector $inspector, array $ev, bool $conditional): array
    {
        if ($entry['class'] === null) {
            return self::unknownSignal($entry['entry'], $entry['from'], $entry['problem'] ?? 'could not be resolved', [...$ev, ...$entry['evidence']], $conditional);
        }

        $key = strtolower($entry['class']).':'.implode(',', $entry['params']);
        $r = $this->inspected[$key] ??= $inspector->inspect($entry['class'], $entry['params']);

        return [
            'kind' => $r['kind'], 'verdict' => $r['verdict'], 'middleware' => $entry['entry'], 'class' => $entry['class'], 'from' => $entry['from'],
            'status' => $r['status'], 'conditional' => $conditional, 'credentials' => $r['credentials'], 'rejects' => $r['rejects'], 'note' => $r['note'],
            'evidence' => array_values(array_unique([...$ev, ...$entry['evidence'], ...$r['evidence']])),
        ];
    }

    /**
     * @param  list<string>  $evidence
     * @return array<string, mixed>
     */
    private static function unknownSignal(string $middleware, string $from, string $note, array $evidence, bool $conditional = false): array
    {
        return [
            'kind' => 'unknown', 'verdict' => 'unknown', 'middleware' => $middleware, 'class' => null, 'from' => $from,
            'status' => 'unknown', 'conditional' => $conditional, 'credentials' => [], 'rejects' => [], 'note' => $note, 'evidence' => $evidence,
        ];
    }

    private const AUTHENTICATING = ['authenticates', 'presence_only', 'guest_or_user'];

    /**
     * The endpoint's authentication, from what every middleware was found to do.
     *
     * @param  list<array<string, mixed>>  $signals
     * @return array{string, string}
     */
    private static function authStatus(array $signals): array
    {
        $label = fn (array $s) => $s['middleware'] ?? $s['class'] ?? $s['from'];
        $unconditional = array_filter($signals, fn ($s) => ! $s['conditional']);
        $first = fn (string $verdict) => array_values(array_filter($unconditional, fn ($s) => $s['verdict'] === $verdict))[0] ?? null;

        if ($s = $first('authenticates')) {
            return ['required', "{$label($s)} rejects requests without a verified credential".($s['note'] ? " ({$s['note']})" : '').'.'];
        }
        if ($s = $first('presence_only')) {
            return ['required_unverified', "{$label($s)} requires a credential to be sent, but {$s['note']}."];
        }
        if ($s = $first('guest_or_user')) {
            return ['guest_or_user', "{$label($s)} {$s['note']}."];
        }

        // Reading or sanitizing a token does not make a route authenticated. Only keep the
        // result uncertain when a credential-related path can actually reject the request,
        // or when known authentication middleware applies conditionally to this action.
        $doubts = array_values(array_filter($signals, fn ($s) => (
            in_array($s['verdict'], ['unknown', 'conditional'], true)
                && $s['credentials'] !== []
                && array_intersect($s['rejects'], [401, 403]) !== []
        ) || ($s['conditional'] && in_array($s['verdict'], self::AUTHENTICATING, true))
            || ($s['verdict'] === 'unknown' && (
                $s['middleware'] === 'global middleware'
                || $s['middleware'] === 'withoutMiddleware()'
                || str_starts_with((string) $s['middleware'], 'group ')
            ))));
        if ($doubts !== []) {
            $why = array_map(fn ($s) => $label($s).': '.($s['note'] ?? 'applies only to some actions'), array_slice($doubts, 0, 3));

            return ['unknown', 'Can\'t tell. '.implode('; ', $why).(count($doubts) > 3 ? '; and '.(count($doubts) - 3).' more' : '').'.'];
        }

        return ['not_required', 'No route middleware was found to require a bearer or authentication token.'];
    }

    /**
     * Credentials the authenticating middleware read become headers and fields of the contract.
     *
     * @param  list<array<string, mixed>>  $signals
     * @param  array<string, array<string, mixed>>  $headers
     * @param  list<array<string, mixed>>  $fields
     */
    private function credentialsToContract(array $signals, array &$headers, array &$fields): void
    {
        foreach ($signals as $s) {
            if (! in_array($s['verdict'], self::AUTHENTICATING, true)) {
                continue;
            }
            $required = $s['conditional'] || $s['verdict'] === 'guest_or_user' ? 'conditional' : 'required';
            $status = str_starts_with((string) $s['class'], 'Illuminate\\') ? 'inferred' : 'verified'; // guard drivers are config
            $reason = 'checked by '.($s['middleware'] ?? $s['class']);

            foreach ($s['credentials'] as $credential) {
                [$name, $value] = match (true) {
                    (bool) preg_match('/^Authorization: Bearer/', $credential) => ['Authorization', 'Bearer <token>'],
                    (bool) preg_match('/^Authorization: Basic/', $credential) => ['Authorization', 'Basic <credentials>'],
                    $credential === 'Authorization header' => ['Authorization', null],
                    (bool) preg_match('/^header (\S+)$/', $credential, $m) => [$m[1], null],
                    default => [null, null],
                };

                if ($name !== null) {
                    $existing = $headers[strtolower($name)] ?? null;
                    if ($existing === null || ($existing['required'] !== 'required' && $required === 'required')) {
                        $headers[strtolower($name)] = ['name' => $name, 'value' => $value ?? $existing['value'] ?? null, 'required' => $required, 'status' => $status, 'reason' => $reason, 'evidence' => $s['evidence']];
                    }

                    continue;
                }

                if (preg_match('/^request value (\S+)/', $credential, $m) && ! in_array(strtolower($m[1]), array_map(fn ($f) => strtolower($f['name']), $fields), true)) {
                    $fields[] = self::field($m[1], 'query_or_body', $required, 'verified', 'middleware', $s['evidence']) + ['location_status' => 'inferred', 'read_via' => $reason];
                }
            }
        }
    }

    /** @param  list<array<string, mixed>>  $signals */
    private function standardResponses(array $signals, ResponseExtractor $responses): void
    {
        foreach ([401 => 'answers 401 when the credential is missing or invalid', 403 => 'can refuse the request with 403', 429 => 'answers 429 when the rate limit is reached'] as $code => $why) {
            foreach ($signals as $s) {
                if ($s['status'] !== 'unknown' && in_array($code, $s['rejects'], true) && ($code !== 401 || in_array($s['verdict'], self::AUTHENTICATING, true))) {
                    $responses->standard($code, ($s['middleware'] ?? $s['class']).' '.$why);

                    break;
                }
            }
        }
    }

    /**
     * Guards and their drivers from config/auth.php, when they are fixed values.
     *
     * @return array{array<string, string>, string|null}
     */
    private function authConfig(SourceSnapshot $snapshot, string $root): array
    {
        $path = SnapshotPaths::join($root, 'config/auth.php');
        $code = $this->reader->read($snapshot, $path);
        if ($code === null || strlen($code) > 262144) {
            $code = '<?php return [];';
        }
        $file = ParsedFile::parse($path, mb_scrub($code, 'UTF-8'), $root);
        $return = (new NodeFinder)->findFirstInstanceOf($file->stmts, Stmt\Return_::class);
        $config = $return?->expr === null ? null : $file->resolver()->resolve($return->expr);
        $config = is_array($config) ? $config : [];

        $guards = [];
        foreach (is_array($config['guards'] ?? null) ? $config['guards'] : [] as $name => $guard) {
            $driver = is_array($guard) ? ($guard['driver'] ?? null) : null;
            $guards[(string) $name] = is_string($driver) ? $driver : '?';
        }
        $default = $config['defaults']['guard'] ?? null;

        // laravel/sanctum registers the "sanctum" guard itself (SanctumServiceProvider) unless the app overrides it.
        $composer = json_decode((string) $this->reader->read($snapshot, SnapshotPaths::join($root, 'composer.json')), true);
        if (! isset($guards['sanctum']) && is_array($composer) && isset($composer['require']['laravel/sanctum'])) {
            $guards['sanctum'] = 'sanctum';
        }

        return [$guards, is_string($default) ? $default : null];
    }

    /**
     * @param  list<string>  $evidence
     * @return array<string, mixed>
     */
    private static function field(string $name, string $location, string $required, string $status, string $source, array $evidence): array
    {
        return [
            'name' => $name, 'location' => $location, 'required' => $required, 'nullable' => false, 'sometimes' => false,
            'conditional' => false, 'types' => $location === 'path' ? ['string'] : [], 'formats' => [], 'constraints' => [],
            'rules' => [], 'status' => $status, 'source' => $source, 'evidence' => $evidence,
        ];
    }

    private function sha(SourceSnapshot $snapshot, string $path): ?string
    {
        $sha = SourceFile::query()->where('source_snapshot_id', $snapshot->id)->where('path_hash', hash('sha256', $path))->value('sha256');

        return is_string($sha) ? $sha : null;
    }
}
