<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\GitHubRepositorySourceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The repository a project's Git source points at. The numeric repository ID is the authority.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $project_id
 * @property string $project_source_id
 * @property string $github_installation_id
 * @property int $github_repository_id
 * @property string $owner_login
 * @property string $repository_name
 * @property string $full_name
 * @property string $visibility
 * @property string $default_branch
 * @property string|null $tracked_ref_type
 * @property string|null $tracked_ref
 * @property string|null $last_imported_sha
 * @property Carbon|null $last_imported_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read GitHubInstallation $installation
 * @property-read Project $project
 */
class GitHubRepositorySource extends Model
{
    /** @use HasFactory<GitHubRepositorySourceFactory> */
    use BelongsToWorkspace, HasFactory, HasUuids;

    protected $table = 'github_repository_sources';

    protected function casts(): array
    {
        return ['github_repository_id' => 'integer', 'last_imported_at' => 'datetime'];
    }

    /** @return BelongsTo<GitHubInstallation, $this> */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(GitHubInstallation::class, 'github_installation_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
