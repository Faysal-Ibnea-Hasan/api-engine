<?php

namespace App\Domain\Analysis;

use App\Domain\Discovery\Dynamic;
use App\Domain\Discovery\ParsedFile;
use App\Domain\Discovery\ValueResolver;
use Illuminate\Validation\ValidationRuleParser;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Turns validation rule arrays (FormRequest::rules(), $request->validate([...]), …) into
 * per-field facts. Status is decided per rule: a closure or custom rule object is "unknown"
 * without hiding the field's other, verified rules. Rule strings are parsed with Laravel's
 * own ValidationRuleParser (string parsing only).
 */
final class RuleExtractor
{
    private const RULE_CLASS = 'illuminate\validation\rule';

    private const TYPES = [
        'String' => 'string', 'Integer' => 'integer', 'Numeric' => 'number', 'Decimal' => 'number',
        'Boolean' => 'boolean', 'Accepted' => 'boolean', 'Array' => 'array', 'List' => 'array',
        'File' => 'file', 'Image' => 'file', 'Mimes' => 'file', 'Mimetypes' => 'file', 'Extensions' => 'file',
        'Email' => 'string', 'Url' => 'string', 'ActiveUrl' => 'string', 'Uuid' => 'string', 'Ulid' => 'string',
        'Date' => 'string', 'DateFormat' => 'string', 'Json' => 'string', 'Ip' => 'string', 'Ipv4' => 'string', 'Ipv6' => 'string',
        'Alpha' => 'string', 'AlphaNum' => 'string', 'AlphaDash' => 'string', 'Timezone' => 'string', 'MacAddress' => 'string',
    ];

    private const FORMATS = ['Email' => 'email', 'Url' => 'url', 'ActiveUrl' => 'url', 'Uuid' => 'uuid', 'Ulid' => 'ulid', 'Date' => 'date', 'DateFormat' => 'date', 'Json' => 'json', 'Ip' => 'ip', 'Ipv4' => 'ipv4', 'Ipv6' => 'ipv6'];

    /** @var list<string> */
    public array $unresolved = [];

    public function __construct(
        private readonly ParsedFile $file,
        private readonly Evidence $evidence,
        private readonly ?string $sha256,
        private readonly string $symbol,
        private readonly string $source, // form_request | inline | validator
    ) {}

    /**
     * rules() of a FormRequest: a literal return, a returned variable built in the method,
     * array_merge(...) of those; keys added inside an if are conditional.
     *
     * @return list<array<string, mixed>>
     */
    public function fromRulesMethod(Stmt\ClassMethod $method): array
    {
        $variables = [];
        $returns = [];
        $this->walk($method->stmts ?? [], $variables, $returns, false);

        if ($returns === []) {
            $this->unresolved[] = "{$this->symbol} returns no rules array that can be read statically.";

            return [];
        }

        // Several return statements: a field is unconditional only if every return has it.
        $byName = [];
        foreach ($returns as $fields) {
            foreach ($fields as $field) {
                $byName[$field['name']][] = $field;
            }
        }

        $out = [];
        foreach ($byName as $name => $versions) {
            $field = end($versions);
            if (count($versions) < count($returns)) {
                $field['conditional'] = true;
            }
            $out[] = $this->finalize($field);
        }

        return $out;
    }

    /**
     * A rules argument passed to validate()/Validator::make(), resolved through simple
     * assignments made earlier in the same method.
     *
     * @param  array<string, list<array<string, mixed>>>  $variables
     * @return list<array<string, mixed>>
     */
    public function fromExpression(Expr $rules, array $variables = []): array
    {
        $fields = $this->rulesOf($rules, $variables, false);

        if ($fields === null) {
            $this->unresolved[] = "Validation rules in {$this->symbol} are built dynamically and could not be read.";

            return [];
        }

        return array_map(fn ($f) => $this->finalize($f), $fields);
    }

