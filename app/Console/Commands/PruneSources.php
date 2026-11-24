<?php

namespace App\Console\Commands;

use App\Domain\Sources\ChunkedUploads;
use App\Domain\Sources\RunStatus;
use App\Domain\Sources\SnapshotImporter;
use App\Domain\Sources\SnapshotStatus;
use App\Models\EndpointAnalysis;
use App\Models\ProcessingRun;
use App\Models\RouteScan;
use App\Models\SourceSnapshot;
use App\Models\UploadSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Daily clean-up of things that never became a ready snapshot. Ready snapshots are
 * never touched, and snapshot storage is only deleted by each failed row's own key,
 * never by scanning the snapshots disk.
 *
 * shortcut: a normal delete, not a secure wipe; at-rest protection comes from disk
 * or object-storage encryption. Revisit with P15 hardening.
 */
class PruneSources extends Command
{
    protected $signature = 'app:prune-sources';

    protected $description = 'Fail abandoned imports and delete expired quarantine and failed-snapshot storage';

    /** Well beyond every retry of a job (3 tries × 1800s + backoff), so only truly lost runs match. */
    private const ABANDONED_AFTER_MINUTES = 240;

    public function handle(SnapshotImporter $importer, ChunkedUploads $uploads): int
    {
        $expiredUploads = $uploads->pruneExpired();

        $abandoned = ProcessingRun::query()
            ->where('kind', ProcessingRun::KIND_SNAPSHOT_IMPORT)
            ->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value])
            ->where('updated_at', '<', now()->subMinutes(self::ABANDONED_AFTER_MINUTES))
            ->pluck('id');

        foreach ($abandoned as $runId) {
            $importer->fail($runId, 'worker_lost'); // also frees the fingerprint for a new import
        }

        // Route scans whose worker died would otherwise block a rescan of the same snapshot forever.
        $abandonedScans = RouteScan::query()
            ->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value])
            ->where('updated_at', '<', now()->subMinutes(self::ABANDONED_AFTER_MINUTES))
            ->update(['status' => RunStatus::Failed->value, 'failure_code' => 'worker_lost', 'claimed_at' => null, 'finished_at' => now(), 'updated_at' => now()]);

        // Same for endpoint analyses: a lost worker must not block re-analysis.
        $abandonedAnalyses = EndpointAnalysis::query()
            ->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value])
            ->where('updated_at', '<', now()->subMinutes(self::ABANDONED_AFTER_MINUTES))
            ->update(['status' => RunStatus::Failed->value, 'failure_code' => 'worker_lost', 'claimed_at' => null, 'finished_at' => now(), 'updated_at' => now()]);

        $quarantine = Storage::disk('quarantine');
        $cutoff = now()->subHours((int) config('sources.quarantine_ttl_hours'))->getTimestamp();
        $removed = 0;

        foreach ($quarantine->directories('runs') as $dir) {
            $runId = basename($dir);
            $active = ProcessingRun::query()->whereKey($runId)
                ->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value])->exists();
            $mtime = @filemtime($quarantine->path($dir)) ?: 0;

            if (! $active || $mtime < $cutoff) {
                $quarantine->deleteDirectory($dir);
                $removed++;
            }
        }

        // Upload folders whose session is gone (e.g. its project was deleted mid-upload).
        foreach ($quarantine->directories('uploads') as $dir) {
            if (! UploadSession::query()->whereKey(basename($dir))->exists()) {
                $quarantine->deleteDirectory($dir);
                $removed++;
            }
        }

        $failed = SourceSnapshot::query()
            ->whereIn('status', [SnapshotStatus::Failed->value, SnapshotStatus::Rejected->value])
            ->where('updated_at', '<', now()->subDays((int) config('sources.failed_snapshot_ttl_days')))
            ->get(['id', 'workspace_id', 'project_id']);

        foreach ($failed as $snapshot) {
            Storage::disk('snapshots')->deleteDirectory($importer->snapshotDir($snapshot));
        }

        $this->components->info("Expired {$expiredUploads} unfinished uploads, failed {$abandoned->count()} abandoned runs {$abandonedScans} abandoned scans and {$abandonedAnalyses} abandoned analyses, removed {$removed} quarantine folders, cleaned {$failed->count()} failed snapshots.");

        return self::SUCCESS;
    }
}
