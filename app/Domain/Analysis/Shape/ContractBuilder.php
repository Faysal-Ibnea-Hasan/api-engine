<?php

namespace App\Domain\Analysis\Shape;

use App\Domain\Analysis\ClassIndex;
use App\Domain\Analysis\ClassInfo;
use App\Domain\Sources\SnapshotReader;
use App\Models\ApiEndpoint;
use App\Models\SourceSnapshot;
use PhpParser\Node\Stmt;

/**
 * The endpoint's full contract: the request payload and a curl command, every success body
 * and every error body, each with the status code and what triggers it. Built by following the
 * request through the app's own middleware, controller and exception renderers (Pipeline).
 */
final class ContractBuilder
{
    public function __construct(
        private readonly ClassIndex $index,
        private readonly SnapshotReader $reader,
        private readonly SourceSnapshot $snapshot,
        private readonly string $root,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $fields  request fields from the analysis
     * @param  list<array<string, mixed>>  $headers  request headers from the analysis
     * @param  array<array<string, mixed>>  $stack  middleware entries (entry, class, params), outermost first
     * @param  list<array<string, mixed>>  $signals  auth signals (to know which layers authenticate)
     * @param  Recorder|null  $recorder  advanced profile: records the action's workflow graph (it only observes)
     * @return array<string, mixed>
     */
    public function build(ApiEndpoint $endpoint, ?ClassInfo $owner, ?Stmt\ClassMethod $method, ?ClassInfo $controller, array $fields, array $headers, array $stack, array $signals, ?string $formRequest, ?Recorder $recorder = null): array
    {
        $interpreter = new Interpreter($this->index, $this->reader, $this->snapshot, $this->root);
        $interpreter->recorder = $recorder;
        $interpreter->requestPath = ltrim((string) preg_replace('/\{\w+\??\}/', '1', $endpoint->uri), '/');
        $interpreter->inputs = self::inputs($fields);
        $pipeline = new Pipeline($interpreter, $this->index, $this->reader, $this->snapshot, $this->root);

        $layers = [];
        foreach ($stack as $entry) {
            $entry['class'] !== null && $layers[] = ['class' => $entry['class'], 'params' => $entry['params'], 'label' => $entry['entry']];
        }
        $pipeline->setLayers($layers);
        $action = count($layers);
        $produced = [];

        // Middleware first, as Laravel runs it: its own refusals, and what it attaches to the request
        // ($request['vendor'] = …) for the action to read.
        $rejections = [];
        foreach (array_keys($layers) as $i) {
            if (! str_starts_with(strtolower($layers[$i]['class']), 'illuminate\\')) {
                array_push($rejections, ...$pipeline->rejections($i));
            }
        }

        // What the action itself returns and throws.
        if ($owner !== null && $method !== null && $controller !== null) {
            $args = [];
            foreach ($fields as $f) {
                if ($f['location'] === 'path') {
                    $args[$f['name']] = isset($f['binds']) ? ['k' => 'model', 'class' => $f['binds'], 'with' => []] : Shape::string();
                }
            }
            $self = $interpreter->instantiate($controller->fqcn);
            $recorder?->root($interpreter->methodNode($controller, $method->name->toString(), 'controller'));
            $result = $interpreter->run($owner, $method, $args, $self);
            $recorder?->end();
            foreach ($result['returns'] as $return) {
                foreach ($return['k'] === 'union' ? $return['of'] : [$return] as $r) {
                    $produced[] = ['value' => $r, 'trigger' => 'the action returns this', 'layer' => $action];
                }
            }
            foreach ($result['throws'] as $exception) {
                $produced[] = ['value' => $exception, 'trigger' => self::throwTrigger($exception), 'layer' => $action];
            }
        }

        // Framework failures at the layer that raises them.
        foreach ($layers as $i => $layer) {
            $class = strtolower($layer['class']);
            $isAuth = $interpreter->isA($layer['class'], 'Illuminate\Auth\Middleware\Authenticate');
            if ($isAuth && ($this->index->find($layer['class']) === null || $this->index->method($this->index->find($layer['class']), 'handle') === null
                || $this->index->method($this->index->find($layer['class']), 'handle')[0]->fqcn !== $layer['class'])) {
                $produced[] = ['value' => self::exception('Illuminate\Auth\AuthenticationException', 401, Shape::literal('Unauthenticated.')),
                    'trigger' => "no valid credentials ({$layer['label']})", 'layer' => $i];
            }
            if (str_contains($class, 'throttlerequests')) {
                $produced[] = ['value' => self::exception('Illuminate\Http\Exceptions\ThrottleRequestsException', 429, Shape::literal('Too Many Attempts.')),
                    'trigger' => "too many requests ({$layer['label']})", 'layer' => $i];
            }
            if (str_contains($class, 'substitutebindings')) {
                foreach ($fields as $f) {
                    if (($f['binds'] ?? null) !== null) {
                        $produced[] = ['value' => self::exception('Illuminate\Database\Eloquent\ModelNotFoundException', 404, Shape::string()) + ['model' => $f['binds']],
                            'trigger' => "no {$f['binds']} matches {{$f['name']}}", 'layer' => $i];
                    }
                }
            }
        }

        // Failures any endpoint can hit, rendered by the app like any other exception.
        $global = count(array_filter($stack, fn ($e) => ($e['from'] ?? '') === 'global'));
        foreach ($layers as $i => $layer) {
            if ($interpreter->isA($layer['class'], 'Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance')) {
                $produced[] = ['value' => self::exception('Symfony\Component\HttpKernel\Exception\HttpException', 503, Shape::literal('Service Unavailable')),
                    'trigger' => 'the app is in maintenance mode', 'layer' => $i];
            }
        }
        $produced[] = ['value' => self::exception('Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException', 405, Shape::string()),
            'trigger' => 'the URL is called with another HTTP method', 'layer' => $global];
        $produced[] = ['value' => self::exception('Exception', 500, Shape::string()) + ['status' => null],
            'trigger' => 'an unexpected server error (e.g. the database is unreachable)', 'layer' => $action];

        // Validation failures: a FormRequest (through its failedValidation override, if any) or validate().
        $validated = array_values(array_filter($fields, fn ($f) => in_array($f['source'] ?? '', ['form_request', 'inline'], true)));
        if ($validated !== []) {
            array_push($produced, ...$this->validationFailure($interpreter, $validated, $formRequest, $action));
        }

        $responses = [...$pipeline->send($produced), ...$rejections];

        [$success, $errors] = self::group($responses);

        return [
            'payload' => self::payload($endpoint, $fields),
            'curl' => self::curl($endpoint, $fields, $headers),
            'success' => $success,
            'errors' => $errors,
            'budget_reached' => $interpreter->budgetHit,
        ];
    }

    /** @return list<array{value: array<string, mixed>, trigger: string, layer: int}> */
    private function validationFailure(Interpreter $interpreter, array $validated, ?string $formRequest, int $layer): array
    {
        $errors = [];
        foreach ($validated as $f) {
            $errors[$f['name']] = Shape::list(Shape::string());
        }
        $errorsShape = Shape::object($errors, array_keys($errors)); // only failing fields appear
        $exception = self::exception('Illuminate\Validation\ValidationException', 422, Shape::string()) + ['errors' => $errorsShape];
        $trigger = 'the input fails validation';

        if ($formRequest !== null && ($info = $this->index->find($formRequest)) !== null && ($override = $this->index->method($info, 'failedValidation')) !== null) {
            $validator = ['k' => 'validator', 'data' => ['k' => 'map', 'value' => Shape::unknown('input')], 'messages' => $errorsShape];
            $result = $interpreter->run($override[0], $override[1], [$validator], $interpreter->instantiate($formRequest));
            $out = [];
            foreach ($result['throws'] as $thrown) {
                $out[] = ['value' => $thrown, 'trigger' => $trigger, 'layer' => $layer];
            }

            return $out === [] ? [['value' => $exception, 'trigger' => $trigger, 'layer' => $layer]] : $out;
        }

        return [['value' => $exception, 'trigger' => $trigger, 'layer' => $layer]];
    }

    private static function exception(string $class, int $status, array $message): array
    {
        return ['k' => 'exception', 'class' => $class, 'status' => $status, 'message' => $message];
    }

    private static function throwTrigger(array $exception): string
    {
        $class = class_basename($exception['class'] ?? 'exception');

        return match (true) {
            in_array($class, ['HttpException', 'NotFoundHttpException'], true) && isset($exception['status']) => "the action aborts with {$exception['status']}",
            $class === 'ModelNotFoundException' => 'a record the action looks up does not exist',
            default => "the action throws {$class}",
        };
    }

    /**
     * Distinct responses grouped by status: success (< 400) and errors.
     *
     * @param  list<array{status: array<string, mixed>, body: array<string, mixed>, kind: string, trigger: string}>  $responses
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private static function group(array $responses): array
    {
        $byKey = [];
        foreach ($responses as $r) {
            $statuses = $r['status']['k'] === 'union' ? $r['status']['of'] : [$r['status']];
            foreach ($statuses as $status) {
                [$known, $code] = Shape::known($status);
                $code = $known && is_int($code) ? $code : null;
                $key = ($code ?? 'x').'#'.md5(json_encode($r['body']) ?: '');
                if (isset($byKey[$key])) {
                    $byKey[$key]['triggers'] = array_values(array_unique([...$byKey[$key]['triggers'], $r['trigger']]));

                    continue;
                }
                $example = Examples::of($r['body']);
                $byKey[$key] = [
                    'status' => $code,
                    'status_source' => $code === null ? 'unread' : 'exact',
                    'kind' => $r['kind'],
                    'triggers' => [$r['trigger']],
                    'body' => $r['body'],
                    'example' => $example['example'],
                    'notes' => $example['notes'],
                    'holes' => Shape::holes($r['body']),
                ];
            }
        }

        $all = array_values($byKey);
        usort($all, fn ($a, $b) => [$a['status'] ?? 999, $a['triggers'][0]] <=> [$b['status'] ?? 999, $b['triggers'][0]]);

        return [
            array_values(array_filter($all, fn ($r) => $r['status'] !== null && $r['status'] < 400)),
            array_values(array_filter($all, fn ($r) => $r['status'] === null || $r['status'] >= 400)),
        ];
    }

    /** Request input shapes, typed by the validation rules, for $request->input('x') reads. */
    private static function inputs(array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            if ($f['location'] === 'path') {
                continue;
            }
            $types = array_map(fn ($t) => match ($t) {
                'number' => 'number', 'integer' => 'integer', 'boolean' => 'boolean', default => 'string'
            }, $f['types'] ?: ['string']);
            $types = array_values(array_unique(array_diff($types, ['array', 'file'])) ?: ['string']);
            if (($f['required'] ?? 'optional') !== 'required' || ($f['nullable'] ?? false)) {
                $types[] = 'null';
            }
            $out[$f['name']] = Shape::scalar($types);
        }

        return $out;
    }

