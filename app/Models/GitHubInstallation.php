<?php

namespace App\Models;

use Database\Factories\GitHubInstallationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A GitHub App installation (provider identity, not source). It has no workspace_id: a workspace
 * may use it only through the workspace_github_installations pivot, so always look it up via
 * GitHubInstallation::linkedTo($workspace).
 *
 * @property string $id
 * @property int $github_installation_id
 * @property int $github_account_id
 * @property string $account_login
 * @property string $account_type
 * @property string $repository_selection
 * @property array<string, string>|null $permissions
 * @property Carbon|null $suspended_at
 * @property Carbon|null $last_verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GitHubInstallation extends Model
{
    /** @use HasFactory<GitHubInstallationFactory> */
    use HasFactory, HasUuids;

    protected $table = 'github_installations';

    protected function casts(): array
    {
        return [
            'github_installation_id' => 'integer',
            'github_account_id' => 'integer',
            'permissions' => 'array',
            'suspended_at' => 'datetime',
            'last_verified_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<Workspace, $this> */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_github_installations', 'github_installation_id', 'workspace_id')
            ->withPivot('connected_by')->withTimestamps();
    }

    /** The installation with this id, only if it is linked to $workspace (else null: callers answer 404). */
    public static function linkedTo(Workspace|string $workspace, string $id): ?self
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return self::query()->whereKey($id)
            ->whereHas('workspaces', fn ($q) => $q->whereKey($workspaceId))
            ->first();
    }

    /**
     * Whether the workspace may read this repository through this installation: linked, and either the
     * whole installation (the connecting user's own account) or one of the repositories they could reach.
     */
    public function allowsRepository(string $workspaceId, int $repositoryId): bool
    {
        $link = DB::table('workspace_github_installations')
            ->where('workspace_id', $workspaceId)->where('github_installation_id', $this->id)->first(['repository_ids']);

        if ($link === null) {
            return false;
        }

        $allowed = self::allowedIds($link->repository_ids);

        return $allowed === null || in_array($repositoryId, $allowed, true);
    }

    /** @return list<int>|null null: every repository of the installation */
    public static function allowedIds(mixed $stored): ?array
    {
        if ($stored === null) {
            return null;
        }

        $ids = is_string($stored) ? json_decode($stored, true) : $stored;

        return array_values(array_filter(is_array($ids) ? $ids : [], 'is_int'));
    }

    public function isLinkedTo(string $workspaceId): bool
    {
        return $this->workspaces()->whereKey($workspaceId)->exists();
    }
}
