<?php

namespace App\Domain\Workspaces;

use App\Domain\Audit\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class WorkspaceImpersonation
{
    public const SESSION_KEY = 'workspace_impersonation';

    /**
     * @return array{impersonator_id: int, target_user_id: int, workspace_id: string, started_at: string}|null
     */
    public function context(Request $request): ?array
    {
        $context = $request->session()->get(self::SESSION_KEY);

        if (! is_array($context)
            || ! is_int($context['impersonator_id'] ?? null)
            || ! is_int($context['target_user_id'] ?? null)
            || ! is_string($context['workspace_id'] ?? null)
            || ! is_string($context['started_at'] ?? null)) {
            return null;
        }

        return $context;
    }

    public function start(Request $request, Workspace $workspace, WorkspaceMember $member): void
    {
        abort_if($this->context($request) !== null, 403, 'End the current login-as session first.');

        $owner = $request->user();
        abort_unless($owner instanceof User, 403);

        $ownerMembership = $owner->membershipIn($workspace);
        abort_unless($ownerMembership?->role === WorkspaceRole::Owner, 403);
        abort_unless($member->workspace_id === $workspace->id, 404);
        abort_if($member->user_id === $owner->id, 422, 'You cannot log in as yourself.');

        $target = $member->user()->firstOrFail();

        Auth::login($target);
        $request->session()->regenerate();
        $request->session()->forget('auth.password_confirmed_at');
        $request->session()->put(self::SESSION_KEY, [
            'impersonator_id' => $owner->id,
            'target_user_id' => $target->id,
            'workspace_id' => $workspace->id,
            'started_at' => now()->toIso8601String(),
        ]);

        AuditLog::record('member.impersonation_started', $workspace, $owner, $target, [
            'member_role' => $member->role->value,
        ]);
    }

    public function stop(Request $request, string $reason = 'owner_exit'): ?User
    {
        $context = $this->context($request);

        if ($context === null) {
            return null;
        }

        $owner = User::query()->find($context['impersonator_id']);
        $target = User::query()->find($context['target_user_id']);
        $workspace = Workspace::query()->find($context['workspace_id']);

        $request->session()->forget([self::SESSION_KEY, 'auth.password_confirmed_at']);

        if ($owner === null) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return null;
        }

        Auth::login($owner);
        $request->session()->regenerate();

        if ($workspace !== null) {
            $ownerMembership = $owner->membershipIn($workspace);

            if ($ownerMembership !== null) {
                $owner->switchWorkspace($workspace->id);
            }

            AuditLog::record('member.impersonation_ended', $workspace, $owner, $target, [
                'reason' => $reason,
            ]);
        }

        return $owner;
    }
}
