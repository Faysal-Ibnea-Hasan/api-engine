<?php

namespace App\Domain\Analysis\Shape;

use App\Domain\Analysis\ClassIndex;
use App\Domain\Analysis\ClassInfo;
use App\Domain\Discovery\ParsedFile;
use App\Domain\Discovery\SnapshotPaths;
use App\Domain\Sources\SnapshotReader;
use App\Models\SourceSnapshot;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/**
 * Follows a request the way Laravel's HTTP kernel does, over shapes: the action's results,
 * exceptions rendered by the app's own renderers (or Laravel's defaults), and every response
 * passed back out through the middleware that wraps it. Produces each distinct response the
 * endpoint can send, with what triggers it.
 */
final class Pipeline
{
    /** Middleware whose body never changes (framework classes leave the JSON body alone). */
    private const PASS_THROUGH = 'illuminate\\';

    /** @var list<array{class: string, params: list<string>, label: string}> outermost first */
    private array $layers = [];

    /** @var list<Expr\Closure|Expr\ArrowFunction>|null */
    private ?array $renderers = null;

    private ?ClassInfo $bootstrap = null;

    public function __construct(
        private readonly Interpreter $interpreter,
        private readonly ClassIndex $index,
        private readonly SnapshotReader $reader,
        private readonly SourceSnapshot $snapshot,
        private readonly string $root,
    ) {}

    /** @param list<array{class: string, params: list<string>, label: string}> $layers outermost first */
    public function setLayers(array $layers): void
    {
        $this->layers = $layers;
    }

    /**
     * The responses this endpoint can send, after every layer.
     *
     * @param  list<array{value: array<string, mixed>, trigger: string, layer: int}>  $produced  responses/exceptions created at a layer
     *                                                                                           (layer = count($layers) for the action)
     * @return list<array{status: array<string, mixed>, body: array<string, mixed>, kind: string, trigger: string}>
     */
    public function send(array $produced): array
    {
        $out = [];
        foreach ($produced as $item) {
            foreach ($this->outward($item['value'], $item['layer'], $item['trigger']) as $response) {
                $out[] = $response;
            }
        }

        return $out;
    }

    /**
     * A response (or exception) created at $layer, carried out through the layers above it.
     *
     * @return list<array{status: array<string, mixed>, body: array<string, mixed>, kind: string, trigger: string}>
     */
    private function outward(array $value, int $layer, string $trigger): array
    {
        // Serializing what the action returned (its resources' toArray()) is part of the action's workflow.
        $rooted = $value['k'] !== 'exception' && $layer === count($this->layers) && $this->interpreter->recorder?->resume() === true;
        $responses = $value['k'] === 'exception'
            ? $this->render($value, $trigger)
            : [array_merge($this->interpreter->toResponse($value), ['trigger' => $trigger])];
        $rooted && $this->interpreter->recorder->end();

        for ($i = $layer - 1; $i >= 0; $i--) {
            $next = [];
            foreach ($responses as $response) {
                array_push($next, ...$this->through($i, $response));
            }
            $responses = $next;
        }

        return $responses;
    }

    /**
     * Pass one response back through layer $i: run its handle() with $next returning that response.
     *
     * @param  array{status: array<string, mixed>, body: array<string, mixed>, kind: string, trigger: string}  $response
     * @return list<array{status: array<string, mixed>, body: array<string, mixed>, kind: string, trigger: string}>
     */
    private function through(int $i, array $response): array
    {
        $layer = $this->layers[$i];
        if (str_starts_with(strtolower($layer['class']), self::PASS_THROUGH)) {
            return [$response];
        }
        $info = $this->index->find($layer['class']);
        $handle = $info === null ? null : $this->index->method($info, 'handle');
        if ($handle === null || ! $this->changesResponse($handle[1])) {
            return [$response];
        }

        $nextName = $handle[1]->params[1]->var ?? null;
        $nextName = $nextName instanceof Expr\Variable && is_string($nextName->name) ? $nextName->name : 'next';
        $carried = ['k' => 'response', 'status' => $response['status'], 'body' => $response['body'], 'origin' => 'pipeline'];
        $args = [['k' => 'request'], ['k' => 'closure-next'], ...array_map(fn ($p) => Shape::literal($p), $layer['params'])];
        $result = $this->interpreter->run($handle[0], $handle[1], $args, $this->interpreter->instantiate($layer['class']), 0, $nextName, $carried);

        $out = [];
        foreach ($result['returns'] as $return) {
            foreach ($return['k'] === 'union' ? $return['of'] : [$return] as $r) {
                // Only what came back from $next is this response; early returns are the middleware's own (seen elsewhere).
                if (($r['origin'] ?? null) === 'pipeline') {
                    $out[] = ['status' => $r['status'], 'body' => $this->interpreter->json($r['body']), 'kind' => $response['kind'], 'trigger' => $response['trigger']];
                }
            }
        }

        return $out === [] ? [$response] : $out;
    }

