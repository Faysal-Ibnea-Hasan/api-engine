<?php

namespace App\Listeners;

use App\Domain\Audit\AuditLog;
use App\Domain\Sources\RunStatus;
use App\Events\ImportFinished;
use App\Models\GitHubImport;
use App\Models\GitHubRepositorySource;
use App\Models\ProcessingRun;

/** Copies a finished run's outcome onto its GitHub origin record. Runs synchronously; ZIP runs have no origin and are skipped. */
class RecordGitHubImportOutcome
{
    public function handle(ImportFinished $event): void
    {
        $import = GitHubImport::query()->where('processing_run_id', $event->runId)->with('workspace')->first();
        $run = ProcessingRun::query()->find($event->runId);

        if ($import === null || $run === null || $run->status->isActive()) {
            return;
        }

        $completed = $run->status === RunStatus::Completed;
        $import->forceFill([
            'status' => $run->status->value,
            'failure_code' => $run->failure_code,
            'source_snapshot_id' => $completed ? $run->source_snapshot_id : null,
        ])->save();

        if ($completed) {
            GitHubRepositorySource::query()->whereKey($import->github_repository_source_id)
                ->where('github_repository_id', $import->github_repository_id)
                ->update(['last_imported_sha' => $import->commit_sha, 'last_imported_at' => now(), 'updated_at' => now()]);
        }

        AuditLog::record($completed ? 'github.import_completed' : 'github.import_failed', $import->workspace, null, $import, [
            'repository_id' => $import->github_repository_id,
            'full_name' => $import->repository_full_name,
            'commit_sha' => $import->commit_sha,
            'run_id' => $run->id,
            'code' => $run->failure_code,
        ]);
    }
}
