<?php

namespace App\Http\Controllers\Analysis;

use App\Domain\Ai\AiError;
use App\Domain\Ai\AiGate;
use App\Domain\Ai\AiUsage;
use App\Domain\Ai\WorkflowExplanations;
use App\Domain\Analysis\EndpointAnalyses;
use App\Domain\Projects\ProjectError;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analysis\TraceWorkflowRequest;
use App\Models\AiProvider;
use App\Models\AiRun;
use App\Models\AnalysisEvidence;
use App\Models\ApiDependency;
use App\Models\EndpointAnalysis;
use App\Models\Project;
use App\Models\WorkflowExplanation;
use App\Support\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * The workflow graph (P10) and its AI explanation for the endpoint behind an analysis. Both routes take the analysis the
 * endpoint page shows (basic) and work on its advanced sibling: a separate row and fingerprint, so
 * a basic result is never replaced or flagged stale by tracing.
 */
class WorkflowController extends Controller
{
    /** Trace then explain with AI (spec § 6.5a). An AiError (AI not ready, limit used up) goes back with an error toast. */
    public function store(TraceWorkflowRequest $request, Project $project, EndpointAnalysis $analysis, WorkflowExplanations $explanations): RedirectResponse
    {
        abort_if($analysis->endpoint === null, 404);
        // Before the AI gate: an archived project never starts a paid run, whatever the AI state.
        if ($project->isArchived()) {
            throw ProjectError::archived();
        }

        $explanations->request($request->user(), $analysis, $request->boolean('again'));
        Inertia::flash('toast', ['type' => 'info', 'message' => __('Explaining the workflow…')]);

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
            'canExplain' => $request->user()->can('analyze', $project) && ! $project->isArchived() && $analysis->endpoint !== null,
            'graph' => $graph,
            'ai' => self::ai(),
            'explanation' => self::explanation($advanced, $withSource),
        ]);
    }

    /** @return array<string, mixed> */
    private static function ai(): array
    {
        $gate = AiGate::check();
        $provider = $gate['provider'];
        $meter = $provider === null ? null : AiUsage::meter($provider);

        return [
            'available' => $gate['available'],
            'reason' => $gate['reason'],
            'provider' => $provider === null ? null : (AiProvider::PROVIDERS[$provider->provider] ?? $provider->provider),
            'model' => $provider?->model,
            'effort' => $provider?->effort,
            'left' => $meter['left'] ?? null,
            'period' => ($meter['left'] ?? null) === null ? null : $provider?->limit_period,
            // The settings page only exists with the flag on.
            'settingsUrl' => Gate::allows('platform-admin') && Features::enabled('ai_settings') ? route('ai-settings.edit') : null,
        ];
    }

    /**
     * Only for roles that may read source: the explanation was written from it (spec § 6.8). Others get just
     * the stage of a run in progress, which every role sees (spec § 6.5a).
     *
     * @return array<string, mixed>|null
     */
    private static function explanation(?EndpointAnalysis $advanced, bool $withSource): ?array
    {
        $explanation = $advanced === null ? null : WorkflowExplanation::query()->where('endpoint_analysis_id', $advanced->id)->first();
        $stage = match ($explanation?->status) {
            'tracing' => 'tracing',
            'queued', 'running' => 'writing',
            default => null,
        };
        if (! $withSource) {
            return ['hidden' => true, 'stage' => $stage];
        }
        if ($explanation === null) {
            return null;
        }
        $failure = $explanation->failure_code === null ? null : AiError::of($explanation->failure_code);
        $run = $explanation->ai_run_id === null ? null : AiRun::query()->find($explanation->ai_run_id);

        return [
            'hidden' => false,
            'status' => $explanation->status,
            'stage' => $stage,
            'failureMessage' => $failure?->getMessage(),
            'retryable' => $failure?->retryable() ?? false,
            'markdown' => $explanation->markdown,
            'html' => self::withoutBeacons($explanation->markdown === null ? null : Str::markdown($explanation->markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false])),
            'truncated' => $explanation->truncated,
            'omitted' => $explanation->omitted ?? [],
            'provider' => $explanation->provider === null ? null : (AiProvider::PROVIDERS[$explanation->provider] ?? $explanation->provider),
            'model' => $explanation->model,
            'promptVersion' => $explanation->prompt_version,
            'inputUnits' => $run?->input_tokens,
            'outputUnits' => $run?->output_tokens,
            'finishedAt' => $explanation->finished_at?->toIso8601String(),
        ];
    }

    /**
     * The AI Markdown's HTML, whose raw HTML was stripped: every tag left is CommonMark's own with escaped attributes. Images
     * become their alt text (a remote image is a beacon a prompt injection could fill with data), links get a safe rel.
     */
    private static function withoutBeacons(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }
        $html = (string) preg_replace_callback('/<img\b[^>]*>/', fn (array $m) => preg_match('/\balt="([^"]*)"/', $m[0], $alt) === 1 ? $alt[1] : '', $html);

        return (string) preg_replace('/<a\b([^>]*)>/', '<a$1 rel="noopener noreferrer nofollow">', $html);
    }
}
