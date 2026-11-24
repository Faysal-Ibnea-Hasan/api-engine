<?php

namespace App\Domain\Analysis;

use App\Domain\Discovery\ParsedFile;
use PhpParser\Node;
use PhpParser\Node\Stmt;

/** One class found in the snapshot: where it lives and its AST. Never loaded or instantiated. */
final class ClassInfo
{
    public function __construct(
        public readonly string $fqcn,
        public readonly ParsedFile $file,
        public readonly Stmt\Class_|Stmt\Trait_ $node,
        public readonly ?string $sha256,
    ) {}

    public function parentName(): ?string
    {
        return $this->node instanceof Stmt\Class_ && $this->node->extends !== null ? self::name($this->node->extends) : null;
    }

    /** @return list<string> */
    public function traitNames(): array
    {
        $names = [];
        foreach ($this->node->getTraitUses() as $use) {
            foreach ($use->traits as $trait) {
                $names[] = self::name($trait);
            }
        }

        return $names;
    }

    public function method(string $name): ?Stmt\ClassMethod
    {
        foreach ($this->node->getMethods() as $method) {
            if (strtolower($method->name->toString()) === strtolower($name)) {
                return $method;
            }
        }

        return null;
    }

    public function symbol(string $method): string
    {
        return $this->fqcn.'::'.$method;
    }

    public static function name(Node\Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return ltrim(($resolved instanceof Node\Name ? $resolved : $name)->toString(), '\\');
    }
}