    /**
     * Collects `$x = [...]`, `$x['k'] = …`, `$x = array_merge(...)` and return statements.
     *
     * @param  array<Node>  $stmts
     * @param  array<string, list<array<string, mixed>>>  $variables
     * @param  list<list<array<string, mixed>>>  $returns
     */
    public function walk(array $stmts, array &$variables, array &$returns, bool $conditional): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Return_ && $stmt->expr !== null) {
                $fields = $this->rulesOf($stmt->expr, $variables, $conditional);
                $fields === null
                    ? $this->unresolved[] = "Part of {$this->symbol} returns rules that are built dynamically (line {$stmt->getStartLine()})."
                    : $returns[] = $fields;
            } elseif ($stmt instanceof Stmt\Expression && $stmt->expr instanceof Expr\Assign) {
                $this->assign($stmt->expr, $variables, $conditional);
            } elseif ($stmt instanceof Stmt\If_) {
                $this->walk($stmt->stmts, $variables, $returns, true);
                foreach ($stmt->elseifs as $elseif) {
                    $this->walk($elseif->stmts, $variables, $returns, true);
                }
                $stmt->else !== null && $this->walk($stmt->else->stmts, $variables, $returns, true);
            } elseif ($stmt instanceof Stmt\Foreach_ || $stmt instanceof Stmt\For_ || $stmt instanceof Stmt\While_) {
                $this->unresolved[] = "{$this->symbol} adds rules in a loop (line {$stmt->getStartLine()}); those fields are not listed.";
            }
        }
    }

    /**
     * Rule arrays assigned, in a controller action, to the variables later passed as rules
     * (other arrays in the action — headers, payloads — are not rules and are ignored).
     *
     * @param  array<Node>  $stmts
     * @param  list<string>  $names
     * @return array<string, list<array<string, mixed>>>
     */
    public function variablesIn(array $stmts, array $names): array
    {
        $variables = [];
        $this->collect($stmts, $variables, false, $names);

        return $variables;
    }

    /**
     * @param  array<Node>  $stmts
     * @param  array<string, list<array<string, mixed>>>  $variables
     * @param  list<string>  $names
     */
    private function collect(array $stmts, array &$variables, bool $conditional, array $names): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Expression && $stmt->expr instanceof Expr\Assign
                && ($stmt->expr->expr instanceof Expr\Array_ || $stmt->expr->var instanceof Expr\ArrayDimFetch)) {
                $target = $stmt->expr->var instanceof Expr\ArrayDimFetch ? $stmt->expr->var->var : $stmt->expr->var;
                $target instanceof Expr\Variable && in_array($target->name, $names, true)
                    && $this->assign($stmt->expr, $variables, $conditional);
            } elseif ($stmt instanceof Stmt\If_) {
                $this->collect($stmt->stmts, $variables, true, $names);
                foreach ($stmt->elseifs as $elseif) {
                    $this->collect($elseif->stmts, $variables, true, $names);
                }
                $stmt->else !== null && $this->collect($stmt->else->stmts, $variables, true, $names);
            } elseif ($stmt instanceof Stmt\TryCatch) {
                $this->collect($stmt->stmts, $variables, $conditional, $names);
            }
        }
    }

    /** @param  array<string, list<array<string, mixed>>>  $variables */
    private function assign(Expr\Assign $assign, array &$variables, bool $conditional): void
    {
        // $rules = [...] / $rules = array_merge(...)
        if ($assign->var instanceof Expr\Variable && is_string($assign->var->name)) {
            $fields = $this->rulesOf($assign->expr, $variables, $conditional);
            $fields !== null && $variables[$assign->var->name] = $fields;

            return;
        }

        // $rules['field'] = 'required|…'
        if ($assign->var instanceof Expr\ArrayDimFetch && $assign->var->var instanceof Expr\Variable && is_string($assign->var->var->name)) {
            $name = $assign->var->var->name;
            $key = $assign->var->dim === null ? null : $this->file->resolver()->resolve($assign->var->dim);

            if (! is_string($key)) {
                $this->unresolved[] = "A validation field in {$this->symbol} has a dynamic name (line {$assign->getStartLine()}).";

                return;
            }

            $variables[$name] = array_values(array_filter($variables[$name] ?? [], fn ($f) => $f['name'] !== $key));
            $variables[$name][] = $this->field($key, $assign->expr, $conditional, $assign);
        }
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $variables
     * @return list<array<string, mixed>>|null null when the array can't be read
     */
    private function rulesOf(Expr $expr, array $variables, bool $conditional): ?array
    {
        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            return $variables[$expr->name] ?? null;
        }

        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name && strtolower($expr->name->getLast()) === 'array_merge') {
            $merged = [];
            foreach ($expr->args as $arg) {
                $part = $arg instanceof Node\Arg ? $this->rulesOf($arg->value, $variables, $conditional) : null;
                if ($part === null) {
                    return null;
                }
                foreach ($part as $field) {
                    $merged[$field['name']] = $field;
                }
            }

            return array_values($merged);
        }

        if (! $expr instanceof Expr\Array_) {
            return null;
        }

        $fields = [];
        foreach ($expr->items as $item) {
            if ($item->unpack) {
                $spread = $this->rulesOf($item->value, $variables, $conditional);
                $spread === null ? $this->unresolved[] = "Rules in {$this->symbol} include a spread that can't be read." : array_push($fields, ...$spread);

                continue;
            }

            $key = $item->key === null ? null : $this->file->resolver()->resolve($item->key);
            if (! is_string($key)) {
                $this->unresolved[] = "A validation field in {$this->symbol} has a dynamic name (line {$item->getStartLine()}).";

                continue;
            }

            $fields[] = $this->field($key, $item->value, $conditional, $item);
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private function field(string $name, Expr $rules, bool $conditional, Node $at): array
    {
        return [
            'name' => $name,
            'rules' => $this->rules($rules),
            'conditional' => $conditional || (new NodeFinder)->findFirstInstanceOf([$rules], Expr\Ternary::class) !== null,
            'source' => $this->source,
            'evidence' => [$this->evidence->add($this->file->path, $this->sha256, $this->symbol, $at, 'validation_rule')],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rules(Expr $expr): array
    {
        if ($expr instanceof Expr\Ternary) {
            $out = [];
            foreach ([$expr->if ?? $expr->cond, $expr->else] as $branch) {
                foreach ($this->rules($branch) as $rule) {
                    $rule['status'] = $rule['status'] === 'unknown' ? 'unknown' : 'partial';
                    $rule['note'] = trim(($rule['note'] ?? '').' Applies only when the runtime condition selects this rule set.');
                    $out[$rule['rule'].':'.implode(',', $rule['params'])] = $rule;
                }
            }

            return array_values($out);
        }

        // Application rule helpers commonly return presence rules plus a custom rule object,
        // e.g. PhoneNumber::rules($request->user ? 'nullable' : 'required'). Preserve both
        // possible presence rules and name the custom validator instead of calling it dynamic.
        if ($expr instanceof Expr\StaticCall && $expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier
            && in_array(strtolower($expr->name->toString()), ['rules', 'basicrules'], true)) {
            $class = ClassInfo::name($expr->class);
            $out = [];
            $presence = $expr->args[0] ?? null;
            if ($presence instanceof Node\Arg) {
                foreach ($this->rules($presence->value) as $rule) {
                    if (in_array($rule['rule'], ['Required', 'Nullable', 'Sometimes', 'Present', 'Filled', 'RequiredIf', 'RequiredUnless', 'RequiredWith', 'RequiredWithout'], true)) {
                        $out[$rule['rule'].':'.implode(',', $rule['params'])] = $rule;
                    }
                }
            }
            $out['Custom:'.$class] = [
                'rule' => 'Custom',
                'params' => [$class],
                'status' => 'unknown',
                'note' => 'Validation is implemented by '.class_basename($class).'::'.$expr->name->toString().'().',
            ];

            return array_values($out);
        }

        $value = $this->file->resolver()->resolve($expr);

        if (is_string($value)) {
            return array_map(fn ($r) => $this->parsed($r), self::explode($value));
        }

        if ($expr instanceof Expr\Array_) {
            $out = [];
            foreach ($expr->items as $item) {
                $one = $this->file->resolver()->resolve($item->value);
                is_string($one)
                    ? array_push($out, ...array_map(fn ($r) => $this->parsed($r), self::explode($one)))
                    : $out[] = $this->object($item->value);
            }

            return $out;
        }

        return [$this->object($expr)];
    }

    /** @return list<string> Laravel's own explode for a rule string (ValidationRuleParser::explodeExplicitRule) */
    private static function explode(string $rules): array
    {
        return array_values(array_filter(explode('|', $rules), fn ($r) => $r !== ''));
    }

    /** @return array<string, mixed> */
    private function parsed(string $rule): array
    {
        [$name, $params] = ValidationRuleParser::parse($rule);

        return ['rule' => (string) $name, 'params' => array_values(array_map('strval', $params)), 'status' => 'verified'];
    }

    /**
     * Rule objects, Rule::…() factories, closures.
     *
     * @return array<string, mixed>
     */
    private function object(Expr $expr): array
    {
        if ($expr instanceof Expr\Closure || $expr instanceof Expr\ArrowFunction) {
            return ['rule' => 'Closure', 'params' => [], 'status' => 'unknown', 'note' => 'custom validation logic in a closure'];
        }

        // Rule::unique('users')->ignore($id): the base rule is known, the extra calls aren't.
        $chained = false;
        while ($expr instanceof Expr\MethodCall) {
            $expr = $expr->var;
            $chained = true;
        }

        if ($expr instanceof Expr\StaticCall && $expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier) {
            $class = strtolower(ClassInfo::name($expr->class));
            $method = $expr->name->toString();
            $args = array_map(fn ($a) => $a instanceof Node\Arg ? $this->file->resolver()->resolve($a->value) : new Dynamic('arg'), $expr->args);

            if ($class === self::RULE_CLASS) {
                $rule = match (strtolower($method)) {
                    'in', 'notin' => ['rule' => $method === 'in' ? 'In' : 'NotIn', 'params' => self::flat($args)],
                    'exists', 'unique' => ['rule' => ucfirst($method), 'params' => self::flat($args)],
                    'enum' => ['rule' => 'Enum', 'params' => self::flat($args)],
                    'requiredif', 'excludeif', 'prohibitedif' => ['rule' => ucfirst($method), 'params' => [], 'status' => 'unknown', 'note' => 'condition decided at runtime'],
                    default => ['rule' => ucfirst($method), 'params' => [], 'status' => 'unknown', 'note' => 'Rule::'.$method.'() details not analyzed'],
                };
                $dynamicArgs = array_filter($args, fn ($a) => ValueResolver::isDynamic($a)) !== [];

                return $rule + [
                    'status' => $chained || $dynamicArgs ? 'partial' : 'verified',
                    ...($chained ? ['note' => 'extra conditions on this rule are set at runtime'] : []),
                ];
            }

            if (str_ends_with($class, '\rules\password') || $class === 'password') {
                return ['rule' => 'Password', 'params' => [], 'status' => 'partial', 'note' => 'password strength rules'];
            }
        }

        if ($expr instanceof Expr\New_ && $expr->class instanceof Node\Name) {
            $class = ClassInfo::name($expr->class);
            $args = array_map(fn ($a) => $a instanceof Node\Arg ? $this->file->resolver()->resolve($a->value) : new Dynamic('arg'), $expr->args);

            return match (strtolower(class_basename($class))) {
                'enum' => ['rule' => 'Enum', 'params' => self::flat($args), 'status' => 'verified'],
                'in' => ['rule' => 'In', 'params' => self::flat($args), 'status' => 'verified'],
                'password' => ['rule' => 'Password', 'params' => [], 'status' => 'partial', 'note' => 'password strength rules'],
                default => ['rule' => 'Custom', 'params' => [$class], 'status' => 'unknown', 'note' => 'custom rule class '.class_basename($class)],
            };
        }

        return ['rule' => 'Dynamic', 'params' => [], 'status' => 'unknown', 'note' => 'rule is not a fixed value'];
    }

    /**
     * @param  array<mixed>  $args
     * @return list<string>
     */
    private static function flat(array $args): array
    {
        $out = [];
        array_walk_recursive($args, function ($v) use (&$out) {
            if (is_scalar($v)) {
                $out[] = (string) $v;
            }
        });

        return $out;
    }

    /**
     * Requiredness, types, constraints and overall status, from the rule list.
     *
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function finalize(array $field): array
    {
        $names = array_map(fn ($r) => $r['rule'], $field['rules']);
        $has = fn (string ...$rules) => array_intersect($rules, $names) !== [];

        $field['required'] = match (true) {
            $has('Required') && ! $field['conditional'] => 'required',
            $has('Required', 'RequiredIf', 'RequiredUnless', 'RequiredWith', 'RequiredWithAll', 'RequiredWithout', 'RequiredWithoutAll', 'RequiredIfAccepted', 'RequiredIfDeclined', 'RequiredArrayKeys', 'Present') => 'conditional',
            default => 'optional',
        };
        $field['nullable'] = $has('Nullable');
        $field['sometimes'] = $has('Sometimes');

        $types = [];
        $formats = [];
        $constraints = [];
        foreach ($field['rules'] as $rule) {
            isset(self::TYPES[$rule['rule']]) && $types[] = self::TYPES[$rule['rule']];
            isset(self::FORMATS[$rule['rule']]) && $formats[] = self::FORMATS[$rule['rule']];
            if (in_array($rule['rule'], ['Min', 'Max', 'Size', 'Between', 'Digits', 'DigitsBetween', 'In', 'NotIn', 'Regex', 'Mimes', 'Exists', 'Unique', 'Enum', 'DateFormat', 'Gt', 'Gte', 'Lt', 'Lte', 'MaxDigits', 'MinDigits'], true)) {
                $constraints[strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $rule['rule']) ?? $rule['rule'])] = $rule['params'];
            }
        }

        $field['types'] = array_values(array_unique($types));
        $field['formats'] = array_values(array_unique($formats));
        $field['constraints'] = $constraints;
        $statuses = array_unique(array_map(fn ($r) => $r['status'], $field['rules']));
        $field['status'] = $statuses === ['verified'] || $statuses === [] ? 'verified' : 'partial';

        return $field;
    }
}
