<?php

namespace App\Domain\Discovery;

use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** One route-related PHP file parsed to an AST (never executed), with its `use` imports. */
final class ParsedFile
{
    /**
     * @param  array<Node>  $stmts
     * @param  list<array{message: string, line: int|null}>  $errors
     * @param  array<string, string>  $imports  lowercased alias => FQCN
     * @param  array<string, mixed>  $properties  statically known $this->properties
     */
    public function __construct(
        public readonly string $path,
        public readonly array $stmts,
        public readonly array $errors,
        public readonly array $imports,
        private readonly string $appRoot,
        public array $properties = [],
        public readonly string $code = '',
        /** @var array<string, mixed> local variables with fixed values seen so far in this file */
        public array $variables = [],
    ) {}

    public static function parse(string $path, string $code, string $appRoot): self
    {
        $errors = new Collecting;
        $stmts = (new ParserFactory)->createForHostVersion()->parse($code, $errors) ?? [];

        // Collect name errors (e.g. a duplicate `use` alias) instead of throwing: the file stays partially usable.
        $traverser = new NodeTraverser(new NameResolver($errors, ['preserveOriginalNames' => true]));
        $stmts = $traverser->traverse($stmts);

        return new self(
            $path,
            $stmts,
            array_values(array_map(fn ($e) => ['message' => mb_strimwidth($e->getRawMessage(), 0, 200, '…'), 'line' => $e->getStartLine() > 0 ? $e->getStartLine() : null], $errors->getErrors())),
            self::imports($stmts),
            $appRoot,
            code: $code,
        );
    }

    /** @return list<array{message: string, line: int|null}> */
    public function parseErrors(): array
    {
        return $this->errors;
    }

    /** @return list<Stmt\Class_> */
    public function classes(): array
    {
        $finder = new NodeFinder;

        return array_values($finder->findInstanceOf($this->stmts, Stmt\Class_::class));
    }

    public function resolver(): ValueResolver
    {
        return new ValueResolver(SnapshotPaths::dirname($this->path), $this->appRoot, $this->properties, $this->variables);
    }

    /**
     * @param  array<Node>  $stmts
     * @return array<string, string>
     */
    private static function imports(array $stmts): array
    {
        $imports = [];

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                $imports += self::imports($stmt->stmts);
            }

            if ($stmt instanceof Stmt\Use_ && $stmt->type === Stmt\Use_::TYPE_NORMAL) {
                foreach ($stmt->uses as $use) {
                    $imports[strtolower($use->getAlias()->toString())] = $use->name->toString();
                }
            }

            if ($stmt instanceof Stmt\GroupUse) {
                foreach ($stmt->uses as $use) {
                    $imports[strtolower($use->getAlias()->toString())] = $stmt->prefix->toString().'\\'.$use->name->toString();
                }
            }
        }

        return $imports;
    }
}
