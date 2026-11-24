<?php

namespace App\Http\Controllers\Workspaces;

use App\Domain\Workspaces\Capability;
use App\Domain\Workspaces\MembershipService;
use App\Domain\Workspaces\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\MemberRoleRequest;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceMemberController extends Controller
{
    public function index(Request $request, Workspace $workspace): Response
    {
        Gate::authorize('view', $workspace);

        $role = $request->user()->membershipIn($workspace)->role; // non-null: Gate::authorize above
        $canManage = $role->can(Capability::ManageMembers);

        return Inertia::render('workspaces/members', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'can' => [
                'manageMembers' => $canManage,
                'updateWorkspace' => $role->can(Capability::UpdateWorkspace),
            ],
            'currentRole' => $role->value,
            'assignableRoles' => collect(WorkspaceRole::cases())
                ->filter(fn (WorkspaceRole $r) => $role->canAssign($r))
                ->map(fn (WorkspaceRole $r) => ['value' => $r->value, 'label' => $r->label()])
                ->values(),
            'members' => $workspace->members()->with('user:id,name,email')->oldest('id')->get()
                ->map(fn (WorkspaceMember $m) => [
                    'id' => $m->id,
                    'name' => $m->user->name,
                    'email' => $m->user->email,
                    'role' => $m->role->value,
                    'roleLabel' => $m->role->label(),
                    'isSelf' => $m->user_id === $request->user()->id,
                ]),
            'invitations' => $canManage
                ? $workspace->invitations()->pending()->latest()->get()
                    ->map(fn (WorkspaceInvitation $i) => [
                        'id' => $i->id,
                        'email' => $i->email,
                        'roleLabel' => $i->role->label(),
                        'expiresAt' => $i->expires_at->toIso8601String(),
                    ])
                : [],
        ]);
    }

    /** Authorized in MemberRoleRequest. */
    public function update(MemberRoleRequest $request, Workspace $workspace, WorkspaceMember $member, MembershipService $memberships): RedirectResponse
    {
        $memberships->changeRole($request->user(), $member, $request->role());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role updated.')]);

        return back();
    }

    public function destroy(Request $request, Workspace $workspace, WorkspaceMember $member, MembershipService $memberships): RedirectResponse
    {
        $leaving = $member->user_id === $request->user()->id;

        if ($leaving) {
            Gate::authorize('view', $workspace);
        } else {
            Gate::authorize('perform', [$workspace, Capability::ManageMembers]);
        }

        $memberships->remove($request->user(), $member);

        Inertia::flash('toast', ['type' => 'success', 'message' => $leaving ? __('You left the workspace.') : __('Member removed.')]);

        return $leaving ? to_route('workspaces.index') : back();
    }
}
