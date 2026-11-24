<?php

namespace App\Models;

use App\Domain\Sources\RunStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\ProcessingRunFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $project_id
 * @property string|null $project_source_id
 * @property string|null $source_snapshot_id
 * @property string $kind
 * @property RunStatus $status
 * @property string $idempotency_key
 * @property string|null $active_key
 * @property int $attempts
 * @property bool $deduplicated
 * @property string|null $failure_code
 * @property Carbon|null $claimed_at
 * @property Carbon|null $cancel_requested_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $created_by
 * @property string|null $correlation_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read ProjectSource|null $source
 * @property-read SourceSnapshot|null $snapshot
 */
#[Hidden(['idempotency_key', 'active_key'])]
class ProcessingRun extends Model
{
    /** @use HasFactory<ProcessingRunFactory> */
    use BelongsToWorkspace, HasFactory, HasUuids;

    public const KIND_SNAPSHOT_IMPORT = 'snapshot_import';

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'attempts' => 'integer',
            'deduplicated' => 'boolean',
            'claimed_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProjectSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(ProjectSource::class, 'project_source_id');
    }

    /** @return BelongsTo<SourceSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(SourceSnapshot::class, 'source_snapshot_id');
    }
}
