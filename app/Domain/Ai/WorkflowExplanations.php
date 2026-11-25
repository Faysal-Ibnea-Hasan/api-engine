<?php

namespace App\Domain\Ai;

use App\Domain\Analysis\EndpointAnalyses;
use App\Domain\Sources\RunStatus;
use App\Events\EndpointAnalysisFinished;
use App\Listeners\QueueWorkflowExplanation;
use App\Models\EndpointAnalysis;
use App\Models\User;
use App\Models\WorkflowExplanation;
use Illuminate\Support\Facades\DB;

/** Starts "trace then explain" for an endpoint (spec § 6.5): one explanation row per advanced analysis. */
final class WorkflowExplanations
{
    /** The database queue's retry_after (config/queue.php): a queued or running row untouched for longer lost its job. */
    private const STALE_AFTER = 1900;

    public function __construct(private readonly EndpointAnalyses $analyses) {}

    public function request(User $actor, EndpointAnalysis $basic, bool $again): WorkflowExplanation
    {
        $gate = AiGate::check();
        if (! $gate['available'] || $gate['provider'] === null) {
            throw AiError::withMessage($gate['code'] === 'limit_reached' ? 'ai.limit_reached' : 'ai.unavailable', (string) $gate['reason']);
        }
        $provider = $gate['provider'];
        $endpoint = $basic->endpoint ?? throw AiError::of('ai.trace_failed');

        $advanced = EndpointAnalysis::query()->where('analysis_key', EndpointAnalyses::key($endpoint, EndpointAnalyses::ADVANCED))->first();
        $existing = $advanced === null ? null : WorkflowExplanation::query()->where('endpoint_analysis_id', $advanced->id)->first();

        if ($existing?->status === 'tracing') {
            // Keeps P10's lost-trace recovery, and hands over a trace whose finish event was missed.
            $this->handOver($this->analyses->start($actor, $endpoint, false, EndpointAnalyses::ADVANCED));

            return $existing->refresh();
        }
        if ($existing !== null && self::inFlight($existing)) {
            return $existing; // no duplicate paid run
        }

        // Outside the transaction: start()'s double-click recovery re-reads the winner's row, which a REPEATABLE READ snapshot would hide.
        $advanced = $this->analyses->start($actor, $endpoint, $again || $advanced === null || EndpointAnalyses::isStale($advanced) || ! $advanced->isFinal(), EndpointAnalyses::ADVANCED);

        $explanation = DB::transaction(function () use ($actor, $advanced, $provider) {
            // Lock the trace first: a worker finishing it now commits after our row, so its event finds the row.
            // It also serializes concurrent first requests before they reach the explanation row.
            $advanced = EndpointAnalysis::query()->lockForUpdate()->findOrFail($advanced->id);
            $existing = WorkflowExplanation::query()->where('endpoint_analysis_id', $advanced->id)->lockForUpdate()->first();
            if ($existing !== null && self::inFlight($existing)) {
                return $existing; // a concurrent request got here first
            }

            $explanation = $existing ?? new WorkflowExplanation;
            $explanation->forceFill([
                'endpoint_analysis_id' => $advanced->id,
                'workspace_id' => $advanced->workspace_id,
                'project_id' => $advanced->project_id,
                'status' => 'tracing',
                'ai_provider_id' => $provider->id,
                'key_version' => $provider->key_version,
                'provider' => $provider->provider,
                'model' => $provider->model,
                'effort' => $provider->effort,
                'requested_by' => $actor->id,
                'markdown' => null,
                'truncated' => false,
                'omitted' => null,
                'ai_run_id' => null,
                'failure_code' => null,
                'started_at' => null,
                'finished_at' => null,
            ])->save();

            $this->handOver($advanced);

            return $explanation;
        });

        return $explanation->refresh();
    }

    /** A trace that is no longer queued or running: the listener queues the AI step (final) or fails the row (trace failed). */
    private function handOver(EndpointAnalysis $advanced): void
    {
        if (! in_array($advanced->status, [RunStatus::Queued, RunStatus::Running], true)) {
            app(QueueWorkflowExplanation::class)->handle(new EndpointAnalysisFinished($advanced->id));
        }
    }

    /** Still being worked on: tracing, or queued/running and touched within the queue's retry window (a lost job's row is not). */
    private static function inFlight(WorkflowExplanation $explanation): bool
    {
        return $explanation->status === 'tracing'
            || ($explanation->isActive() && $explanation->updated_at?->gt(now()->subSeconds(self::STALE_AFTER)) === true);
    }
}
