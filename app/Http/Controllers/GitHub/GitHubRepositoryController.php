<?php

namespace App\Http\Controllers\GitHub;

use App\Domain\GitHub\GitHubRepositories;
use App\Http\Controllers\Controller;
use App\Http\Requests\GitHub\SelectGitHubRepositoryRequest;
use App\Models\GitHubInstallation;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/** The repository picker. Every installation is looked up through the project's workspace link: anything else is 404. */
class GitHubRepositoryController extends Controller
{
    public function installations(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        return response()->json(GitHubInstallation::query()->whereHas('workspaces', fn ($q) => $q->whereKey($project->workspace_id))
            ->orderBy('account_login')->get()->map(fn (GitHubInstallation $i) => GitHubPresenter::installation($i))->all());
    }

    public function repositories(Request $request, Project $project, string $installation, GitHubRepositories $repositories): JsonResponse
    {
        Gate::authorize('update', $project);
        $linked = GitHubInstallation::linkedTo($project->workspace_id, $installation) ?? abort(404);

        return response()->json($repositories->list($linked, $project->workspace_id));
    }

    public function update(SelectGitHubRepositoryRequest $request, Project $project, GitHubRepositories $repositories): RedirectResponse
    {
        $linked = GitHubInstallation::linkedTo($project->workspace_id, $request->string('installation_id')->value()) ?? abort(404);
        $source = $repositories->select($request->user(), $project, $linked, $request->integer('repository_id'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Repository :name selected.', ['name' => $source->full_name])]);

        return to_route('projects.show', $project);
    }
}
