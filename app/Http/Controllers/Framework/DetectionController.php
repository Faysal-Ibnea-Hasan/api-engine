<?php

namespace App\Http\Controllers\Framework;

use App\Domain\Framework\FrameworkDetections;
use App\Domain\Projects\ProjectError;
use App\Domain\Sources\SnapshotStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Framework\SelectRootRequest;
use App\Models\Project;
use App\Models\SourceSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class DetectionController extends Controller
{
    /** Runs (or returns) detection for a ready snapshot. Reads files only; never runs them. */
    public function detect(Request $request, Project $project, SourceSnapshot $snapshot, FrameworkDetections $detections): RedirectResponse
    {
        Gate::authorize('update', $project);

        if ($project->isArchived()) {
            throw ProjectError::archived();
        }

        abort_unless($snapshot->status === SnapshotStatus::Ready, 409, __('Only a ready snapshot can be inspected.'));
        $detections->detect($snapshot);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Framework detection finished.')]);

        return back();
    }

    public function show(Request $request, Project $project, SourceSnapshot $snapshot, FrameworkDetections $detections): JsonResponse
    {
        Gate::authorize('view', $project);
        $detection = $detections->current($snapshot);
        abort_if($detection === null, 404);

        return response()->json(DetectionPresenter::present($detection, $request->user()->can('viewSource', $project)));
    }

    public function selectRoot(SelectRootRequest $request, Project $project, SourceSnapshot $snapshot, FrameworkDetections $detections): RedirectResponse
    {
        $detection = $detections->current($snapshot);
        abort_if($detection === null, 404);

        $detections->selectRoot($request->user(), $detection, $request->string('root')->value());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Application root saved.')]);

        return back();
    }
}
