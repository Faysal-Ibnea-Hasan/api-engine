<?php

namespace App\Domain\Workspaces;

use App\Domain\Audit\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\DB;

final class WorkspaceService
{
    public function create(User $owner, string $name): Workspace
    {
        return DB::transaction(function () use ($owner, $name) {
            $workspace = Workspace::create(['name' => $name]);
            $this->addMember($workspace, $owner, WorkspaceRole::Owner);
            $owner->switchWorkspace($workspace->id);

            AuditLog::record('workspace.created', $workspace, $owner, $workspace, ['name' => $name]);

            return $workspace;
        });
    }

    public function rename(User $actor, Workspace $workspace, string $name): void
    {
        $from = $workspace->name;
        $workspace->update(['name' => $name]);

        AuditLog::record('workspace.renamed', $workspace, $actor, $workspace, ['from' => $from, 'to' => $name]);
    }

    /** The only place memberships are created. */
    public function addMember(Workspace $workspace, User $user, WorkspaceRole $role): WorkspaceMember
    {
        $member = new WorkspaceMember;
        $member->forceFill([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
        ])->save();

        return $member;
    }
}
