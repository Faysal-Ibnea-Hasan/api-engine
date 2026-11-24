<?php

namespace App\Domain\Sources;

use App\Contracts\Sources\SourceImportResult;
use App\Contracts\Sources\SourceProvider;
use App\Domain\Audit\AuditLog;
use App\Domain\GitHub\GitHubConnectionService;
use App\Domain\GitHub\GitHubError;
use App\Domain\GitHub\GitHubRepositories;
use App\Domain\Projects\ProjectError;
use App\Jobs\FetchGitHubArchiveJob;
use App\Models\GitHubImport;
use App\Models\GitHubRepositorySource;
use App\Models\ProcessingRun;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * P09. Resolves the requested ref to one commit SHA *before* anything is downloaded, records
 * that origin, and hands a deferred run to FetchGitHubArchiveJob (payload: the run ID only).
 * The job's archive then goes through the same SnapshotImporter as a ZIP upload.
 *
 * Re-importing a commit that is importing or imported returns that run (idempotent); a branch
 * that moved resolves to a new SHA and so to a new snapshot.
 */
final class GitHubSourceProvider implements SourceProvider
{
    public function __construct(private readonly SnapshotImporter $importer, private readonly GitHubRepositories $repositories) {}

    /** @param  array{ref_type?: string, ref?: string|null}  $configuration */
    public function import(User $actor, Project $project, array $configuration): SourceImportResult
    {
        GitHubConnectionService::settings(); // github.not_configured

        if ($project->isArchived()) {
            throw ProjectError::archived();
        }

        $source = GitHubRepositorySource::query()->forWorkspace($project->workspace_id)->where('project_id', $project->id)->with('installation')->first()
            ?? throw GitHubError::of('github.repository_not_found');
        $installation = $source->installation;

        if (! $installation->isLinkedTo($project->workspace_id)) {
            throw GitHubError::of('github.permission_revoked'); // disconnected: history stays, new pulls stop
        }

        GitHubRepositories::refresh($source, $this->repositories->fetch($installation, $source->github_repository_id, $project->workspace_id));
        $refType = (string) ($configuration['ref_type'] ?? 'default');
        $commit = $this->repositories->resolve($source, $refType, $configuration['ref'] ?? null);
        $source->forceFill(['tracked_ref_type' => $refType, 'tracked_ref' => $commit['ref']])->save();

        $fingerprint = "github:{$installation->github_installation_id}:{$source->github_repository_id}:{$commit['sha']}";

        /** @var array{ProcessingRun, GitHubImport, bool} $started */
        $started = DB::transaction(function () use ($actor, $project, $source, $refType, $commit, $fingerprint) {
            GitHubRepositorySource::query()->whereKey($source->id)->lockForUpdate()->first(); // one start per project at a time

            $existing = GitHubImport::query()->where('project_id', $project->id)
                ->where('github_repository_source_id', $source->id)
                ->where('github_repository_id', $source->github_repository_id)
                ->where('commit_sha', $commit['sha'])->with('run')->first();

            if ($existing?->run !== null && ($existing->run->status->isActive() || $existing->run->status === RunStatus::Completed)) {
                return [$existing->run, $existing, true];
            }

            $import = $existing ?? new GitHubImport; // a failed or cancelled import of this commit is retried in place
            [$run, $alreadyRunning] = $this->importer->beginDeferred($actor, $project, SourceType::Git, $fingerprint, function (ProcessingRun $run) use ($import, $actor, $project, $source, $refType, $commit) {
                $import->forceFill([
                    'workspace_id' => $project->workspace_id,
                    'project_id' => $project->id,
                    'github_repository_source_id' => $source->id,
                    'github_repository_id' => $source->github_repository_id,
                    'repository_full_name' => $source->full_name,
                    'processing_run_id' => $run->id,
                    'source_snapshot_id' => null,
                    'requested_ref' => $commit['ref'],
                    'ref_type' => $refType,
                    'commit_sha' => $commit['sha'],
                    'commit_message' => $commit['message'],
                    'committed_at' => $commit['committedAt'] === null ? null : now()->parse($commit['committedAt']),
                    'status' => GitHubImport::QUEUED,
                    'failure_code' => null,
                    'created_by' => $actor->id,
                ])->save();
            });

            return [$run, GitHubImport::query()->where('processing_run_id', $run->id)->first() ?? $import, $alreadyRunning];
        });

        [$run, $import, $reused] = $started;

        if (! $reused) {
            AuditLog::record('github.import_started', $project->workspace, $actor, $import, [
                'installation_id' => $installation->github_installation_id,
                'repository_id' => $source->github_repository_id,
                'full_name' => $source->full_name,
                'commit_sha' => $commit['sha'],
                'run_id' => $run->id,
            ]);

            try {
                FetchGitHubArchiveJob::dispatch($run->id);
            } catch (Throwable $e) {
                report($e);
                $this->importer->fail($run->id, 'internal'); // never leave a queued run blocking this commit
            }
        }

        return SourceImportResult::fromRun($run->refresh(), alreadyRunning: $reused);
    }
}