    /** Does handle() touch the response after $next (or return something else built from it)? */
    private function changesResponse(ClassMethod $handle): bool
    {
        $code = (new Standard)->prettyPrint($handle->stmts ?? []);

        return (bool) preg_match('/->(setData|setContent|setStatusCode|getData)\(|wrap\(|envelope/i', $code);
    }

    /**
     * Rejections a layer's middleware returns itself (before calling $next), carried outward.
     *
     * @return list<array{status: array<string, mixed>, body: array<string, mixed>, kind: string, trigger: string}>
     */
    public function rejections(int $i): array
    {
        $layer = $this->layers[$i];
        $info = $this->index->find($layer['class']);
        $handle = $info === null ? null : $this->index->method($info, 'handle');
        if ($handle === null) {
            return [];
        }
        $nextName = $handle[1]->params[1]->var ?? null;
        $nextName = $nextName instanceof Expr\Variable && is_string($nextName->name) ? $nextName->name : 'next';
        $marker = ['k' => 'response', 'status' => Shape::literal(200), 'body' => Shape::null(), 'origin' => 'pipeline'];
        $args = [['k' => 'request'], ['k' => 'closure-next'], ...array_map(fn ($p) => Shape::literal($p), $layer['params'])];
        $result = $this->interpreter->run($handle[0], $handle[1], $args, $this->interpreter->instantiate($layer['class']), 0, $nextName, $marker);

        $produced = [];
        foreach ($result['returns'] as $return) {
            foreach ($return['k'] === 'union' ? $return['of'] : [$return] as $r) {
                if (($r['origin'] ?? null) !== 'pipeline' && in_array($r['k'], ['response', 'instance', 'object', 'list'], true)) {
                    $produced[] = ['value' => $r, 'trigger' => "{$layer['label']} refuses the request", 'layer' => $i];
                }
            }
        }
        foreach ($result['throws'] as $exception) {
            $produced[] = ['value' => $exception, 'trigger' => "{$layer['label']} throws ".class_basename($exception['class'] ?? 'an exception'), 'layer' => $i];
        }

        // The layer's own response is created inside layer $i, so it still passes the layers above it.
        $out = [];
        foreach ($produced as $p) {
            array_push($out, ...$this->outward($p['value'], $p['layer'], $p['trigger']));
        }

        return $out;
    }

    /**
     * An exception rendered the way the app renders it: its withExceptions()->render() callbacks
     * first, then Laravel's default JSON rendering.
     *
     * @return list<array{status: array<string, mixed>, body: array<string, mixed>, kind: string, trigger: string}>
     */
    public function render(array $exception, string $trigger): array
    {
        $out = [];
        $handled = false;
        foreach ($this->renderers() as $renderer) {
            $type = $renderer->params[0]->type ?? null;
            if ($type instanceof Node\Name && ! $this->interpreter->isA($exception['class'], ClassInfo::name($type))
                && ! in_array(strtolower(class_basename(ClassInfo::name($type))), ['throwable', 'exception'], true)) {
                continue;
            }
            $result = $this->interpreter->run($this->bootstrap(), $renderer, [$exception, ['k' => 'request']]);
            $mayFallThrough = false;
            foreach ($result['returns'] as $return) {
                foreach ($return['k'] === 'union' ? $return['of'] : [$return] as $r) {
                    if ($r['k'] === 'scalar' && $r['types'] === ['null']) {
                        $mayFallThrough = true;

                        continue;
                    }
                    $out[] = array_merge($this->interpreter->toResponse($r), ['trigger' => $trigger]);
                }
            }
            if ($out !== [] && ! $mayFallThrough) {
                $handled = true;

                break;
            }
        }

        return $handled ? $out : [...$out, array_merge($this->defaultRender($exception), ['trigger' => $trigger])];
    }

