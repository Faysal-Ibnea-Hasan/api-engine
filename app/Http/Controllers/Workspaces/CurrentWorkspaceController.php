<?php

namespace App\Http\Controllers\Workspaces;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CurrentWorkspaceController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate(['workspace_id' => ['required', 'string']]);

        // Looked up through the user's own memberships: a foreign ID is simply not found.
        $membership = $request->user()->membershipIn($request->string('workspace_id')->value());
        abort_if($membership === null, 404);

        $request->user()->switchWorkspace($membership->workspace_id);

        return to_route('dashboard');
    }
}
