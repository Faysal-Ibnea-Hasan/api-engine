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
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * What an Eloquent model looks like as JSON: its table's columns, read from the migrations
 * (Schema::create plus later Schema::table changes), then $hidden / $visible / $appends /
 * $casts from the model class. Column types follow what Laravel + PDO MySQL return.
 */
final class ModelSchema
{
    private const MAX_MIGRATIONS = 1500;

    /** @var array<string, array<string, array{types: list<string>}>>|null table => column => types */
    private ?array $tables = null;

    private bool $truncated = false;

    public function __construct(
        private readonly SnapshotReader $reader,
        private readonly SourceSnapshot $snapshot,
        private readonly string $root,
        private readonly ClassIndex $index,
    ) {}

    /**
     * @param  list<string>  $with  relations eager-loaded on this model
     * @return array<string, mixed> object shape, or unknown
     */
    public function shape(string $class, array $with, Interpreter $interpreter, int $depth): array
    {
        $info = $this->index->find($class);
        if ($info === null) {
            return Shape::unknown("model {$class} was not found in the snapshot");
        }

        $table = $this->tableOf($info);
        $columns = $this->columns()[$table] ?? null;
        if ($columns === null) {
            return Shape::unknown("no migration creates table \"{$table}\" for {$class}".($this->truncated ? ' (migration read limit reached)' : ''));
        }

        $props = [];
        foreach ($columns as $name => $column) {
            $props[$name] = Shape::scalar($column['types']);
        }

        $casts = $this->arrayProperty($info, 'casts');
        foreach ($casts as $column => $cast) {
            if (isset($props[$column]) && is_string($cast)) {
                $props[$column] = self::cast($cast, in_array('null', $props[$column]['types'] ?? [], true));
            }
        }

        foreach ($this->arrayProperty($info, 'appends') as $appended) {
            if (is_string($appended)) {
                $props[$appended] = $interpreter->accessor($info, $appended, $depth) ?? Shape::unknown("accessor {$appended} of {$class} could not be read");
            }
        }

        $visible = $this->arrayProperty($info, 'visible');
        if ($visible !== []) {
            $props = array_intersect_key($props, array_flip(array_filter($visible, 'is_string')));
        }
        foreach ($this->arrayProperty($info, 'hidden') as $hidden) {
            is_string($hidden) && ($hidden = explode('.', $hidden)[0]) && $props = array_diff_key($props, [$hidden => true]);
        }

        // Attributes the code set at runtime: present only on instances that went through that code.
        $optional = [];
        foreach ($interpreter->setAttributes($class) as $attribute => $value) {
            if (! isset($props[$attribute])) {
                $props[$attribute] = $interpreter->json($value, $depth + 1);
                $optional[] = $attribute;
            }
        }

        foreach ($with as $relation) {
            $props[Str::snake(explode('.', $relation)[0])] = $interpreter->relation($info, explode('.', $relation)[0], $depth) ?? Shape::unknown("relation {$relation} of {$class} could not be read");
        }

        return Shape::object($props, $optional);
    }

    /** Are this model's columns known (its table was found in the dump or migrations)? */
    public function tableKnown(string $class): bool
    {
        $info = $this->index->find($class);

        return $info !== null && isset($this->columns()[$this->tableOf($info)]);
    }

    /** One column's type, e.g. for $model->price or pluck('price'). */
    public function column(string $class, string $column): ?array
    {
        $info = $this->index->find($class);
        if ($info === null) {
            return null;
        }
        $columns = $this->columns()[$this->tableOf($info)] ?? [];
        $cast = $this->arrayProperty($info, 'casts')[$column] ?? null;

        if (is_string($cast)) {
            return self::cast($cast, in_array('null', $columns[$column]['types'] ?? [], true));
        }

        return isset($columns[$column]) ? Shape::scalar($columns[$column]['types']) : null;
    }

