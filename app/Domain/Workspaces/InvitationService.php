<?php

namespace App\Domain\Workspaces;

use App\Domain\Audit\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

final class InvitationService
{
    public function __construct(private readonly WorkspaceService $workspaces) {}

    /** Caller has checked ManageMembers. Re-inviting the same email replaces the pending invite. */
    public function invite(User $actor, Workspace $workspace, string $email, WorkspaceRole $role): WorkspaceInvitation
    {
        $email = Str::lower($email);
        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($actor, $workspace, $email, $role, $token) {
            $actorRole = WorkspaceMember::query()->forWorkspace($workspace)->where('user_id', $actor->id)->firstOrFail()->role;

            if (! $actorRole->canAssign($role)) {
                throw MembershipError::cannotAssign($role);
            }

            $alreadyMember = WorkspaceMember::query()->forWorkspace($workspace)
                ->whereHas('user', fn ($q) => $q->where('email', $email))
                ->exists();

            if ($alreadyMember) {
                throw MembershipError::alreadyMember();
            }

            WorkspaceInvitation::query()->forWorkspace($workspace)->where('email', $email)->whereNull('accepted_at')->delete();

            $invitation = new WorkspaceInvitation;
            $invitation->forceFill([
                'workspace_id' => $workspace->id,
                'email' => $email,
                'role' => $role,
                'token_hash' => WorkspaceInvitation::hashToken($token),
                'invited_by' => $actor->id,
                'expires_at' => now()->addDays(WorkspaceInvitation::TTL_DAYS),
            ])->save();

            AuditLog::record('member.invited', $workspace, $actor, $invitation, ['email' => $email, 'role' => $role->value]);

            return $invitation;
        });

        Notification::route('mail', $email)->notify(new WorkspaceInvitationNotification($invitation, $token, $actor->name));

        return $invitation;
    }

    public function revoke(User $actor, WorkspaceInvitation $invitation): void
    {
        if ($invitation->accepted_at !== null) {
            throw InvitationError::invalid();
        }

        $invitation->delete();

        AuditLog::record('invitation.revoked', $invitation->workspace, $actor, $invitation, ['email' => $invitation->email]);
    }

    public function findPending(string $token): ?WorkspaceInvitation
    {
        return WorkspaceInvitation::query()->pending()
            ->where('token_hash', WorkspaceInvitation::hashToken($token))
            ->with('workspace:id,name')
            ->first();
    }

    public function isFor(WorkspaceInvitation $invitation, User $user): bool
    {
        return Str::lower($user->email) === $invitation->email;
    }

    /** The invite link proves the email, so the new account starts verified. */
    public function acceptAsNewUser(WorkspaceInvitation $invitation, string $name, string $password): User
    {
        return DB::transaction(function () use ($invitation, $name, $password) {
            // Lock first so two simultaneous accepts can't both try to create the account.
            WorkspaceInvitation::query()->whereKey($invitation->id)->pending()->lockForUpdate()->first()
                ?? throw InvitationError::invalid();

            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => $invitation->email,
                'password' => $password,
                'email_verified_at' => now(),
            ])->save();

            $this->accept($invitation, $user);

            return $user;
        });
    }

    /** Single use: the row lock plus the pending re-check stop a token being spent twice. */
    public function accept(WorkspaceInvitation $invitation, User $user): WorkspaceMember
    {
        return DB::transaction(function () use ($invitation, $user) {
            $locked = WorkspaceInvitation::query()->whereKey($invitation->id)->pending()->lockForUpdate()->first();

            if ($locked === null || ! $this->isFor($locked, $user) || ! $this->inviterCanStillGrant($locked)) {
                throw InvitationError::invalid();
            }

            try {
                $member = $this->workspaces->addMember($locked->workspace, $user, $locked->role);
            } catch (UniqueConstraintViolationException) {
                throw MembershipError::alreadyMember();
            }

            $locked->forceFill(['accepted_at' => now()])->save();
            $user->switchWorkspace($locked->workspace_id);

            AuditLog::record('member.joined', $locked->workspace, $user, $user, ['role' => $locked->role->value]);

            return $member;
        });
    }

    /** A demoted or removed inviter's pending invitations stop working. */
    private function inviterCanStillGrant(WorkspaceInvitation $invitation): bool
    {
        $inviterRole = $invitation->invited_by === null ? null : WorkspaceMember::query()
            ->forWorkspace($invitation->workspace_id)
            ->where('user_id', $invitation->invited_by)
            ->first()?->role;

        return $inviterRole?->canAssign($invitation->role) === true;
    }
}