    /**
     * Laravel's JSON rendering of an exception in production (APP_DEBUG off).
     * Kept in step with the installed framework by FrameworkRenderingParityTest.
     *
     * @return array{status: array<string, mixed>, body: array<string, mixed>, kind: string}
     */
    public function defaultRender(array $exception): array
    {
        $class = strtolower($exception['class'] ?? '');
        $short = class_basename($class);
        $json = fn (int $status, array $body) => ['status' => Shape::literal($status), 'body' => $body, 'kind' => 'json'];
        $message = fn (array $m) => Shape::object(['message' => $m]);

        return match (true) {
            $short === 'httpresponseexception' => $this->interpreter->toResponse($exception['response'] ?? Shape::unknown('response')),
            $short === 'validationexception' || $this->interpreter->isA($class, 'Illuminate\Validation\ValidationException') => $json(422, Shape::object([
                'message' => Shape::string(),
                'errors' => $exception['errors'] ?? ['k' => 'map', 'value' => Shape::list(Shape::string())],
            ])),
            $short === 'authenticationexception' => $json(401, $message(Shape::literal('Unauthenticated.'))),
            $short === 'authorizationexception' => $json(403, $message(Shape::union([$exception['message'], Shape::literal('This action is unauthorized.')]))),
            $short === 'modelnotfoundexception' => $json(404, $message(Shape::string())),
            $short === 'throttlerequestsexception' => $json(429, $message(Shape::literal('Too Many Attempts.'))),
            $short === 'notfoundhttpexception', $this->interpreter->isA($class, 'Symfony\Component\HttpKernel\Exception\HttpException')
                || $short === 'httpexception' => $json($exception['status'] ?? ($short === 'notfoundhttpexception' ? 404 : 500), $message($exception['message'])),
            default => $json(500, $message(Shape::literal('Server Error'))),
        };
    }

    /** @return list<Expr\Closure|Expr\ArrowFunction> render callbacks registered in bootstrap/app.php */
    private function renderers(): array
    {
        if ($this->renderers !== null) {
            return $this->renderers;
        }
        $this->renderers = [];
        $file = $this->bootstrap()->file;
        $with = (new NodeFinder)->findFirst($file->stmts, fn (Node $n) => $n instanceof Expr\MethodCall && $n->name instanceof Node\Identifier
            && strtolower($n->name->toString()) === 'withexceptions');
        $closure = $with instanceof Expr\MethodCall && ($with->args[0] ?? null) instanceof Node\Arg ? $with->args[0]->value : null;
        if (! $closure instanceof Expr\Closure) {
            return $this->renderers;
        }
        foreach ((new NodeFinder)->find($closure->stmts, fn (Node $n) => $n instanceof Expr\MethodCall && $n->name instanceof Node\Identifier
            && strtolower($n->name->toString()) === 'render') as $call) {
            /** @var Expr\MethodCall $call */
            $callback = ($call->args[0] ?? null) instanceof Node\Arg ? $call->args[0]->value : null;
            if ($callback instanceof Expr\Closure || $callback instanceof Expr\ArrowFunction) {
                $this->renderers[] = $callback;
            }
        }

        return $this->renderers;
    }

    private function bootstrap(): ClassInfo
    {
        if ($this->bootstrap === null) {
            $path = SnapshotPaths::join($this->root, 'bootstrap/app.php');
            $code = (string) $this->reader->read($this->snapshot, $path);
            $this->bootstrap = new ClassInfo('bootstrap', ParsedFile::parse($path, mb_scrub($code, 'UTF-8'), $this->root), new Class_('Bootstrap'), null);
        }

        return $this->bootstrap;
    }
}
