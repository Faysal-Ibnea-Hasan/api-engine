<?php

namespace App\Jobs;

use App\Domain\Sources\SnapshotImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Payload is the run ID only: no paths, file names or credentials go into the queue. */
class ImportSnapshotJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = SnapshotImporter::JOB_TIMEOUT;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly string $runId)
    {
        $this->onQueue('imports');
    }

    public function handle(SnapshotImporter $importer): void
    {
        $importer->process($this->runId);
    }

    public function failed(?Throwable $e): void
    {
        app(SnapshotImporter::class)->fail($this->runId, 'internal'); // details are in the log
    }
}
