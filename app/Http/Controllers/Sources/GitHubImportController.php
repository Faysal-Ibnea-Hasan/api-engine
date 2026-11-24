<?php

namespace App\Http\Controllers\Sources;

use App\Domain\Sources\GitHubSourceProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sources\StartGitHubImportRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class GitHubImportController extends Controller
{
    /** Resolves the ref to a commit now; the download and import run in workers. */
    public function store(StartGitHubImportRequest $request, Project $project, GitHubSourceProvider $github): RedirectResponse
    {
        $result = $github->import($request->user(), $request->project(), [
            'ref_type' => $request->string('ref_type')->value(),
            'ref' => $request->filled('ref') ? $request->string('ref')->value() : null,
        ]);

        Inertia::flash('toast', ['type' => 'info', 'message' => $result->alreadyRunning
            ? __('That commit is already imported or importing.')
            : __('Import started.')]);

        return to_route('projects.show', $project);
    }
}
