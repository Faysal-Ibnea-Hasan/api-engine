<?php

namespace App\Http\Controllers\Workspaces;

use App\Domain\Workspaces\WorkspaceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\WorkspaceNameRequest;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('workspaces/index', [
            'myWorkspaces' => $request->user()->memberships()->with(['workspace' => fn ($q) => $q->withCount('members')])
                ->get()
                ->map(fn (WorkspaceMember $m) => [
                    'id' => $m->workspace->id,
                    'name' => $m->workspace->name,
                    'role' => $m->role->value,
                    'roleLabel' => $m->role->label(),
                    'members' => $m->workspace->members_count,
                ])
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values(),
        ]);
    }

    public function store(WorkspaceNameRequest $request, WorkspaceService $workspaces): RedirectResponse
    {
        $workspace = $workspaces->create($request->user(), $request->string('name')->value());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workspace created.')]);

        return to_route('workspaces.members.index', $workspace);
    }

    public function update(WorkspaceNameRequest $request, Workspace $workspace, WorkspaceService $workspaces): RedirectResponse
    {
        $workspaces->rename($request->user(), $workspace, $request->string('name')->value());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workspace renamed.')]);

        return back();
    }
}
