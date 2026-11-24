<?php

namespace App\Domain\Sources;

use App\Models\SourceFile;
use App\Models\SourceSnapshot;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only access to a ready snapshot's stored files, by manifest path. Content is
 * returned as text for parsing; nothing read here is ever included or executed.
 */
final class SnapshotReader
{
    public function exists(SourceSnapshot $snapshot, string $path): bool
    {
        return $this->file($snapshot, $path) !== null;
    }

    /** Null when the file is missing, excluded, or the snapshot isn't ready. */
    public function read(SourceSnapshot $snapshot, string $path): ?string
    {
        $file = $this->file($snapshot, $path);

        if ($file === null || $file->sha256 === null || $snapshot->storage_key === null) {
            return null;
        }

        return Storage::disk('snapshots')->get("{$snapshot->storage_key}/blobs/{$file->sha256}");
    }

    /**
     * Stored paths under a folder ('.' for the root), optionally matching a pattern.
     *
     * @return array<int, string>
     */
    public function paths(SourceSnapshot $snapshot, string $folder = '.', ?string $like = null): array
    {
        if ($snapshot->status !== SnapshotStatus::Ready) {
            return [];
        }

        $prefix = $folder === '.' ? '' : rtrim($folder, '/').'/';

        return SourceFile::query()
            ->where('source_snapshot_id', $snapshot->id)
            ->whereNull('excluded_reason')
            ->when($prefix !== '', fn ($q) => $q->where('path', 'like', addcslashes($prefix, '%_\\').'%'))
            ->when($like !== null, fn ($q) => $q->where('path', 'like', $like))
            ->orderBy('path')
            ->pluck('path')
            ->map(fn ($path): string => (string) $path)
            ->all();
    }

    private function file(SourceSnapshot $snapshot, string $path): ?SourceFile
    {
        if ($snapshot->status !== SnapshotStatus::Ready) {
            return null;
        }

        return SourceFile::query()
            ->where('source_snapshot_id', $snapshot->id)
            ->where('path_hash', hash('sha256', $path))
            ->whereNull('excluded_reason')
            ->first();
    }
}
