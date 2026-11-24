<?php

namespace App\Models;

use App\Domain\Projects\ProjectStatus;
use App\Domain\Projects\ProjectVisibility;
use App\Domain\Projects\WizardStep;
use App\Domain\Workspaces\WorkspaceRole;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Not mass-assignable: written only through ProjectService.
 *
 * @property string $id
 * @property string $workspace_id
 * @property int|null $created_by
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property ProjectVisibility $visibility
 * @property ProjectStatus $status
 * @property ProjectStatus|null $status_before_archive
 * @property Carbon|null $archived_at
 * @property WizardStep $wizard_step
 * @property Carbon|null $wizard_completed_at
 * @property string|null $source_preference
 * @property string $framework
 * @property string $root_path
 * @property string $analysis_mode
 * @property string $documentation_mode
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use BelongsToWorkspace, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'visibility' => ProjectVisibility::class,
            'status' => ProjectStatus::class,
            'status_before_archive' => ProjectStatus::class,
            'wizard_step' => WizardStep::class,
            'archived_at' => 'datetime',
            'wizard_completed_at' => 'datetime',
            'lock_version' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<SourceSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(SourceSnapshot::class);
    }

    /** @return HasMany<ApiEndpoint, $this> */
    public function endpoints(): HasMany
    {
        return $this->hasMany(ApiEndpoint::class);
    }

    /** @return HasMany<EndpointAnalysis, $this> */
    public function analyses(): HasMany
    {
        return $this->hasMany(EndpointAnalysis::class);
    }

    /**
     * Static documentation drafts; named after the {documentation} route parameter for scoped bindings.
     *
     * @return HasMany<DocumentationVersion, $this>
     */
    public function documentations(): HasMany
    {
        return $this->hasMany(DocumentationVersion::class);
    }

    /** @return HasMany<RouteScan, $this> */
    public function scans(): HasMany
    {
        return $this->hasMany(RouteScan::class);
    }

    /** @return HasMany<UploadSession, $this> */
    public function uploads(): HasMany
    {
        return $this->hasMany(UploadSession::class);
    }

    /** The one visibility rule, shared by the policy and every listing. */
    public function isVisibleTo(User $user, WorkspaceRole $role): bool
    {
        return $this->visibility === ProjectVisibility::Workspace
            || in_array($role, [WorkspaceRole::Owner, WorkspaceRole::Maintainer], true)
            || $this->created_by === $user->id;
    }

    /**
     * Same rule as isVisibleTo(), as a query constraint.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user, WorkspaceRole $role): Builder
    {
        if (in_array($role, [WorkspaceRole::Owner, WorkspaceRole::Maintainer], true)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('visibility', ProjectVisibility::Workspace)
            ->orWhere('created_by', $user->id));
    }

    public function isArchived(): bool
    {
        return $this->status === ProjectStatus::Archived;
    }
}
