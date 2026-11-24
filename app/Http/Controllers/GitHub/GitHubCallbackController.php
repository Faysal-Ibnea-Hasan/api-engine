<?php

namespace App\Http\Controllers\GitHub;

use App\Domain\GitHub\GitHubConnectionService;
use App\Domain\GitHub\GitHubError;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Where GitHub sends the browser back. Errors redirect to our own page explicitly: the global
 * DomainError renderer's back() could follow the Referer to github.com. The query string
 * (code, state) is never logged or stored.
 */
class GitHubCallbackController extends Controller
{
    public function __invoke(Request $request, GitHubConnectionService $connections): RedirectResponse
    {
        $param = fn (string $key): ?string => is_string($value = $request->query($key)) ? $value : null;

        try {
            $workspace = $connections->complete($request->user(), $param('state') ?? '', $param('code'), $param('installation_id'), $param('error'));
        } catch (GitHubError $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);
            $current = $request->user()->currentMembership();

            return $current === null ? to_route('workspaces.index') : to_route('workspaces.github.show', $current->workspace_id);
        }

        // No installation of the app on any account this user can access yet: go install it (that callback comes back here).
        if (is_string($workspace)) {
            return redirect()->away($workspace);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('GitHub connected.')]);

        return to_route('workspaces.github.show', $workspace);
    }
}
