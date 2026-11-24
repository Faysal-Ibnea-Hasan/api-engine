<?php

namespace App\Domain\Sources;

/**
 * The manifest of one snapshot (version 1.0). Its hash depends only on the
 * normalized paths and content of stored files: never on order, timestamps or
 * archive metadata. Excluded files are listed but don't affect the hash, so two
 * imports that differ only in excluded files (e.g. a different .env) dedupe.
 */
final class Manifest
{
    public const VERSION = '1.0';

    /** @var array<string, ManifestEntry> keyed by path */
    private array $entries = [];

    /** @var array<string, string> lowercased path → path, to catch case collisions */
    private array $folded = [];

    /** Running total, so adding N entries stays linear (an archive can list 100k excluded entries). */
    private int $storedBytes = 0;

    public function add(ManifestEntry $entry): void
    {
        $folded = mb_strtolower($entry->path);

        if (isset($this->folded[$folded])) {
            throw SourceRejection::pathCollision();
        }

        $this->folded[$folded] = $entry->path;
        $this->entries[$entry->path] = $entry;

        if ($entry->isStored()) {
            $this->storedBytes += $entry->bytes;
        }
    }

    /** @return list<ManifestEntry> sorted by path */
    public function entries(): array
    {
        $entries = $this->entries;
        ksort($entries, SORT_STRING);

        return array_values($entries);
    }

    /** @return list<ManifestEntry> */
    public function stored(): array
    {
        return array_values(array_filter($this->entries(), fn (ManifestEntry $e) => $e->isStored()));
    }

    public function storedBytes(): int
    {
        return $this->storedBytes;
    }

    public function hash(): string
    {
        $lines = array_map(
            fn (ManifestEntry $e) => $e->path."\0".$e->sha256."\0".$e->bytes,
            $this->stored(),
        );

        return hash('sha256', self::VERSION."\n".implode("\n", $lines));
    }
}
