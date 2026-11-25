<?php

namespace App\Jobs;

use App\Domain\Ai\WorkflowExplainer;
use App\Models\WorkflowExplanation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Payload is the explanation ID only: the row holds the provider and key version, never the key. */
class ExplainWorkflowJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $timeout = 600;

    public function __construct(public readonly int $explanationId)
    {
        $this->onQueue('ai');
        $this->afterCommit();
    }

    public function handle(WorkflowExplainer $explainer): void
    {
        $explainer->run($this->explanationId, $this->attempts() < $this->tries);
    }

    public function failed(?Throwable $e): void
    {
        WorkflowExplanation::query()->whereKey($this->explanationId)->whereIn('status', ['tracing', 'queued', 'running'])
            ->update(['status' => 'failed', 'failure_code' => 'ai.internal', 'finished_at' => now(), 'updated_at' => now()]);
    }
}
