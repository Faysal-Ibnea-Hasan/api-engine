<?php

namespace App\Contracts\Sources;

use App\Domain\Sources\RunStatus;
use App\Models\ProcessingRun;

/** What a provider returns: the run to follow, and the snapshot once it's ready. */
final readonly class SourceImportResult
{
    public function __construct(
        public string $runId,
        public RunStatus $status,
        public ?string $snapshotId,
        public ?string $failureCode,
        /** True when an identical import was already in progress and was returned instead. */
        public bool $alreadyRunning,
    ) {}

    public static function fromRun(ProcessingRun $run, bool $alreadyRunning): self
    {
        return new self($run->id, $run->status, $run->status->isActive() ? null : $run->source_snapshot_id, $run->failure_code, $alreadyRunning);
    }
}
