<?php

namespace App\Domain\Sources;

use App\Domain\Audit\AuditLog;
use App\Domain\Projects\ProjectError;
use App\Domain\Projects\ProjectService;
use App\Events\SnapshotReady;
use App\Jobs\ImportSnapshotJob;
use App\Models\ProcessingRun;
use App\Models\Project;
use App\Models\ProjectSource;
use App\Models\SourceFile;
use App\Models\SourceSnapshot;
use App\Models\User;
use Closure;
use FilesystemIterator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * The one path from staged files to an immutable snapshot, shared by every SourceProvider.
 *
 *   begin()   → run (queued) + snapshot (pending), files staged in quarantine, job dispatched
 *   process() → claim run → validate + hash → store blobs by hash → verify → ready (one transaction)
 *
 * Content is stored by SHA-256 under a server-generated key, so untrusted paths never
 * touch the filesystem. Failures store only a fixed failure_code; details go to report().
 */
final class SnapshotImporter
{
    /** Must stay below the queue's retry_after (config/queue.php) so a slow job is never handed to a second worker. */
    public const JOB_TIMEOUT = 1800; // 10 GB archives: unpack + hash + store + verify up to 1 GB

    private const CANCEL_CHECK_EVERY = 100;

    /** Providers that upload an archive put it here (relative to the run folder); the worker unpacks it. */
    public const ARCHIVE = 'archive.zip';

    public function __construct(private readonly ProjectService $projects, private readonly ZipExtractor $zips) {}

    /**
     * Starts an import, or returns the identical one already in progress.
     *
     * @param  Closure(Filesystem $quarantine, string $dir): void  $stage  copies the files into quarantine under $dir
     * @return array{ProcessingRun, bool} the run, and whether it was already running
     */
    public function begin(User $actor, Project $project, SourceType $type, string $fingerprint, Closure $stage, bool $retried = false): array
    {
        if ($project->isArchived()) {
            throw ProjectError::archived();
        }

        $activeKey = hash('sha256', ProcessingRun::KIND_SNAPSHOT_IMPORT."|{$project->id}|{$fingerprint}");

        try {
            $run = DB::transaction(function () use ($actor, $project, $type, $fingerprint, $activeKey) {
                $source = $this->sourceFor($actor, $project, $type);

                $snapshot = new SourceSnapshot;
                $snapshot->forceFill([
                    'workspace_id' => $project->workspace_id,
                    'project_id' => $project->id,
                    'project_source_id' => $source->id,
                    'status' => SnapshotStatus::Pending,
                    'manifest_version' => Manifest::VERSION,
                ])->save();

                $run = new ProcessingRun;
                $run->forceFill([
                    'workspace_id' => $project->workspace_id,
                    'project_id' => $project->id,
                    'project_source_id' => $source->id,
                    'source_snapshot_id' => $snapshot->id,
                    'kind' => ProcessingRun::KIND_SNAPSHOT_IMPORT,
                    'status' => RunStatus::Queued,
                    'idempotency_key' => hash('sha256', $fingerprint),
                    'active_key' => $activeKey,
                    'created_by' => $actor->id,
                    'correlation_id' => Context::get('correlation_id'),
                ])->save();

                AuditLog::record('source.import_started', $project->workspace, $actor, $run, ['source_type' => $type->value]);

                return $run;
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same import is already queued or running: hand that one back.
            $active = ProcessingRun::query()->where('active_key', $activeKey)->first();

            if ($active !== null) {
                return [$active, true];
            }

            // Lost the race to create this project's source row: it exists now, so try once more.
            if ($retried) {
                throw $e;
            }

            return $this->begin($actor, $project, $type, $fingerprint, $stage, retried: true);
        }

        try {
            $stage($this->quarantine(), $this->quarantineDir($run));
        } catch (Throwable $e) {
            report($e);
            $this->fail($run->id, 'storage_failed');

            return [$run->refresh(), false];
        }

        try {
            ImportSnapshotJob::dispatch($run->id);
        } catch (Throwable $e) {
            report($e);
            $this->fail($run->id, 'internal'); // never leave a queued run blocking this fingerprint
        }

        return [$run->refresh(), false];
    }

    private function sourceFor(User $actor, Project $project, SourceType $type): ProjectSource
    {
        $existing = ProjectSource::query()->where('project_id', $project->id)->where('type', $type->value)->first();

        if ($existing !== null) {
            return $existing;
        }

        $source = new ProjectSource;
        $source->forceFill([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'type' => $type,
            'created_by' => $actor->id,
        ])->save();

        return $source;
    }

    /** Asks a run to stop. A queued run stops at once; a running one at its next checkpoint. */
    public function cancel(ProcessingRun $run): void
    {
        ProcessingRun::query()->whereKey($run->id)->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value])
            ->update(['cancel_requested_at' => now()]);

        $queued = ProcessingRun::query()->whereKey($run->id)->where('status', RunStatus::Queued->value)->whereNull('claimed_at')->exists();

        if ($queued) {
            $this->finishUnsuccessfully($run->id, 'cancelled', SnapshotStatus::Failed, RunStatus::Cancelled);
        }
    }

