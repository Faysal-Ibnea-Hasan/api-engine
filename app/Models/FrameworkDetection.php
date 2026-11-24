<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\FrameworkDetectionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The detection facts never change after creation; only the root selection does.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $project_id
 * @property string $source_snapshot_id
 * @property string $detector_version
 * @property string $snapshot_sha256
 * @property string $status
 * @property array<string, mixed> $result
 * @property string|null $selected_root
 * @property string|null $root_selection_source
 * @property int|null $root_selected_by
 * @property Carbon|null $root_selected_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SourceSnapshot $snapshot
 * @property-read Project $project
 */
class FrameworkDetection extends Model
{
    /** @use HasFactory<FrameworkDetectionFactory> */
    use BelongsToWorkspace, HasFactory, HasUuids;

    private const IMMUTABLE = ['workspace_id', 'project_id', 'source_snapshot_id', 'detector_version', 'snapshot_sha256', 'status', 'result'];

    protected function casts(): array
    {
        return ['result' => 'array', 'root_selected_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (FrameworkDetection $detection): void {
            if ($detection->isDirty(self::IMMUTABLE)) {
                throw new LogicException('Detection results are immutable; run a new detector version instead.');
            }
        });
    }

    /** @return BelongsTo<SourceSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(SourceSnapshot::class, 'source_snapshot_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return list<string> */
    public function rootPaths(): array
    {
        $roots = is_array($this->result['roots'] ?? null) ? $this->result['roots'] : [];

        return array_values(array_map(fn ($r) => (string) ($r['path'] ?? ''), $roots));
    }
}
