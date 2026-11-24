<?php

namespace App\Http\Controllers\Workspaces;

use App\Domain\Workspaces\Capability;
use App\Domain\Workspaces\InvitationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\InviteMemberRequest;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class WorkspaceInvitationController extends Controller
{
    /** Authorized in InviteMemberRequest. */
    public function store(InviteMemberRequest $request, Workspace $workspace, InvitationService $invitations): RedirectResponse
    {
        $invitations->invite($request->user(), $workspace, $request->string('email')->value(), $request->role());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return back();
    }

    public function destroy(Request $request, Workspace $workspace, WorkspaceInvitation $invitation, InvitationService $invitations): RedirectResponse
    {
        Gate::authorize('perform', [$workspace, Capability::ManageMembers]);

        $invitations->revoke($request->user(), $invitation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation revoked.')]);

        return back();
    }
}
