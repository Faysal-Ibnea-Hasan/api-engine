<?php

namespace App\Http\Controllers\GitHub;

use App\Domain\GitHub\GitHubConnectionService;
use App\Domain\Workspaces\Capability;
use App\Http\Controllers\Controller;
use App\Http\Requests\GitHub\StartGitHubConnectionRequest;
use App\Models\GitHubAppSettings;
use App\Models\GitHubInstallation;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** The workspace's GitHub connection. Authorizes, then delegates to GitHubConnectionService. */
class GitHubConnectionController extends Controller
{
    public function show(Request $request, Workspace $workspace): Response
    {
        Gate::authorize('view', $workspace);
        $role = $request->user()->membershipIn($workspace)?->role;

        return Inertia::render('workspaces/integrations/github', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'configured' => GitHubAppSettings::configured(),
            'can' => ['manage' => $role?->can(Capability::ManageIntegrations) === true],
            'installations' => $workspace->githubInstallations()->orderBy('account_login')->get()
                ->map(fn (GitHubInstallation $i) => GitHubPresenter::installation($i) + [
                    'connectedAt' => $i->getRelationValue('pivot')?->getAttribute('created_at')?->toIso8601String(),
                ])->all(),
        ]);
    }

    /** Sends the browser to GitHub to install/authorize the App (an Inertia visit can't follow a cross-site redirect). */
    public function store(StartGitHubConnectionRequest $request, Workspace $workspace, GitHubConnectionService $connections): HttpResponse
    {
        return Inertia::location($connections->start($request->user(), $request->workspace()));
    }

    public function destroy(Request $request, Workspace $workspace, string $installation, GitHubConnectionService $connections): RedirectResponse
    {
        Gate::authorize('perform', [$workspace, Capability::ManageIntegrations]);
        $linked = GitHubInstallation::linkedTo($workspace, $installation) ?? abort(404);

        $connections->disconnect($request->user(), $workspace, $linked);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('GitHub account disconnected. Existing snapshots and documentation are kept.')]);

        return to_route('workspaces.github.show', $workspace);
    }
}
