<?php

namespace App\Policies;

use App\Domain\Workspaces\Capability;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

/**
 * Non-members get a 404 (never learn the workspace exists); members without
 * the capability get a 403. Use: Gate::authorize('perform', [$workspace, Capability::X]).
 */
class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): Response
    {
        return $user->membershipIn($workspace) !== null
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function perform(User $user, Workspace $workspace, Capability $capability): Response
    {
        $membership = $user->membershipIn($workspace);

        if ($membership === null) {
            return Response::denyAsNotFound();
        }

        return $membership->role->can($capability)
            ? Response::allow()
            : Response::deny('Your role in this workspace does not allow this.');
    }
}
