<?php

namespace App\Http\Controllers\Analysis;

use App\Domain\Analysis\EndpointAnalyses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analysis\TraceWorkflowRequest;
use App\Models\AnalysisEvidence;
use App\Models\ApiDependency;
use App\Models\EndpointAnalysis;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The workflow graph (P10) of the endpoint behind an analysis. Both routes take the analysis the
 * endpoint page shows (basic) and work on its advanced sibling: a separate row and fingerprint, so
 * a basic result is never replaced or flagged stale by tracing.
 */
class WorkflowController extends Controller
{
    /** Start (or rerun) the advanced analysis of this analysis's endpoint. */
    public function store(TraceWorkflowRequest $request, Project $project, EndpointAnalysis $analysis, EndpointAnalyses $analyses): RedirectResponse
    {
        $endpoint = $analysis->endpoint;
        abort_if($endpoint === null, 404);

        $reanalyze = $request->boolean('reanalyze');
        $analyses->start($request->user(), $endpoint, $reanalyze, EndpointAnalyses::ADVANCED);
        Inertia::flash('toast', ['type' => 'info', 'message' => $reanalyze ? __('Workflow tracing started again.') : __('Workflow tracing started.')]);

        return back();
    }

    /** Polled by the Workflow tab. Same access as the analysis; source refs only for roles that may read source. */
    public function show(Request $request, Project $project, EndpointAnalysis $analysis): JsonResponse
    {
        Gate::authorize('view', $project);
        $withSource = $request->user()->can('viewSource', $project);

        $advanced = $analysis->profile === EndpointAnalyses::ADVANCED ? $analysis
            : ($analysis->endpoint === null ? null : EndpointAnalysis::query()->where('analysis_key', EndpointAnalyses::key($analysis->endpoint, EndpointAnalyses::ADVANCED))->first());

        $graph = null;
        if ($advanced !== null && $advanced->isFinal() && isset($advanced->result['graph'])) {
            $graph = AnalysisPresenter::graph($advanced->result['graph'], $withSource);
            $refs = $withSource ? AnalysisEvidence::query()->where('endpoint_analysis_id', $advanced->id)->get()->keyBy('evidence_key') : collect();
            // Edges from the normalized rows; nodes and coverage travel with the result.
            $graph['edges'] = ApiDependency::query()->where('endpoint_analysis_id', $advanced->id)->orderBy('id')->get()->map(fn (ApiDependency $d) => [
                'from' => $d->from_node,
                'to' => $d->to_node,
                'kind' => $d->kind,
                'conditional' => $d->conditional,
                'resolution' => $d->resolution,
                'reason' => $d->reason,
                'async' => $d->async,
                'note' => $d->note,
                ...($withSource ? [
                    'expression' => $d->expression,
                    'refs' => collect($d->evidence ?? [])->map(fn (string $id) => $refs->get($id))->filter()
                        ->map(fn (AnalysisEvidence $e) => ['id' => $e->evidence_key, 'path' => $e->path, 'lines' => [$e->start_line, $e->end_line]])->values(),
                ] : []),
            ])->values();
        }

        return response()->json([
            'advanced' => $advanced === null ? null : [
                'id' => $advanced->id,
                'status' => $advanced->status->value,
                'active' => $advanced->status->isActive(),
                'failureCode' => $advanced->failure_code,
                'stale' => EndpointAnalyses::isStale($advanced),
                'finishedAt' => $advanced->finished_at?->toIso8601String(),
            ],
            'canTrace' => $request->user()->can('analyze', $project) && ! $project->isArchived() && $analysis->endpoint !== null,
            'graph' => $graph,
        ]);
    }
}