    /**
     * The request payload as an example, from the validation rules. Path params go in the URL,
     * GET/DELETE fields in the query string, files make the body multipart.
     *
     * @return array<string, mixed>
     */
    private static function payload(ApiEndpoint $endpoint, array $fields): array
    {
        $method = self::method($endpoint);
        $body = [];
        $notes = [];
        $multipart = false;
        foreach ($fields as $f) {
            if ($f['location'] === 'path') {
                continue;
            }
            $value = self::fieldExample($f);
            in_array('file', $f['types'] ?? [], true) && $multipart = true;
            self::put($body, explode('.', $f['name']), $value);
            $notes[] = [
                'path' => '$.'.$f['name'],
                'required' => $f['required'] ?? 'optional',
                'types' => $f['types'] ?: ['string'],
                'source' => isset($f['rules']) && $f['rules'] !== [] ? 'rules' : 'read',
                'rules' => array_map(fn ($r) => $r['rule'].($r['params'] !== [] ? ':'.implode(',', $r['params']) : ''), $f['rules'] ?? []),
            ];
        }
        $location = in_array($method, ['GET', 'HEAD', 'DELETE'], true) ? 'query' : ($multipart ? 'multipart' : 'json');

        return ['location' => $location, 'example' => $body === [] ? new \stdClass : $body, 'notes' => $notes];
    }

