<?php

namespace App\Domain\Analysis;

use App\Domain\Discovery\ParsedFile;
use App\Domain\Discovery\SnapshotPaths;
use App\Domain\Discovery\ValueResolver;
use App\Domain\Sources\SnapshotReader;
use App\Models\SourceFile;
use App\Models\SourceSnapshot;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Finds a class's source file in a snapshot through the app's (and modules') composer.json
 * PSR-4 maps, falling back to a filename lookup, and always verifies the file declares that
 * exact class. Classes are parsed, never autoloaded or executed.
 */
final class ClassIndex
{
    private const MAX_FILE_BYTES = 2097152; // 2 MB: helper classes in real apps (e.g. CentralLogics/Helpers.php) exceed 256 KB

    private const MAX_FILES = 900; // contracts follow calls across many classes; each file is still capped

    /** @var array<string, string> namespace prefix => snapshot folder, longest first */
    private array $psr4 = [];

    /** @var array<string, ClassInfo|null> */
    private array $cache = [];

    private int $parsed = 0;

    public function __construct(
        private readonly SnapshotReader $reader,
        private readonly SourceSnapshot $snapshot,
        private readonly string $root,
    ) {
        $this->loadPsr4(SnapshotPaths::join($root, 'composer.json'), $root);

        foreach ($reader->paths($snapshot, SnapshotPaths::join($root, 'Modules'), like: '%/composer.json') as $moduleComposer) {
            if (substr_count(substr($moduleComposer, strlen(SnapshotPaths::join($root, 'Modules')) + 1), '/') === 1) {
                $this->loadPsr4($moduleComposer, SnapshotPaths::dirname($moduleComposer));
            }
        }

        uksort($this->psr4, fn ($a, $b) => strlen($b) <=> strlen($a));
    }

    public function find(string $fqcn): ?ClassInfo
    {
        $fqcn = ltrim($fqcn, '\\');

        if (array_key_exists($fqcn, $this->cache)) {
            return $this->cache[$fqcn];
        }

        foreach ($this->psr4 as $prefix => $dir) {
            if (str_starts_with($fqcn, $prefix)) {
                $path = SnapshotPaths::normalize(SnapshotPaths::join($dir, str_replace('\\', '/', substr($fqcn, strlen($prefix))).'.php'));
                if ($path !== null && ($info = $this->load($path, $fqcn)) !== null) {
                    return $this->cache[$fqcn] = $info;
                }
            }
        }

        // Fallback: a file named after the class, inside the application root.
        $short = substr(strrchr('\\'.$fqcn, '\\') ?: $fqcn, 1);
        $candidates = SourceFile::query()->where('source_snapshot_id', $this->snapshot->id)->whereNull('excluded_reason')
            ->where('path', 'like', '%/'.str_replace(['%', '_'], ['\%', '\_'], $short).'.php')->limit(20)->pluck('path');

        foreach ($candidates as $path) {
            if (basename((string) $path) === $short.'.php' && SnapshotPaths::within((string) $path, $this->root)
                && ($info = $this->load((string) $path, $fqcn)) !== null) {
                return $this->cache[$fqcn] = $info;
            }
        }

        return $this->cache[$fqcn] = null;
    }

    /** A class already looked up by find(), without reading anything new. */
    public function cached(string $fqcn): ?ClassInfo
    {
        return $this->cache[ltrim($fqcn, '\\')] ?? null;
    }

    /** @var array<string, string> */
    private array $interfaces = [];

    /**
     * What a name find() returned null for is (find() only returns classes and traits):
     * interface (declared in the snapshot), missing (in the app's namespace but not in the snapshot),
     * outside (no app namespace or file: a library), or unchecked (the lookup cap was reached).
     * Read apart from find()'s cache and file limit, so asking changes nothing else.
     * shortcut: at most 100 lookups per analysis; raise it if large apps report "unchecked" often.
     */
    public function interfaceStatus(string $fqcn): string
    {
        $fqcn = ltrim($fqcn, '\\');
        $key = strtolower($fqcn);
        if (isset($this->interfaces[$key]) || count($this->interfaces) >= 100) {
            return $this->interfaces[$key] ?? 'unchecked';
        }

        $paths = [];
        $claimed = false;
        foreach ($this->psr4 as $prefix => $dir) {
            if (str_starts_with($fqcn, $prefix)) {
                $claimed = true;
                $paths[] = SnapshotPaths::normalize(SnapshotPaths::join($dir, str_replace('\\', '/', substr($fqcn, strlen($prefix))).'.php'));
            }
        }
        // find()'s fallback: a file named after it, inside the application root.
        $short = substr(strrchr('\\'.$fqcn, '\\') ?: $fqcn, 1);
        $paths = [...$paths, ...SourceFile::query()->where('source_snapshot_id', $this->snapshot->id)->whereNull('excluded_reason')
            ->where('path', 'like', '%/'.str_replace(['%', '_'], ['\%', '\_'], $short).'.php')->limit(20)->pluck('path')
            ->filter(fn ($p) => basename((string) $p) === $short.'.php' && SnapshotPaths::within((string) $p, $this->root))->all()];

        foreach (array_unique(array_filter($paths)) as $path) {
            $code = $this->reader->read($this->snapshot, (string) $path);
            if ($code !== null && strlen($code) <= self::MAX_FILE_BYTES && (new NodeFinder)->findFirst(ParsedFile::parse((string) $path, mb_scrub($code, 'UTF-8'), $this->root)->stmts,
                fn ($n) => $n instanceof Stmt\Interface_ && $n->namespacedName !== null && strcasecmp($n->namespacedName->toString(), $fqcn) === 0) !== null) {
                return $this->interfaces[$key] = 'interface';
            }
        }

        return $this->interfaces[$key] = $claimed ? 'missing' : 'outside';
    }

    /**
     * Does $class extend $ancestor? null = unknown (a parent couldn't be read, e.g. it is in vendor/).
     */
    public function extends(ClassInfo $class, string $ancestor, int $depth = 0): ?bool
    {
        $parent = $class->parentName();

        if ($parent === null) {
            return false;
        }
        if (strcasecmp($parent, $ancestor) === 0) {
            return true;
        }
        if ($depth >= 6) {
            return null;
        }

        $info = $this->find($parent);

        return $info === null ? null : $this->extends($info, $ancestor, $depth + 1);
    }

    /**
     * A method on the class, its traits, or its parents (nearest first).
     *
     * @return array{ClassInfo, Stmt\ClassMethod}|null
     */
    public function method(ClassInfo $class, string $name, int $depth = 0): ?array
    {
        if ($method = $class->method($name)) {
            return [$class, $method];
        }
        if ($depth >= 6) {
            return null;
        }

        foreach ($class->traitNames() as $trait) {
            if (($info = $this->find($trait)) && ($found = $this->method($info, $name, $depth + 1))) {
                return $found;
            }
        }

        $parent = $class->parentName();

        return $parent !== null && ($info = $this->find($parent)) ? $this->method($info, $name, $depth + 1) : null;
    }

    /**
     * A literal config/<name>.php array from the snapshot, never from the running app.
     *
     * @return array<mixed>|null
     */
    public function config(string $name): ?array
    {
        $path = SnapshotPaths::join($this->root, 'config/'.$name.'.php');
        $code = $this->reader->read($this->snapshot, $path);
        if ($code === null || strlen($code) > self::MAX_FILE_BYTES) {
            return null;
        }

        $file = ParsedFile::parse($path, mb_scrub($code, 'UTF-8'), $this->root);
        $return = (new NodeFinder)->findFirstInstanceOf($file->stmts, Stmt\Return_::class);
        $value = $return instanceof Stmt\Return_ ? $file->resolver()->resolve($return->expr) : null;

        return is_array($value) && ! ValueResolver::isDynamic($value) ? $value : null;
    }

    /** @var array<string, string> lowercased FQCN => why it was not read (when it exists but was skipped) */
    private array $skipped = [];

    /** Why a class couldn't be read: its file exists but was skipped, or it isn't in the snapshot. */
    public function missingReason(string $fqcn): string
    {
        return $this->skipped[strtolower(ltrim($fqcn, '\\'))] ?? 'is not in the snapshot';
    }

    private function load(string $path, string $fqcn): ?ClassInfo
    {
        if ($this->parsed + 1 > self::MAX_FILES) {
            $this->skipped[strtolower($fqcn)] = 'was not read: the per-analysis class limit ('.self::MAX_FILES.' files) was reached';
        }
        if (++$this->parsed > self::MAX_FILES) {
            return null;
        }

        $code = $this->reader->read($this->snapshot, $path);
        if ($code !== null && strlen($code) > self::MAX_FILE_BYTES) {
            $this->skipped[strtolower($fqcn)] = 'was not read: its file is larger than '.(self::MAX_FILE_BYTES / 1048576).' MB';
        }
        if ($code === null || strlen($code) > self::MAX_FILE_BYTES) {
            return null;
        }

        $file = ParsedFile::parse($path, mb_scrub($code, 'UTF-8'), $this->root);

        $node = (new NodeFinder)->findFirst($file->stmts, function ($n) use ($fqcn) {
            return ($n instanceof Stmt\Class_ || $n instanceof Stmt\Trait_)
                && $n->namespacedName !== null && strcasecmp($n->namespacedName->toString(), $fqcn) === 0;
        });

        if (! $node instanceof Stmt\Class_ && ! $node instanceof Stmt\Trait_) {
            return null;
        }

        $sha = SourceFile::query()->where('source_snapshot_id', $this->snapshot->id)->where('path_hash', hash('sha256', $path))->value('sha256');

        return new ClassInfo($fqcn, $file, $node, is_string($sha) ? $sha : null);
    }

    private function loadPsr4(string $composerPath, string $baseDir): void
    {
        $composer = json_decode((string) $this->reader->read($this->snapshot, $composerPath), true);
        $map = $composer['autoload']['psr-4'] ?? [];

        foreach (is_array($map) ? $map : [] as $prefix => $dirs) {
            foreach ((array) $dirs as $dir) {
                if (is_string($prefix) && is_string($dir)) {
                    $this->psr4[$prefix] ??= SnapshotPaths::join($baseDir, trim($dir, '/'));
                }
            }
        }

        // nwidart modules often rely on the app's own autoload; add the conventional layout too.
        if (str_contains($baseDir, '/Modules/') || str_starts_with($baseDir, 'Modules/')) {
            $module = basename($baseDir);
            $this->psr4["Modules\\{$module}\\"] ??= $this->reader->exists($this->snapshot, "{$baseDir}/app/Providers/RouteServiceProvider.php") ? "{$baseDir}/app" : $baseDir;
        }
    }
}
