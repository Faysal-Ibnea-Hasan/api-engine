<?php

namespace Tests\Concerns;

use App\Domain\Workspaces\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;

trait WorksWithWorkspaces
{
    protected function enableWorkspaces(): void
    {
        config(['features.workspace_management' => true]);
    }

    /** A new verified user holding $role in $workspace (created if omitted). */
    protected function memberOf(WorkspaceRole $role, ?Workspace $workspace = null): User
    {
        $member = WorkspaceMember::factory()
            ->for($workspace ?? Workspace::factory()->create())
            ->role($role)
            ->create();

        return $member->user;
    }

    protected function membershipOf(User $user, Workspace $workspace): WorkspaceMember
    {
        return WorkspaceMember::query()->forWorkspace($workspace)->where('user_id', $user->id)->firstOrFail();
    }
}
