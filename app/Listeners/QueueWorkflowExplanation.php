<?php

namespace App\Listeners;

use App\Events\EndpointAnalysisFinished;
use App\Jobs\ExplainWorkflowJob;
use App\Models\EndpointAnalysis;
use App\Models\WorkflowExplanation;

/** Hands a finished trace to the AI step, or fails the waiting explanation when the trace has nothing to explain. */
class QueueWorkflowExplanation
{
    public function handle(EndpointAnalysisFinished $event): void
    {
        $explanation = WorkflowExplanation::query()->where('endpoint_analysis_id', $event->analysisId)->where('status', 'tracing')->first();
        if ($explanation === null) {
            return; // not an explanation's trace, or already handed over
        }

        $analysis = EndpointAnalysis::query()->find($event->analysisId);
        $explainable = $analysis !== null && $analysis->isFinal() && ! empty($analysis->result['graph']);

        // Conditional on 'tracing', so a duplicate event can't queue a second paid run.
        $moved = WorkflowExplanation::query()->whereKey($explanation->id)->where('status', 'tracing')->update($explainable
            ? ['status' => 'queued', 'updated_at' => now()]
            : ['status' => 'failed', 'failure_code' => 'ai.trace_failed', 'finished_at' => now(), 'updated_at' => now()]);

        if ($moved === 1 && $explainable) {
            ExplainWorkflowJob::dispatch($explanation->id);
        }
    }
}
