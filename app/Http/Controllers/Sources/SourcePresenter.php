<?php

namespace App\Http\Controllers\Sources;

use App\Domain\GitHub\GitHubError;
use App\Domain\Sources\SnapshotStatus;
use App\Domain\Sources\SourceRejection;
use App\Models\ProcessingRun;
use App\Models\Project;
use App\Models\SourceFile;
use App\Models\SourceSnapshot;

/**
 * The only shapes source data leaves the server in. Never storage keys, quarantine
 * paths, idempotency keys or exception text: failures are a fixed message per code.
 */
final class SourcePresenter
{
    /** @return array<string, mixed> */
    public static function run(ProcessingRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status->value,
            'active' => $run->status->isActive(),
            'attempts' => $run->attempts,
            'deduplicated' => $run->deduplicated,
            'failureCode' => $run->failure_code,
            'failureMessage' => $run->failure_code === null ? null : self::failureMessage($run->failure_code),
            'snapshotId' => $run->status->isActive() ? null : $run->source_snapshot_id,
            'cancelRequested' => $run->cancel_requested_at !== null,
            'createdAt' => $run->created_at?->toIso8601String(),
            'finishedAt' => $run->finished_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function snapshot(SourceSnapshot $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'status' => $snapshot->status->value,
            'sourceType' => $snapshot->source->type->value,
            'sha256' => $snapshot->sha256,
            'manifestVersion' => $snapshot->manifest_version,
            'fileCount' => $snapshot->file_count,
            'excludedCount' => $snapshot->excluded_count,
            'totalBytes' => $snapshot->total_bytes,
            'readyAt' => $snapshot->ready_at?->toIso8601String(),
            'createdAt' => $snapshot->created_at?->toIso8601String(),
            'failureMessage' => $snapshot->failure_code === null ? null : self::failureMessage($snapshot->failure_code),
        ];
    }

    /** Fixed, user-safe text for a stored failure code (source rejections, or GitHub's stable codes). */
    public static function failureMessage(string $code): string
    {
        return str_starts_with($code, 'github.') ? GitHubError::messageFor($code) : SourceRejection::messageFor($code);
    }

    /** @return array<string, mixed> the project page's Source panel */
    public static function panel(Project $project, bool $canImportFixture, bool $canUpload = false): array
    {
        $runs = ProcessingRun::query()->where('project_id', $project->id)
            ->where('kind', ProcessingRun::KIND_SNAPSHOT_IMPORT)
            ->latest('created_at')->limit(1)->get();
        $snapshots = SourceSnapshot::query()->where('project_id', $project->id)
            ->with('source')->latest('created_at')->limit(10)->get();

        return [
            'latestRun' => $runs->first() === null ? null : self::run($runs->first()),
            'snapshots' => $snapshots->map(fn (SourceSnapshot $s) => self::snapshot($s))->all(),
            'canImportFixture' => $canImportFixture,
            'canUpload' => $canUpload,
            'maxUploadBytes' => (int) config('sources.zip.max_upload_bytes'),
            'maxSourceBytes' => (int) config('sources.max_total_bytes'),
            'rootCandidates' => self::rootCandidates($snapshots->firstWhere('status', SnapshotStatus::Ready)),
        ];
    }

    /**
     * Folders of the latest ready snapshot that contain both artisan and composer.json.
     * P05 picks the real root; this only helps the user see what was found.
     *
     * @return list<string>
     */
    public static function rootCandidates(?SourceSnapshot $snapshot): array
    {
        if ($snapshot === null) {
            return [];
        }

        $paths = SourceFile::query()->where('source_snapshot_id', $snapshot->id)->whereNull('excluded_reason')
            ->where(fn ($q) => $q->where('path', 'artisan')->orWhere('path', 'like', '%/artisan')->orWhere('path', 'composer.json')->orWhere('path', 'like', '%/composer.json'))
            ->pluck('path')->all();

        $dirs = fn (string $file) => array_map(fn ($p) => dirname($p), array_filter($paths, fn ($p) => basename($p) === $file));

        return array_values(array_unique(array_intersect($dirs('artisan'), $dirs('composer.json'))));
    }
}