    public function process(string $runId): void
    {
        if (! $this->claim($runId)) {
            return; // finished, cancelled, or another worker holds it
        }

        $run = ProcessingRun::query()->with('snapshot', 'project')->findOrFail($runId);
        $snapshot = $run->snapshot;

        // A previous attempt already committed: nothing to redo, ready is final.
        if ($snapshot !== null && $snapshot->status === SnapshotStatus::Ready) {
            $this->complete($run, $snapshot, deduplicated: $run->deduplicated);

            return;
        }

        if ($snapshot === null) {
            $this->fail($runId, 'internal');

            return;
        }

        try {
            $this->markValidating($snapshot);
            [$root, $excluded] = $this->prepareFiles($run);
            [$manifest, $onDisk] = $this->buildManifest($root, $excluded);

            $this->checkCancelled($run);

            if ($existing = $this->readyTwin($run->project_id, $manifest->hash())) {
                $this->adopt($run, $snapshot, $existing);

                return;
            }

            // A fresh folder per attempt: a worker that lost a takeover can only ever delete its own files.
            $key = $this->snapshotDir($snapshot).'/'.Str::uuid();
            $this->storeBlobs($run, $key, $manifest, $onDisk);
            $this->verifyBlobs($key, $manifest);
            $this->markReady($run, $snapshot, $key, $manifest);
        } catch (SourceRejection $e) {
            $this->finishUnsuccessfully($runId, $e->failureCode, SnapshotStatus::Rejected, RunStatus::Failed);
        } catch (ImportCancelled) {
            $this->finishUnsuccessfully($runId, 'cancelled', SnapshotStatus::Failed, RunStatus::Cancelled);
        } catch (Throwable $e) {
            // Let the queue retry: release the claim so the next attempt can take it.
            ProcessingRun::query()->whereKey($runId)->where('status', RunStatus::Running->value)->update(['claimed_at' => null]);

            throw $e;
        }
    }

    /** Called when an import cannot be retried any more. Never touches a ready snapshot. */
    public function fail(string $runId, string $failureCode): void
    {
        $run = ProcessingRun::query()->with('snapshot', 'project')->find($runId);

        // The snapshot made it to ready before the error: the import succeeded.
        if ($run !== null && $run->status->isActive() && $run->snapshot?->status === SnapshotStatus::Ready) {
            $this->complete($run, $run->snapshot, deduplicated: $run->deduplicated);

            return;
        }

        $this->finishUnsuccessfully($runId, $failureCode, SnapshotStatus::Failed, RunStatus::Failed);
    }

