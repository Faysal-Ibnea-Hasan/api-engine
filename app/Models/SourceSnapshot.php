<?php

namespace App\Models;

use App\Domain\Sources\SnapshotStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\SourceSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Immutable once ready: model events refuse to update or delete a ready snapshot,
 * and SnapshotImporter adds `status != ready` to every query-level write.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $project_id
 * @property string $project_source_id
 * @property SnapshotStatus $status
 * @property string $manifest_version
 * @property string|null $sha256
 * @property int $file_count
 * @property int $excluded_count
 * @property int $total_bytes
 * @property string|null $storage_key
 * @property string|null $failure_code
 * @property Carbon|null $ready_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProjectSource $source
 * @property-read Project $project
 */
#[Hidden(['storage_key'])]
class SourceSnapshot extends Model
{
    /** @use HasFactory<SourceSnapshotFactory> */
    use BelongsToWorkspace, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'status' => SnapshotStatus::class,
            'ready_at' => 'datetime',
            'file_count' => 'integer',
            'excluded_count' => 'integer',
            'total_bytes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (SourceSnapshot $snapshot): void {
            if ($snapshot->getOriginal('status') === SnapshotStatus::Ready) {
                throw new LogicException('A ready snapshot is immutable.');
            }
        };

        static::updating($guard);
        static::deleting($guard);
    }

    /** @return BelongsTo<ProjectSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(ProjectSource::class, 'project_source_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<SourceFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(SourceFile::class);
    }
}