    /** @param list<string> $path */
    private static function put(array &$body, array $path, mixed $value): void
    {
        $key = array_shift($path);
        if ($key === null) {
            return;
        }
        if ($path === []) {
            $body[$key === '*' ? 0 : $key] ??= $value;

            return;
        }
        $slot = $key === '*' ? 0 : $key;
        if (! isset($body[$slot]) || ! is_array($body[$slot])) {
            $body[$slot] = [];
        }
        self::put($body[$slot], $path, $value);
    }

    private static function fieldExample(array $f): mixed
    {
        $rules = [];
        foreach ($f['rules'] ?? [] as $r) {
            $rules[$r['rule']] = $r['params'];
        }
        $types = $f['types'] ?? [];
        $name = strtolower((string) last(explode('.', $f['name'])));

        return match (true) {
            isset($rules['In']) && $rules['In'] !== [] => self::typed($rules['In'][0], $types),
            in_array('file', $types, true) => '@/path/to/'.($name !== '*' ? $name : 'file').(isset($rules['Image']) || str_contains($name, 'image') ? '.jpg' : '.pdf'),
            in_array('array', $types, true) => [],
            in_array('boolean', $types, true) => true,
            in_array('integer', $types, true) || in_array('number', $types, true) => self::number($rules, in_array('integer', $types, true)),
            in_array('email', $f['formats'] ?? [], true) => 'user@example.com',
            in_array('url', $f['formats'] ?? [], true) => 'https://example.com',
            in_array('date', $f['formats'] ?? [], true) => isset($rules['DateFormat'][0]) ? date($rules['DateFormat'][0], 1767225600) : '2026-01-01',
            in_array('uuid', $f['formats'] ?? [], true) => '9b2f6c1e-1d2a-4c3b-8e4f-5a6b7c8d9e0f',
            default => Examples::scalar(['string'], $name),
        };
    }

