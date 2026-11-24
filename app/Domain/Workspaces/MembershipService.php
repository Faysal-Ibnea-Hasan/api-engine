<?php

namespace App\Domain\Workspaces;

use App\Domain\Audit\AuditLog;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\DB;

/**
 * Role changes and removals. Authorization (ManageMembers) is checked by the
 * caller; this enforces the owner rules, under a row lock so two concurrent
 * demotions can't both pass the last-owner check.
 */
final class MembershipService
{
    public function changeRole(User $actor, WorkspaceMember $member, WorkspaceRole $role): void
    {
        DB::transaction(function () use ($actor, $member, $role) {
            $actorRole = $this->lockedRoleOf($actor, $member->workspace_id);
            $member = WorkspaceMember::query()->lockForUpdate()->findOrFail($member->id);

            if ($member->role === $role) {
                return;
            }

            $this->guardOwnerRules($actorRole, $member, $role);

            $from = $member->role;
            $member->forceFill(['role' => $role])->save();

            AuditLog::record('member.role_changed', $member->workspace, $actor, $member->user, [
                'from' => $from->value,
                'to' => $role->value,
            ]);
        });
    }

    /** Removing someone else needs ManageMembers (checked by caller); anyone may remove themself. */
    public function remove(User $actor, WorkspaceMember $member): void
    {
        DB::transaction(function () use ($actor, $member) {
            $actorRole = $this->lockedRoleOf($actor, $member->workspace_id);
            $member = WorkspaceMember::query()->lockForUpdate()->findOrFail($member->id);

            if ($member->user_id !== $actor->id) {
                $this->guardOwnerRules($actorRole, $member, null);
            } elseif ($member->role === WorkspaceRole::Owner) {
                $this->guardNotLastOwner($member);
            }

            $user = $member->user;
            $member->delete();

            if ($user->current_workspace_id === $member->workspace_id) {
                $user->switchWorkspace(null);
            }

            AuditLog::record('member.removed', $member->workspace, $actor, $user, [
                'role' => $member->role->value,
                'self' => $member->user_id === $actor->id,
            ]);
        });
    }

    /** @param  WorkspaceRole|null  $newRole  null means the member is being removed */
    private function guardOwnerRules(WorkspaceRole $actorRole, WorkspaceMember $member, ?WorkspaceRole $newRole): void
    {
        if ($member->role === WorkspaceRole::Owner && $actorRole !== WorkspaceRole::Owner) {
            throw MembershipError::cannotManageOwner();
        }

        if ($newRole !== null && ! $actorRole->canAssign($newRole)) {
            throw MembershipError::cannotAssign($newRole);
        }

        if ($member->role === WorkspaceRole::Owner) {
            $this->guardNotLastOwner($member);
        }
    }

    private function guardNotLastOwner(WorkspaceMember $member): void
    {
        $owners = WorkspaceMember::query()
            ->forWorkspace($member->workspace_id)
            ->where('role', WorkspaceRole::Owner)
            ->lockForUpdate()
            ->count();

        if ($owners <= 1) {
            throw MembershipError::lastOwner();
        }
    }

    private function lockedRoleOf(User $actor, string $workspaceId): WorkspaceRole
    {
        return WorkspaceMember::query()
            ->forWorkspace($workspaceId)
            ->where('user_id', $actor->id)
            ->lockForUpdate()
            ->firstOrFail()
            ->role;
    }
}
