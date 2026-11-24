<?php

namespace App\Http\Controllers\Sources;

use App\Domain\Sources\FixtureSourceProvider;
use App\Domain\Sources\SnapshotImporter;
use App\Http\Controllers\Controller;
use App\Models\ProcessingRun;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ProcessingRunController extends Controller
{
    /** Polling endpoint. Authorized through the run's project, so another tenant's run is a plain 404. */
    public function show(ProcessingRun $run): JsonResponse
    {
        Gate::authorize('view', $run->project);

        return response()->json(SourcePresenter::run($run));
    }

    public function cancel(Request $request, ProcessingRun $run, SnapshotImporter $importer): RedirectResponse
    {
        Gate::authorize('update', $run->project);
        $importer->cancel($run);

        Inertia::flash('toast', ['type' => 'info', 'message' => __('Cancelling the import.')]);

        return back();
    }

    /** Local/testing only: lets a developer watch the pipeline work before ZIP upload (P04) exists. */
    public function importFixture(Request $request, Project $project, FixtureSourceProvider $fixtures): RedirectResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
        Gate::authorize('update', $project);

        $result = $fixtures->import($request->user(), $project, ['fixture' => 'laravel-mini']);

        Inertia::flash('toast', ['type' => 'info', 'message' => $result->alreadyRunning
            ? __('That import is already running.')
            : __('Import started.')]);

        return to_route('projects.show', $project);
    }
}
