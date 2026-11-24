<?php

namespace App\Models;

use App\Domain\Workspaces\WorkspaceRole;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\WorkspaceMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Not mass-assignable: memberships are only created by the workspace services.
 *
 * @property int $id
 * @property string $workspace_id
 * @property int $user_id
 * @property WorkspaceRole $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Workspace $workspace
 */
class WorkspaceMember extends Model
{
    /** @use HasFactory<WorkspaceMemberFactory> */
    use BelongsToWorkspace, HasFactory;

    protected function casts(): array
    {
        return ['role' => WorkspaceRole::class];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
