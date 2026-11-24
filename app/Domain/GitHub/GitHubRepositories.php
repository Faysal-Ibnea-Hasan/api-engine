<?php

namespace App\Domain\GitHub;

use App\Domain\Audit\AuditLog;
use App\Domain\Projects\ProjectError;
use App\Domain\Sources\SnapshotImporter;
use App\Domain\Sources\SourceType;
use App\Models\GitHubInstallation;
use App\Models\GitHubRepositorySource;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Repositories and refs of an installation, read with tokens limited to one repository where
 * possible. Callers have already checked the workspace ↔ installation link.
 */
final class GitHubRepositories
{
    private const MAX_REPOSITORY_PAGES = 10; // shortcut: lists the first 1,000 repositories; add search once installations grow past that

    public const REF_TYPES = ['default', 'branch', 'tag', 'commit'];

    public function __construct(
        private readonly GitHubApiClient $client,
        private readonly GitHubInstallationTokens $tokens,
        private readonly SnapshotImporter $importer,
    ) {}

    /** @return list<array{id: int, fullName: string, visibility: string, defaultBranch: string, archived: bool}> */
    public function list(GitHubInstallation $installation): array
    {
        self::assertUsable($installation);
        $repositories = [];

        for ($page = 1; $page <= self::MAX_REPOSITORY_PAGES; $page++) {
            $response = $this->tokens->with($installation, null, fn (string $token) => $this->client->json('GET', '/installation/repositories', $token, ['per_page' => 100, 'page' => $page]));
            $items = (array) ($response['repositories'] ?? []);

            foreach ($items as $repo) {
                if (is_array($repo) && is_int($repo['id'] ?? null)) {
                    $repositories[] = self::summary($repo);
                }
            }

            if (count($items) < 100) {
                break;
            }
        }

        return $repositories;
    }

    /**
     * The repository by numeric ID, confirmed to still be in the installation (a token limited to it is refused otherwise).
     *
     * @return array<mixed>
     */
    public function fetch(GitHubInstallation $installation, int $repositoryId): array
    {
        self::assertUsable($installation);

        $repo = $this->tokens->with($installation, $repositoryId, fn (string $token) => $this->client->json('GET', GitHubApiClient::path('/repositories/%s', $repositoryId), $token, [], [
            404 => 'github.repository_not_allowed',
            403 => 'github.repository_not_allowed',
        ]));

        if (($repo['id'] ?? null) !== $repositoryId || preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', (string) ($repo['full_name'] ?? '')) !== 1) {
            throw GitHubError::of('github.repository_not_found');
        }

        if (($repo['disabled'] ?? false) === true) {
            throw GitHubError::of('github.repository_not_found');
        }

        return $repo;
    }

    /** Points the project's Git source at this repository (verified first). */
    public function select(User $actor, Project $project, GitHubInstallation $installation, int $repositoryId): GitHubRepositorySource
    {
        if ($project->isArchived()) {
            throw ProjectError::archived();
        }

        $repo = $this->fetch($installation, $repositoryId);

        $source = DB::transaction(function () use ($actor, $project, $installation, $repo) {
            $projectSource = $this->importer->sourceFor($actor, $project, SourceType::Git);
            $source = GitHubRepositorySource::query()->forWorkspace($project->workspace_id)->where('project_id', $project->id)->lockForUpdate()->first()
                ?? (new GitHubRepositorySource)->forceFill(['created_by' => $actor->id]);

            if ($source->github_repository_id !== $repo['id']) {
                $source->forceFill(['tracked_ref_type' => null, 'tracked_ref' => null, 'last_imported_sha' => null, 'last_imported_at' => null]);
            }

            $source->forceFill([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'project_source_id' => $projectSource->id,
                'github_installation_id' => $installation->id,
            ]);
            self::refresh($source, $repo);
            $source->save();

            return $source;
        });

        AuditLog::record('github.repository_selected', $project->workspace, $actor, $source, [
            'installation_id' => $installation->github_installation_id,
            'repository_id' => $source->github_repository_id,
            'full_name' => $source->full_name,
        ]);

        return $source;
    }

