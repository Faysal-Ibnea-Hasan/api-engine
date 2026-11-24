<?php

namespace App\Jobs;

use App\Domain\Discovery\RouteScans;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Payload is the scan ID only. */
class ScanRoutesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = RouteScans::JOB_TIMEOUT;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly string $scanId)
    {
        $this->onQueue('discovery');
    }

    public function handle(RouteScans $scans): void
    {
        // Parsing builds ASTs much larger than their source; give this job (not the app) headroom.
        @ini_set('memory_limit', '1024M');
        $scans->process($this->scanId);
    }

    public function failed(?Throwable $e): void
    {
        app(RouteScans::class)->fail($this->scanId, 'internal');
    }
}
