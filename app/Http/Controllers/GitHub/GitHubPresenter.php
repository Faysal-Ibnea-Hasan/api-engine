<?php

namespace App\Http\Controllers\GitHub;

use App\Domain\GitHub\GitHubError;
use App\Domain\Workspaces\Capability;
use App\Http\Controllers\Sources\SourcePresenter;
use App\Models\GitHubAppSettings;
use App\Models\GitHubImport;
use App\Models\GitHubInstallation;
use App\Models\GitHubRepositorySource;
use App\Models\Project;
use App\Models\User;

/** The only shapes GitHub data leaves the server in: IDs, names, refs and SHAs. Never tokens, codes, URLs from GitHub or raw responses. */
final class GitHubPresenter
{
    /** @return array<string, mixed> */
    public static function installation(GitHubInstallation $installation): array
    {
        $web = rtrim((string) config('github.web_url'), '/');

        return [
            'id' => $installation->id,
            'githubId' => $installation->github_installation_id,
            'accountLogin' => $installation->account_login,
            'accountType' => $installation->account_type,
            'repositorySelection' => $installation->repository_selection,
            'suspended' => $installation->suspended_at !== null,
            // Where the account owner grants or removes repositories (built from IDs, not from GitHub responses).
            'manageUrl' => $installation->account_type === 'Organization'
                ? $web.'/organizations/'.rawurlencode($installation->account_login).'/settings/installations/'.$installation->github_installation_id
                : $web.'/settings/installations/'.$installation->github_installation_id,
        ];
    }

    /** @return array<string, mixed> */
    public static function import(GitHubImport $import): array
    {
        return [
            'id' => $import->id,
            'repository' => $import->repository_full_name,
            'refType' => $import->ref_type,
            'requestedRef' => $import->requested_ref,
            'commitSha' => $import->commit_sha,
            'commitMessage' => $import->commit_message === null ? null : mb_substr(strtok($import->commit_message, "\n") ?: '', 0, 200),
            'committedAt' => $import->committed_at?->toIso8601String(),
            'status' => $import->status,
            'failureCode' => $import->failure_code,
            'failureMessage' => $import->failure_code === null ? null : SourcePresenter::failureMessage($import->failure_code),
            'runId' => $import->processing_run_id,
            'snapshotId' => $import->source_snapshot_id,
            'createdAt' => $import->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> the project page's GitHub tab */
    public static function panel(Project $project, User $user): array
    {
        $source = GitHubRepositorySource::query()->forWorkspace($project->workspace_id)->where('project_id', $project->id)->with('installation')->first();
        $membership = $user->membershipIn($project->workspace_id);
        $editable = ! $project->isArchived();

        return [
            'configured' => GitHubAppSettings::configured(),
            'connected' => GitHubInstallation::query()->whereHas('workspaces', fn ($q) => $q->whereKey($project->workspace_id))->exists(),
            'source' => $source === null ? null : [
                'installationId' => $source->github_installation_id,
                'installationLinked' => $source->installation->isLinkedTo($project->workspace_id),
                'repositoryId' => $source->github_repository_id,
                'fullName' => $source->full_name,
                'visibility' => $source->visibility,
                'defaultBranch' => $source->default_branch,
                'trackedRefType' => $source->tracked_ref_type,
                'trackedRef' => $source->tracked_ref,
                'lastImportedSha' => $source->last_imported_sha,
                'lastImportedAt' => $source->last_imported_at?->toIso8601String(),
            ],
            'imports' => GitHubImport::query()->forWorkspace($project->workspace_id)->where('project_id', $project->id)
                ->latest('created_at')->latest('id')->limit(10)->get()
                ->map(fn (GitHubImport $i) => self::import($i))->all(),
            'canSelect' => $editable && $user->can('update', $project),
            'canImport' => $editable && $user->can('analyze', $project),
            'canConnect' => $membership?->role->can(Capability::ManageIntegrations) === true,
            'workspaceId' => $project->workspace_id,
        ];
    }

    public static function notConfiguredMessage(): string
    {
        return GitHubError::messageFor('github.not_configured');
    }
}