    /** Is this attribute a date (Carbon when read)? Timestamps, $dates and date/datetime casts. */
    public function isDate(string $class, string $column): bool
    {
        $info = $this->index->find($class);
        if ($info === null) {
            return false;
        }
        if (in_array($column, ['created_at', 'updated_at', 'deleted_at'], true) && isset($this->columns()[$this->tableOf($info)][$column])) {
            return true;
        }
        $cast = $this->arrayProperty($info, 'casts')[$column] ?? null;

        return (is_string($cast) && in_array(strtolower(explode(':', $cast)[0]), ['date', 'datetime', 'immutable_date', 'immutable_datetime', 'custom_datetime', 'timestamp'], true))
            || in_array($column, array_filter($this->arrayProperty($info, 'dates'), 'is_string'), true);
    }

    public function tableOf(ClassInfo $info): string
    {
        foreach ($info->node->getProperties() as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === 'table' && $prop->default instanceof Node\Scalar\String_) {
                    return $prop->default->value;
                }
            }
        }

        return Str::snake(Str::pluralStudly(class_basename($info->fqcn)));
    }

    /** @return array<int|string, mixed> */
    private function arrayProperty(ClassInfo $info, string $name): array
    {
        $current = $info;
        for ($i = 0; $i < 4 && $current !== null; $i++) {
            foreach ($current->node->getProperties() as $property) {
                foreach ($property->props as $prop) {
                    if ($prop->name->toString() === $name && $prop->default !== null) {
                        $value = $current->file->resolver()->resolve($prop->default);

                        return is_array($value) ? $value : [];
                    }
                }
            }
            // casts() method (Laravel 11): return [...]
            if ($name === 'casts' && ($method = $current->method('casts')) !== null) {
                $return = (new NodeFinder)->findFirstInstanceOf($method->stmts ?? [], Stmt\Return_::class);
                $value = $return?->expr === null ? null : $current->file->resolver()->resolve($return->expr);
                if (is_array($value)) {
                    return $value;
                }
            }
            $parent = $current->parentName();
            $current = $parent === null ? null : $this->index->find($parent);
        }

        return [];
    }

    private static function cast(string $cast, bool $nullable): array
    {
        $base = strtolower(explode(':', $cast)[0]);
        $shape = match ($base) {
            'int', 'integer', 'timestamp' => Shape::int(),
            'bool', 'boolean' => Shape::bool(),
            'float', 'double', 'real' => Shape::scalar(['number']),
            'decimal', 'string', 'date', 'datetime', 'immutable_date', 'immutable_datetime', 'custom_datetime', 'hashed', 'encrypted' => Shape::string(),
            'array', 'json', 'object', 'collection', 'encrypted:array', 'asarrayobject', 'ascollection' => Shape::unknown('JSON column cast to array: its keys are data'),
            default => str_contains($cast, '\\') ? Shape::scalar(['integer', 'string']) : Shape::string(), // enum / custom cast
        };

        return $nullable && $shape['k'] === 'scalar' ? Shape::scalar([...$shape['types'], 'null']) : $shape;
    }

    /** @return array<string, array<string, array{types: list<string>}>> */
    private function columns(): array
    {
        if ($this->tables !== null) {
            return $this->tables;
        }
        $this->tables = $this->sqlDump();

        $paths = [];
        foreach ([SnapshotPaths::join($this->root, 'database/migrations'), SnapshotPaths::join($this->root, 'Modules')] as $folder) {
            foreach ($this->reader->paths($this->snapshot, $folder, like: '%.php') as $path) {
                if (str_contains(strtolower($path), 'migrations/')) {
                    $paths[] = $path;
                }
            }
        }
        // Migrations run in filename order (timestamps); later ones alter earlier tables.
        usort($paths, fn ($a, $b) => strcmp(basename($a), basename($b)));
        if (count($paths) > self::MAX_MIGRATIONS) {
            $this->truncated = true;
            $paths = array_slice($paths, 0, self::MAX_MIGRATIONS);
        }

        foreach ($paths as $path) {
            $code = $this->reader->read($this->snapshot, $path);
            if ($code === null || strlen($code) > 262144 || ! str_contains($code, 'Schema::')) {
                continue;
            }
            $file = ParsedFile::parse($path, mb_scrub($code, 'UTF-8'), $this->root);
            $up = null;
            foreach ((new NodeFinder)->findInstanceOf($file->stmts, Stmt\ClassMethod::class) as $method) {
                $method->name->toString() === 'up' && $up = $method;
            }
            foreach ((new NodeFinder)->find($up === null ? [] : ($up->stmts ?? []), fn (Node $n) => $n instanceof Expr\StaticCall && $n->name instanceof Node\Identifier
                && in_array($n->name->toString(), ['create', 'table', 'dropIfExists', 'drop', 'rename'], true)) as $call) {
                /** @var Expr\StaticCall $call */
                $this->apply($call, $file);
            }
        }

        return $this->tables;
    }

    /**
     * Many apps (6amTech among them) install their base schema from an SQL dump and only add
     * later changes as migrations. Read CREATE TABLE statements from the installer's dump.
     *
     * @return array<string, array<string, array{types: list<string>}>>
     */
    private function sqlDump(): array
    {
        $candidates = [];
        foreach (['installation/backup', 'installation', 'database'] as $folder) {
            foreach ($this->reader->paths($this->snapshot, SnapshotPaths::join($this->root, $folder), like: '%.sql') as $path) {
                $candidates[] = $path;
            }
        }
        if ($candidates === []) {
            return [];
        }
        // The installer's main dump is database.sql; otherwise the newest-numbered one.
        usort($candidates, fn ($a, $b) => [basename($a) !== 'database.sql', -strnatcmp(basename($a), basename($b))] <=> [basename($b) !== 'database.sql', 0]);
        $sql = $this->reader->read($this->snapshot, $candidates[0]);
        if ($sql === null || strlen($sql) > 16777216) {
            return [];
        }

        $tables = [];
        preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?`([^`]+)`\s*\((.*?)\)\s*(?:ENGINE|;)/si', $sql, $creates, PREG_SET_ORDER);
        foreach ($creates as [, $table, $body]) {
            foreach (preg_split('/\R/', $body) ?: [] as $line) {
                if (! preg_match('/^\s*`([^`]+)`\s+([a-z]+)(\(([^)]*)\))?(.*)$/i', $line, $m)) {
                    continue;
                }
                $type = strtolower($m[2]);
                $types = match (true) {
                    in_array($type, ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint', 'year', 'bit'], true) => ['integer'],
                    in_array($type, ['decimal', 'numeric'], true) => ['string'], // PDO MySQL returns DECIMAL as a string
                    in_array($type, ['float', 'double', 'real'], true) => ['number'],
                    default => ['string'], // varchar, text, json, enum, date, datetime, timestamp, …
                };
                $nullable = ! preg_match('/NOT NULL/i', $m[5]);
                $tables[$table][$m[1]] = ['types' => $nullable ? [...$types, 'null'] : $types];
            }
        }

        return $tables;
    }

    private function apply(Expr\StaticCall $call, ParsedFile $file): void
    {
        $resolver = $file->resolver();
        $method = $call->name instanceof Node\Identifier ? $call->name->toString() : '';
        $table = $resolver->arg($call->args, 0);
        if (! is_string($table)) {
            return;
        }
        if (in_array($method, ['dropIfExists', 'drop'], true)) {
            unset($this->tables[$table]);

            return;
        }
        if ($method === 'rename') {
            $to = $resolver->arg($call->args, 1);
            is_string($to) && isset($this->tables[$table]) && $this->tables[$to] = $this->tables[$table];
            unset($this->tables[$table]);

            return;
        }

        $closure = $call->args[1] ?? null;
        $closure = $closure instanceof Node\Arg ? $closure->value : null;
        if (! $closure instanceof Expr\Closure) {
            return;
        }
        $method === 'create' && $this->tables[$table] = [];

        foreach ($closure->stmts as $stmt) {
            if (! $stmt instanceof Stmt\Expression) {
                continue;
            }
            // $table->string('name')->nullable()->default(...)
            $chain = [];
            $expr = $stmt->expr;
            while ($expr instanceof Expr\MethodCall) {
                $chain[] = $expr;
                $expr = $expr->var;
            }
            if ($chain === []) {
                continue;
            }
            $base = end($chain);
            $type = $base->name instanceof Node\Identifier ? strtolower($base->name->toString()) : '';
            $modifiers = array_map(fn ($c) => $c->name instanceof Node\Identifier ? strtolower($c->name->toString()) : '', array_slice($chain, 0, -1));
            $nullable = in_array('nullable', $modifiers, true);
            $name = $resolver->arg($base->args, 0);

            foreach ($this->columnsFor($type, $name, $resolver->arg($base->args, 1)) as $column => $types) {
                if (in_array('change', $modifiers, true) || ! isset($this->tables[$table][$column]) || $method === 'create') {
                    $this->tables[$table][$column] = ['types' => $nullable ? [...$types, 'null'] : $types];
                }
            }
            if ($type === 'dropcolumn' || $type === 'dropcolumns') {
                foreach ((array) $name as $drop) {
                    if (is_string($drop)) {
                        unset($this->tables[$table][$drop]);
                    }
                }
            }
            if ($type === 'renamecolumn' && is_string($name) && is_string($to = $resolver->arg($base->args, 1)) && isset($this->tables[$table][$name])) {
                $this->tables[$table][$to] = $this->tables[$table][$name];
                unset($this->tables[$table][$name]);
            }
        }
    }

    /** @return array<string, list<string>> column => JSON types */
    private function columnsFor(string $type, mixed $name, mixed $second): array
    {
        $int = ['integer'];
        $string = ['string'];
        $date = ['null', 'string'];

        return match ($type) {
            'id', 'increments', 'bigincrements', 'smallincrements', 'tinyincrements', 'mediumincrements' => [is_string($name) ? $name : 'id' => $int],
            'integer', 'biginteger', 'smallinteger', 'tinyinteger', 'mediuminteger', 'unsignedbiginteger', 'unsignedinteger',
            'unsignedsmallinteger', 'unsignedtinyinteger', 'unsignedmediuminteger', 'foreignid', 'year' => is_string($name) ? [$name => $int] : [],
            // tinyint(1) comes back as 0/1, not true/false, unless the model casts it
            'boolean' => is_string($name) ? [$name => $int] : [],
            // PDO MySQL returns DECIMAL as a string
            'decimal', 'unsigneddecimal' => is_string($name) ? [$name => $string] : [],
            'float', 'double' => is_string($name) ? [$name => ['number']] : [],
            'string', 'char', 'text', 'mediumtext', 'longtext', 'tinytext', 'uuid', 'ulid', 'foreignuuid', 'foreignulid', 'enum', 'set',
            'ipaddress', 'macaddress', 'json', 'jsonb', 'binary', 'date', 'datetime', 'datetimetz', 'time', 'timetz', 'timestamp', 'timestamptz' => is_string($name) ? [$name => $string] : [],
            'timestamps', 'timestampstz', 'nullabletimestamps' => ['created_at' => $date, 'updated_at' => $date],
            'softdeletes', 'softdeletestz' => [is_string($name) ? $name : 'deleted_at' => $date],
            'remembertoken' => ['remember_token' => ['null', 'string']],
            'morphs', 'nullablemorphs' => is_string($name) ? ["{$name}_type" => $string, "{$name}_id" => $int] : [],
            'uuidmorphs', 'nullableuuidmorphs', 'ulidmorphs' => is_string($name) ? ["{$name}_type" => $string, "{$name}_id" => $string] : [],
            default => [],
        };
    }
}
