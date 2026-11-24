<?php

namespace App\Http\Controllers\GitHub;

use App\Domain\GitHub\GitHubError;
use App\Domain\GitHub\GitHubRepositories;
use App\Http\Controllers\Controller;
use App\Models\GitHubRepositorySource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** Branches and tags of the project's selected repository, for the ref chooser. */
class GitHubRefController extends Controller
{
    public function __invoke(Project $project, GitHubRepositories $repositories): JsonResponse
    {
        Gate::authorize('analyze', $project);
        $source = GitHubRepositorySource::query()->forWorkspace($project->workspace_id)->where('project_id', $project->id)->with('installation')->first() ?? abort(404);

        if (! $source->installation->isLinkedTo($project->workspace_id)) {
            throw GitHubError::of('github.permission_revoked');
        }

        return response()->json($repositories->refs($source));
    }
}