    /** @return array{defaultBranch: string, branches: list<string>, tags: list<string>} */
    public function refs(GitHubRepositorySource $source): array
    {
        $installation = $source->installation;
        self::assertUsable($installation);
        $names = fn (string $kind) => $this->tokens->with($installation, $source->github_repository_id, fn (string $token) => array_values(array_filter(array_map(
            fn ($item) => is_array($item) && is_string($item['name'] ?? null) ? $item['name'] : null,
            $this->client->json('GET', GitHubApiClient::path("/repos/%s/%s/{$kind}", $source->owner_login, $source->repository_name), $token, ['per_page' => 100]),
        ))));

        // shortcut: first 100 branches and tags; a full SHA or a typed name still works for anything older
        return ['defaultBranch' => $source->default_branch, 'branches' => $names('branches'), 'tags' => $names('tags')];
    }

    /**
     * Resolves a branch, tag, full SHA or the default branch to one immutable commit, before anything is downloaded.
     *
     * @return array{ref: string, sha: string, message: string|null, committedAt: string|null}
     */
    public function resolve(GitHubRepositorySource $source, string $refType, ?string $ref): array
    {
        $ref = match ($refType) {
            'default' => $source->default_branch,
            'commit' => strtolower((string) $ref),
            'branch', 'tag' => (string) $ref,
            default => throw GitHubError::of('github.ref_not_found'),
        };

        if ($refType === 'commit' ? preg_match('/^[0-9a-f]{40}$/', $ref) !== 1 : ! self::validRef($ref)) {
            throw GitHubError::of('github.ref_not_found');
        }

        $path = GitHubApiClient::path('/repos/%s/%s/commits/', $source->owner_login, $source->repository_name)
            .implode('/', array_map('rawurlencode', explode('/', $ref)));

        $commit = $this->tokens->with($source->installation, $source->github_repository_id, fn (string $token) => $this->client->json('GET', $path, $token, [], [
            404 => 'github.ref_not_found',
            422 => 'github.ref_not_found',
        ]));

        $sha = $commit['sha'] ?? null;
        if (! is_string($sha) || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw GitHubError::of('github.ref_not_found');
        }

        $message = $commit['commit']['message'] ?? null;
        $date = $commit['commit']['committer']['date'] ?? null;

        return [
            'ref' => $ref,
            'sha' => $sha,
            'message' => is_string($message) ? mb_substr($message, 0, 1000) : null,
            'committedAt' => is_string($date) && strtotime($date) !== false ? $date : null,
        ];
    }

    /** Git ref-name rules, bounded: no "..", control characters, spaces or ~^:?*[\, no leading "-" or "/". */
    public static function validRef(string $ref): bool
    {
        return $ref !== '' && strlen($ref) <= 255
            && preg_match('/^(?!-)(?!\/)(?!.*\.\.)(?!.*\/\/)(?!.*@\{)(?!.*\.lock$)(?!.*\/$)(?!.*\.$)[^\x00-\x20\x7F~^:?*\[\\\\]+$/', $ref) === 1;
    }

    /**
     * Copies GitHub's current names for the repository (it may have been renamed or moved).
     *
     * @param  array<mixed>  $repo
     */
    public static function refresh(GitHubRepositorySource $source, array $repo): void
    {
        [$owner, $name] = explode('/', (string) $repo['full_name'], 2);

        $source->forceFill([
            'github_repository_id' => (int) $repo['id'],
            'owner_login' => $owner,
            'repository_name' => $name,
            'full_name' => (string) $repo['full_name'],
            'visibility' => self::visibility($repo),
            'default_branch' => is_string($repo['default_branch'] ?? null) && self::validRef($repo['default_branch']) ? $repo['default_branch'] : 'main',
        ]);
    }

    public static function assertUsable(GitHubInstallation $installation): void
    {
        if ($installation->suspended_at !== null) {
            throw GitHubError::of('github.installation_suspended');
        }
    }

    /**
     * @param  array<mixed>  $repo
     * @return array{id: int, fullName: string, visibility: string, defaultBranch: string, archived: bool}
     */
    private static function summary(array $repo): array
    {
        return [
            'id' => (int) $repo['id'],
            'fullName' => (string) ($repo['full_name'] ?? ''),
            'visibility' => self::visibility($repo),
            'defaultBranch' => (string) ($repo['default_branch'] ?? ''),
            'archived' => ($repo['archived'] ?? false) === true,
        ];
    }

    /** @param  array<mixed>  $repo */
    private static function visibility(array $repo): string
    {
        $visibility = $repo['visibility'] ?? (($repo['private'] ?? true) ? 'private' : 'public');

        return in_array($visibility, ['public', 'private', 'internal'], true) ? $visibility : 'private';
    }
}
