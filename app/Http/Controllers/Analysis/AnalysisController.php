<?php

namespace App\Http\Controllers\Analysis;

use App\Domain\Analysis\EndpointAnalyses;
use App\Domain\Sources\SnapshotReader;
use App\Http\Controllers\Controller;
use App\Models\AnalysisEvidence;
use App\Models\ApiEndpoint;
use App\Models\EndpointAnalysis;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class AnalysisController extends Controller
{
    /** Lines of context shown around an evidence range, and the most an excerpt may hold. */
    private const CONTEXT = 3;

    private const MAX_LINES = 200;

    public function store(Request $request, Project $project, ApiEndpoint $endpoint, EndpointAnalyses $analyses): RedirectResponse
    {
        Gate::authorize('analyze', $project);

        $reanalyze = $request->boolean('reanalyze');
        $analyses->start($request->user(), $endpoint, $reanalyze);
        Inertia::flash('toast', ['type' => 'info', 'message' => $reanalyze ? __('Re-analysis started.') : __('Analysis started.')]);

        return back();
    }

    /** Polled by the endpoint page while the analysis runs. */
    public function show(Request $request, Project $project, EndpointAnalysis $analysis): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json(AnalysisPresenter::analysis($analysis, $request->user()->can('viewSource', $project)));
    }

    /** The exact lines of the immutable file version a fact rests on. Source access only. */
    public function evidence(Request $request, Project $project, EndpointAnalysis $analysis, AnalysisEvidence $evidence, SnapshotReader $reader): JsonResponse
    {
        Gate::authorize('viewSource', $project);

        $code = $reader->read($analysis->snapshot, $evidence->path);
        abort_if($code === null, 404);

        $lines = preg_split('/\R/', $code) ?: [];
        $from = max(1, $evidence->start_line - self::CONTEXT);
        $to = min(count($lines), $evidence->end_line + self::CONTEXT, $from + self::MAX_LINES - 1);

        $excerpt = [];
        for ($n = $from; $n <= $to; $n++) {
            $excerpt[] = ['n' => $n, 'text' => mb_scrub($lines[$n - 1], 'UTF-8'), 'hit' => $n >= $evidence->start_line && $n <= $evidence->end_line];
        }

        return response()->json([
            'id' => $evidence->evidence_key,
            'path' => $evidence->path,
            'sha256' => $evidence->sha256,
            'symbol' => $evidence->symbol,
            'kind' => $evidence->kind,
            'lines' => [$evidence->start_line, $evidence->end_line],
            'truncated' => $to < $evidence->end_line,
            'excerpt' => $excerpt,
        ]);
    }
}