    private static function typed(string $value, array $types): mixed
    {
        return in_array('integer', $types, true) && is_numeric($value) ? (int) $value : $value;
    }

    private static function number(array $rules, bool $integer): int|float
    {
        $min = $rules['Min'][0] ?? $rules['Between'][0] ?? $rules['Gte'][0] ?? null;
        $value = is_numeric($min) ? (float) $min : 1;
        $value = max($value, isset($rules['Gt'][0]) && is_numeric($rules['Gt'][0]) ? (float) $rules['Gt'][0] + 1 : $value);

        return $integer || floor($value) === $value ? (int) $value : $value;
    }

    private static function method(ApiEndpoint $endpoint): string
    {
        return array_values(array_filter($endpoint->methods, fn ($m) => $m !== 'HEAD'))[0] ?? 'GET';
    }

    /** A complete curl command; {{base_url}} is the only placeholder (set per project in the UI). */
    private static function curl(ApiEndpoint $endpoint, array $fields, array $headers): string
    {
        $method = self::method($endpoint);
        $uri = $endpoint->uri;
        foreach ($fields as $f) {
            if ($f['location'] === 'path') {
                $uri = (string) preg_replace('/\{'.preg_quote($f['name'], '/').'\??\}/', isset($f['binds']) ? '1' : (string) Examples::scalar(['string'], $f['name']), $uri);
            }
        }
        $payload = self::payload($endpoint, $fields);
        $url = '{{base_url}}'.$uri;
        if ($payload['location'] === 'query' && $payload['example'] instanceof \stdClass === false && $payload['example'] !== []) {
            $url .= '?'.http_build_query($payload['example']);
        }

        $lines = ["curl -X {$method} '".str_replace("'", "'\\''", $url)."'", "  -H 'Accept: application/json'"];
        foreach ($headers as $h) {
            $value = $h['value'] ?? match (strtolower($h['name'])) {
                'authorization' => 'Bearer <token>',
                default => (string) Examples::scalar(['string'], $h['name']),
            };
            $lines[] = "  -H '".$h['name'].': '.str_replace("'", "'\\''", (string) $value)."'";
        }
        if ($payload['location'] === 'json' && $payload['example'] !== [] && ! $payload['example'] instanceof \stdClass) {
            $lines[] = "  -H 'Content-Type: application/json'";
            $lines[] = "  -d '".str_replace("'", "'\\''", (string) json_encode($payload['example'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))."'";
        }
        if ($payload['location'] === 'multipart') {
            foreach (self::flatten($payload['example']) as $k => $v) {
                $lines[] = "  -F '".$k.'='.str_replace("'", "'\\''", is_bool($v) ? ($v ? '1' : '0') : (string) $v)."'";
            }
        }

        return implode(" \\\n", $lines);
    }

    /** @return array<string, scalar|null> nested fields as form keys: items[0][id] */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = $prefix === '' ? (string) $k : "{$prefix}[{$k}]";
            is_array($v) ? $out += self::flatten($v, $key) : $out[$key] = $v;
        }

        return $out;
    }
}
