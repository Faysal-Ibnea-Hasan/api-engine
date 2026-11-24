<?php

namespace App\Jobs;

use App\Domain\Analysis\EndpointAnalyses;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Payload is the analysis ID only. */
class AnalyzeEndpointJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = EndpointAnalyses::JOB_TIMEOUT;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(public readonly string $analysisId)
    {
        $this->onQueue('analysis');
        $this->afterCommit();
    }

    public function handle(EndpointAnalyses $analyses): void
    {
        @ini_set('memory_limit', '512M');
        $analyses->process($this->analysisId);
    }

    public function failed(?Throwable $e): void
    {
        app(EndpointAnalyses::class)->fail($this->analysisId, 'internal');
    }
}