    private function claim(string $runId): bool
    {
        return ProcessingRun::query()
            ->whereKey($runId)
            ->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value])
            ->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subSeconds(self::JOB_TIMEOUT)))
            ->update([
                'status' => RunStatus::Running->value,
                'claimed_at' => now(),
                'started_at' => DB::raw('COALESCE(started_at, CURRENT_TIMESTAMP)'),
                'attempts' => DB::raw('attempts + 1'),
            ]) === 1;
    }

    private function markValidating(SourceSnapshot $snapshot): void
    {
        SourceSnapshot::query()->whereKey($snapshot->id)
            ->whereIn('status', [SnapshotStatus::Pending->value, SnapshotStatus::Validating->value])
            ->update(['status' => SnapshotStatus::Validating->value, 'updated_at' => now()]);
    }

    /**
     * Where this attempt reads files from. An uploaded archive is unpacked into a fresh
     * folder per attempt, so two workers (or a retry) never share a half-written tree.
     *
     * @return array{string, list<ManifestEntry>} absolute folder, and entries excluded without being written
     */
    private function prepareFiles(ProcessingRun $run): array
    {
        $quarantine = $this->quarantine();
        $archive = $this->runDir($run).'/'.self::ARCHIVE;

        if (! $quarantine->exists($archive)) {
            return [$quarantine->path($this->quarantineDir($run)), []];
        }

        $attempt = $quarantine->path($this->runDir($run).'/attempt-'.Str::uuid());

        return [$attempt, $this->zips->extract($quarantine->path($archive), $attempt)];
    }

    /**
     * @param  list<ManifestEntry>  $excluded  entries already excluded (never written to disk)
     * @return array{Manifest, array<string, string>} the manifest, and each normalized path's real staged file
     */
    private function buildManifest(string $root, array $excluded): array
    {
        $maxFiles = (int) config('sources.max_files');
        $maxTotal = (int) config('sources.max_total_bytes');
        $maxFile = (int) config('sources.max_file_bytes');
        $maxPath = (int) config('sources.max_path_length');

        $manifest = new Manifest;
        $onDisk = [];
        $count = 0; // the stored-file limit counts files on disk; archive entry limits live in ZipExtractor

        foreach ($excluded as $entry) {
            $manifest->add($entry);
        }

        if (! is_dir($root)) {
            throw SourceRejection::empty();
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isLink()) {
                throw SourceRejection::symlink();
            }

            if ($file->isDir()) {
                continue;
            }

            if (++$count > $maxFiles) {
                throw SourceRejection::tooManyFiles();
            }

            $path = SourcePath::normalize(substr($file->getPathname(), strlen($root) + 1), $maxPath);
            $bytes = (int) $file->getSize();
            $reason = FileClassifier::exclusionReason($path, $bytes, $maxFile);

            if ($reason === null && FileClassifier::looksBinary((string) file_get_contents($file->getPathname(), length: 8000))) {
                $reason = 'binary';
            }

            $onDisk[$path] = $file->getPathname(); // NFC in the manifest; the staged name may be NFD

            $manifest->add(new ManifestEntry(
                $path,
                $bytes,
                $reason === null ? (string) hash_file('sha256', $file->getPathname()) : null,
                FileClassifier::language($path),
                $reason,
            ));

            if ($manifest->storedBytes() > $maxTotal) {
                throw SourceRejection::tooLarge();
            }
        }

        if ($manifest->stored() === []) {
            throw SourceRejection::empty();
        }

        return [$manifest, $onDisk];
    }

    /** @param  array<string, string>  $onDisk */
    private function storeBlobs(ProcessingRun $run, string $key, Manifest $manifest, array $onDisk): void
    {
        $disk = $this->snapshots();

        foreach ($manifest->stored() as $i => $entry) {
            if ($i % self::CANCEL_CHECK_EVERY === 0) {
                $this->checkCancelled($run);
            }

            $blob = "{$key}/blobs/{$entry->sha256}";

            if (! $disk->exists($blob)) {
                $stream = fopen($onDisk[$entry->path], 'rb');
                $disk->writeStream($blob, $stream ?: throw new \RuntimeException('Unreadable staged file.'));
                is_resource($stream) && fclose($stream);
            }
        }
    }

    /** Re-read every stored blob: never mark ready before storage is proven. */
    private function verifyBlobs(string $key, Manifest $manifest): void
    {
        $disk = $this->snapshots();

        foreach (array_unique(array_map(fn (ManifestEntry $e) => (string) $e->sha256, $manifest->stored())) as $sha) {
            $stream = $disk->readStream("{$key}/blobs/{$sha}");
            $context = hash_init('sha256');
            hash_update_stream($context, $stream ?: throw new \RuntimeException('Stored blob missing.'));
            fclose($stream);

            if (hash_final($context) !== $sha) {
                throw new \RuntimeException('Stored blob does not match its hash.');
            }
        }
    }

    private function markReady(ProcessingRun $run, SourceSnapshot $snapshot, string $key, Manifest $manifest): void
    {
        try {
            DB::transaction(function () use ($snapshot, $key, $manifest) {
                foreach (array_chunk($manifest->entries(), 500) as $chunk) {
                    SourceFile::query()->insert(array_map(fn (ManifestEntry $e) => [
                        'source_snapshot_id' => $snapshot->id,
                        'path' => $e->path,
                        'path_hash' => hash('sha256', $e->path),
                        'sha256' => $e->sha256,
                        'bytes' => $e->bytes,
                        'language' => $e->language,
                        'excluded_reason' => $e->excludedReason,
                    ], $chunk));
                }

                $updated = SourceSnapshot::query()->whereKey($snapshot->id)
                    ->where('status', SnapshotStatus::Validating->value)
                    ->update([
                        'status' => SnapshotStatus::Ready->value,
                        'sha256' => $manifest->hash(),
                        'file_count' => count($manifest->stored()),
                        'excluded_count' => count($manifest->entries()) - count($manifest->stored()),
                        'total_bytes' => $manifest->storedBytes(),
                        'storage_key' => $key,
                        'ready_at' => now(),
                        'updated_at' => now(),
                    ]);

                if ($updated !== 1) {
                    throw new \RuntimeException('Snapshot left the validating state during import.');
                }
            });
        } catch (UniqueConstraintViolationException) {
            // An identical import became ready first: use that one, drop our copy.
            $this->snapshots()->deleteDirectory($key);
            $this->adopt($run, $snapshot, $this->readyTwin($run->project_id, $manifest->hash()) ?? throw new \RuntimeException('Lost dedupe race without a twin.'));

            return;
        }

        // Drop folders left by this snapshot's earlier attempts; keep only the one now in storage_key.
        foreach ($this->snapshots()->directories($this->snapshotDir($snapshot)) as $attempt) {
            if ($attempt !== $key) {
                $this->snapshots()->deleteDirectory($attempt);
            }
        }

        $this->complete($run, $snapshot->refresh(), deduplicated: false);
    }

    private function readyTwin(string $projectId, string $sha): ?SourceSnapshot
    {
        return SourceSnapshot::query()->where('project_id', $projectId)->where('sha256', $sha)->where('status', SnapshotStatus::Ready->value)->first();
    }

    /** Identical content already exists: point the run at it and drop the never-ready placeholder. */
    private function adopt(ProcessingRun $run, SourceSnapshot $placeholder, SourceSnapshot $existing): void
    {
        if ($placeholder->id !== $existing->id) {
            DB::transaction(function () use ($run, $placeholder, $existing) {
                ProcessingRun::query()->whereKey($run->id)->update(['source_snapshot_id' => $existing->id, 'deduplicated' => true]);
                SourceSnapshot::query()->whereKey($placeholder->id)->where('status', '!=', SnapshotStatus::Ready->value)->delete();
            });
            $this->snapshots()->deleteDirectory($this->snapshotDir($placeholder)); // files of earlier attempts
        }

        $this->complete($run->refresh(), $existing, deduplicated: true);
    }

    private function complete(ProcessingRun $run, SourceSnapshot $snapshot, bool $deduplicated): void
    {
        // Activate before marking the run done, so a failure here is retried instead of skipped for good.
        $this->projects->activate($run->project);

        $finished = ProcessingRun::query()->whereKey($run->id)->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value])->update([
            'status' => RunStatus::Completed->value,
            'active_key' => null,
            'source_snapshot_id' => $snapshot->id,
            'deduplicated' => $deduplicated,
            'finished_at' => now(),
            'updated_at' => now(),
        ]);

        if ($finished === 1) {
            AuditLog::record('source.snapshot_ready', $run->project->workspace, null, $snapshot, [
                'run_id' => $run->id,
                'deduplicated' => $deduplicated,
                'files' => $snapshot->file_count,
            ]);
        }

        try {
            $this->quarantine()->deleteDirectory($this->runDir($run)); // includes the uploaded archive
        } catch (Throwable $e) {
            report($e); // app:prune-sources removes it later
        }

        // Last, and guarded: later stages (detection…) must never undo or block a finished import.
        if ($finished === 1) {
            try {
                SnapshotReady::dispatch($snapshot->id);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private function finishUnsuccessfully(string $runId, string $code, SnapshotStatus $snapshotStatus, RunStatus $runStatus): void
    {
        $run = ProcessingRun::query()->find($runId);

        if ($run === null || ! $run->status->isActive()) {
            return;
        }

        DB::transaction(function () use ($run, $code, $snapshotStatus, $runStatus) {
            if ($run->source_snapshot_id !== null) {
                SourceSnapshot::query()->whereKey($run->source_snapshot_id)
                    ->whereIn('status', [SnapshotStatus::Pending->value, SnapshotStatus::Validating->value])
                    ->update(['status' => $snapshotStatus->value, 'failure_code' => $code, 'storage_key' => null, 'updated_at' => now()]);
            }

            ProcessingRun::query()->whereKey($run->id)->update([
                'status' => $runStatus->value,
                'failure_code' => $code,
                'active_key' => null,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
        });

        // Remove anything written for a snapshot that will never be ready.
        $snapshot = $run->source_snapshot_id === null ? null : SourceSnapshot::query()->find($run->source_snapshot_id);
        if ($snapshot !== null && $snapshot->status !== SnapshotStatus::Ready) {
            $this->snapshots()->deleteDirectory($this->snapshotDir($snapshot));
        }
        $this->quarantine()->deleteDirectory($this->runDir($run)); // includes the uploaded archive

        AuditLog::record($runStatus === RunStatus::Cancelled ? 'source.import_cancelled' : 'source.import_failed', $run->project->workspace, null, $run, ['code' => $code]);
    }

    private function checkCancelled(ProcessingRun $run): void
    {
        if (ProcessingRun::query()->whereKey($run->id)->whereNotNull('cancel_requested_at')->exists()) {
            throw new ImportCancelled;
        }
    }

    /** Parent of every attempt folder for one snapshot. Only ever deleted while the snapshot isn't ready. */
    public function snapshotDir(SourceSnapshot $snapshot): string
    {
        return "{$snapshot->workspace_id}/{$snapshot->project_id}/{$snapshot->id}";
    }

    public function runDir(ProcessingRun $run): string
    {
        return "runs/{$run->id}";
    }

    public function quarantineDir(ProcessingRun $run): string
    {
        return "runs/{$run->id}/files";
    }

    private function quarantine(): Filesystem
    {
        return Storage::disk('quarantine');
    }

    private function snapshots(): Filesystem
    {
        return Storage::disk('snapshots');
    }
}
