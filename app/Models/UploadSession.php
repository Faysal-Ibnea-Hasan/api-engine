<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\UploadSessionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $project_id
 * @property int $created_by
 * @property int $total_bytes
 * @property int $received_bytes
 * @property string $status
 * @property string|null $processing_run_id
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 */
class UploadSession extends Model
{
    /** @use HasFactory<UploadSessionFactory> */
    use BelongsToWorkspace, HasFactory, HasUuids;

    public const UPLOADING = 'uploading';

    public const COMPLETED = 'completed';

    public const EXPIRED = 'expired';

    protected function casts(): array
    {
        return [
            'total_bytes' => 'integer',
            'received_bytes' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Quarantine path of the bytes received so far (server-generated; never sent to the browser). */
    public function partPath(): string
    {
        return "uploads/{$this->id}/archive.part";
    }
}
