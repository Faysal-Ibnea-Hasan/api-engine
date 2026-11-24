<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\AuditEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only. Written through App\Domain\Audit\AuditLog only.
 *
 * @property int $id
 * @property string|null $workspace_id
 * @property int|null $actor_id
 * @property string $event_type
 * @property string|null $target_type
 * @property string|null $target_id
 * @property array<string, mixed>|null $metadata
 * @property string|null $correlation_id
 * @property Carbon $created_at
 */
class AuditEvent extends Model
{
    /** @use HasFactory<AuditEventFactory> */
    use BelongsToWorkspace, HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
