<?php

namespace App\Models;

use App\Domain\Sources\SourceType;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\ProjectSourceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $project_id
 * @property SourceType $type
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProjectSource extends Model
{
    /** @use HasFactory<ProjectSourceFactory> */
    use BelongsToWorkspace, HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['type' => SourceType::class];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasOne<GitHubRepositorySource, $this> */
    public function githubRepositorySource(): HasOne
    {
        return $this->hasOne(GitHubRepositorySource::class);
    }

    /** @return HasMany<SourceSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(SourceSnapshot::class);
    }
}
