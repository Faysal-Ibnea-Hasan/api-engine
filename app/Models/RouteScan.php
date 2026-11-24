<?php

namespace App\Models;

use App\Domain\Sources\RunStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\RouteScanFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $project_id
 * @property string $source_snapshot_id
 * @property string|null $framework_detection_id
 * @property string $application_root
 * @property string $analyzer_version
 * @property string $scan_key
 * @property RunStatus $status
 * @property int $attempts
 * @property string|null $failure_code
 * @property int $endpoint_count
 * @property int $resolved_count
 * @property int $partial_count
 * @property int $unresolved_count
 * @property int $finding_count
 * @property int $file_count
 * @property Carbon|null $claimed_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read SourceSnapshot $snapshot
 */
#[Hidden(['scan_key'])]
class RouteScan extends Model
{
    /** @use HasFactory<RouteScanFactory> */
    use BelongsToWorkspace, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'claimed_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [RunStatus::Completed, RunStatus::Partial], true);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<SourceSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(SourceSnapshot::class, 'source_snapshot_id');
    }

    /** @return HasMany<ApiEndpoint, $this> */
    public function endpoints(): HasMany
    {
        return $this->hasMany(ApiEndpoint::class);
    }

    /** @return HasMany<ScanFinding, $this> */
    public function findings(): HasMany
    {
        return $this->hasMany(ScanFinding::class);
    }
}
