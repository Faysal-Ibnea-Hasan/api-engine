<?php

namespace App\Domain\Discovery;

use App\Domain\Sources\SourcePath;
use App\Domain\Sources\SourceRejection;

/** Snapshot-relative path arithmetic. Paths are data looked up in the manifest, never filesystem paths. */
final class SnapshotPaths
{
    /** Collapses "a/b/../c" inside the snapshot; null if it climbs above the snapshot root or is unsafe. */
    public static function normalize(string $path): ?string
    {
        $out = [];

        foreach (explode('/', str_replace('//', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($out === []) {
                    return null;
                }
                array_pop($out);

                continue;
            }
            $out[] = $segment;
        }

        if ($out === []) {
            return '.';
        }

        try {
            return SourcePath::normalize(implode('/', $out), (int) config('sources.max_path_length'));
        } catch (SourceRejection) {
            return null;
        }
    }

    public static function within(string $path, string $root): bool
    {
        return $root === '.' || $path === $root || str_starts_with($path, rtrim($root, '/').'/');
    }

    public static function join(string $root, string $relative): string
    {
        return ValueResolver::join($root, $relative);
    }

    public static function dirname(string $path): string
    {
        $dir = dirname($path);

        return $dir === '' ? '.' : $dir;
    }
}
