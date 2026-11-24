<?php

namespace App\Policies;

use App\Domain\Workspaces\Capability;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Same split as WorkspacePolicy: anyone who may not see the project gets 404
 * (another tenant, or a restricted project they can't see); a viewer without
 * ManageProjects gets 403.
 */
class ProjectPolicy
{
    public function view(User $user, Project $project): Response
    {
        $role = $user->membershipIn($project->workspace_id)?->role;

        return $role !== null && $project->isVisibleTo($user, $role)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /** File lists and content: Owners, Maintainers, Developers (ReadSourceEvidence). Hidden projects stay 404. */
    public function viewSource(User $user, Project $project): Response
    {
        $role = $user->membershipIn($project->workspace_id)?->role;

        if ($role === null || ! $project->isVisibleTo($user, $role)) {
            return Response::denyAsNotFound();
        }

        return $role->can(Capability::ReadSourceEvidence)
            ? Response::allow()
            : Response::deny('Your role can see import status but not source files.');
    }

    /** Permanent deletion: Owners and Maintainers only. Others who can see it get 403; those who can't, 404. */
    public function delete(User $user, Project $project): Response
    {
        $role = $user->membershipIn($project->workspace_id)?->role;

        if ($role === null || ! $project->isVisibleTo($user, $role)) {
            return Response::denyAsNotFound();
        }

        return $role->can(Capability::DeleteProjects)
            ? Response::allow()
            : Response::deny('Only workspace Owners and Maintainers can delete projects.');
    }

    public function update(User $user, Project $project): Response
    {
        $role = $user->membershipIn($project->workspace_id)?->role;

        if ($role === null || ! $project->isVisibleTo($user, $role)) {
            return Response::denyAsNotFound();
        }

        return $role->can(Capability::ManageProjects)
            ? Response::allow()
            : Response::deny('Your role in this workspace does not allow changing projects.');
    }

    /** Run imports and analyses (Owner, Maintainer, Developer). */
    public function analyze(User $user, Project $project): Response
    {
        $role = $user->membershipIn($project->workspace_id)?->role;

        if ($role === null || ! $project->isVisibleTo($user, $role)) {
            return Response::denyAsNotFound();
        }

        return $role->can(Capability::ImportAndAnalyze)
            ? Response::allow()
            : Response::deny('Your role in this workspace does not allow running analyses.');
    }

    /** Generate documentation drafts (Owner, Maintainer, Developer: SubmitDocs). */
    public function generateDocs(User $user, Project $project): Response
    {
        $role = $user->membershipIn($project->workspace_id)?->role;

        if ($role === null || ! $project->isVisibleTo($user, $role)) {
            return Response::denyAsNotFound();
        }

        return $role->can(Capability::SubmitDocs)
            ? Response::allow()
            : Response::deny('Your role in this workspace does not allow generating documentation.');
    }
}
