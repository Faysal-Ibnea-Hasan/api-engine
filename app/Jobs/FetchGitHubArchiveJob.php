<?php

namespace App\Jobs;

use App\Domain\GitHub\GitHubArchiveDownloader;
use App\Domain\GitHub\GitHubError;
use App\Domain\GitHub\GitHubRepositories;
use App\Domain\Sources\SnapshotImporter;
use App\Models\GitHubImport;
use App\Models\ProcessingRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Downloads the archive of an already-resolved commit into the run's quarantine folder, then
 * queues the ordinary ImportSnapshotJob. Payload is the run ID only: the repository, commit and
 * installation are re-read (and re-verified) from tenant-scoped rows; a fresh token is minted here.
 */
class FetchGitHubArchiveJob implements ShouldQueue
{
    use Queueable;

    /** archive_timeout_seconds (300) plus token and redirect calls. Below Horizon's 1830s and retry_after 1900s. */
    public const TIMEOUT = 600;

    public int $tries = 3;

    public int $timeout = self::TIMEOUT;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly string $runId)
    {
        $this->onQueue('imports');
    }

    public function handle(SnapshotImporter $importer, GitHubArchiveDownloader $downloader): void
    {
        if (! $importer->claim($this->runId)) {
            return; // finished, cancelled, deleted, or another worker holds it
        }

        $run = ProcessingRun::query()->find($this->runId);
        $import = GitHubImport::query()->where('processing_run_id', $this->runId)->with('repositorySource.installation')->first();

        if ($run === null || $import === null || $import->workspace_id !== $run->workspace_id || $import->project_id !== $run->project_id) {
            $importer->fail($this->runId, 'internal');

            return;
        }

        $archive = Storage::disk('quarantine')->path($importer->runDir($run).'/'.SnapshotImporter::ARCHIVE);

        try {
            $source = $import->repositorySource;

            // Verify again: the workspace may have disconnected GitHub, or the project switched repositories, since the request.
            if ($source->workspace_id !== $import->workspace_id || $source->github_repository_id !== $import->github_repository_id
                || ! $source->installation->isLinkedTo($import->workspace_id)) {
                throw GitHubError::of('github.permission_revoked');
            }
            GitHubRepositories::assertUsable($source->installation);

            $import->forceFill(['status' => GitHubImport::DOWNLOADING])->save();
            $downloader->download($source, $import->commit_sha, $archive);
        } catch (GitHubError $e) {
            // HTTP calls already retried 429/5xx; what is left is a stable outcome.
            $importer->fail($this->runId, $e->errorCode());

            return;
        } catch (Throwable $e) {
            $importer->release($this->runId); // let the queue retry this job

            throw $e;
        }

        $import->forceFill(['status' => GitHubImport::IMPORTING])->save();
        $importer->release($this->runId);
        $importer->dispatchImport($this->runId);
    }

    public function failed(?Throwable $e): void
    {
        app(SnapshotImporter::class)->fail($this->runId, 'github.archive_download_failed'); // details are in the log
    }
}
