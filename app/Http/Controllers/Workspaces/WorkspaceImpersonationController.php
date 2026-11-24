<?php

namespace App\Http\Controllers\Workspaces;

use App\Domain\Workspaces\WorkspaceImpersonation;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class WorkspaceImpersonationController extends Controller
{
    public function store(
        Request $request,
        Workspace $workspace,
        WorkspaceMember $member,
        WorkspaceImpersonation $impersonation,
    ): RedirectResponse {
        $impersonation->start($request, $workspace, $member);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You are now logged in as :name.', [
            'name' => $member->user->name,
        ])]);

        return to_route('dashboard');
    }

    public function destroy(Request $request, WorkspaceImpersonation $impersonation): RedirectResponse
    {
        abort_if($impersonation->context($request) === null, 404);

        $impersonation->stop($request);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('You are back in your owner account.')]);

        return to_route('dashboard');
    }
}
