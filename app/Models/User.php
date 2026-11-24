<?php

namespace App\Models;

use App\Domain\Workspaces\WorkspaceRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $is_platform_admin
 * @property string|null $current_workspace_id
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'current_workspace_id', 'is_platform_admin'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** @return HasMany<WorkspaceMember, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    public function membershipIn(Workspace|string $workspace): ?WorkspaceMember
    {
        return $this->memberships()->forWorkspace($workspace)->first();
    }

    /**
     * The selected workspace, re-checked against live membership on every call.
     * A stale or revoked selection falls back to the user's oldest membership.
     */
    public function currentMembership(): ?WorkspaceMember
    {
        $membership = $this->current_workspace_id === null
            ? null
            : $this->membershipIn($this->current_workspace_id);

        if ($membership === null) {
            $membership = $this->memberships()->oldest('id')->first();
            $this->switchWorkspace($membership?->workspace_id);
        }

        return $membership;
    }

    /** Caller must have checked membership; never pass a request ID straight in. */
    public function switchWorkspace(?string $workspaceId): void
    {
        if ($this->current_workspace_id !== $workspaceId) {
            $this->forceFill(['current_workspace_id' => $workspaceId])->save();
        }
    }

    /** True when deleting this user would leave a workspace without an owner. */
    public function isSoleOwnerOfAnyWorkspace(): bool
    {
        return $this->memberships()
            ->where('role', WorkspaceRole::Owner)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('workspace_members as other_owners')
                ->whereColumn('other_owners.workspace_id', 'workspace_members.workspace_id')
                ->whereColumn('other_owners.user_id', '!=', 'workspace_members.user_id')
                ->where('other_owners.role', WorkspaceRole::Owner->value))
            ->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_platform_admin' => 'boolean',
        ];
    }
}
