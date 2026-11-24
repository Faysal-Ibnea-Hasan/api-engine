<?php

namespace App\Listeners;

use App\Domain\Framework\FrameworkDetections;
use App\Events\SnapshotReady;
use App\Models\SourceSnapshot;
use App\Support\Features;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Runs detection right after an import; idempotent, so a duplicate event is harmless. */
class DetectFrameworkOnSnapshotReady implements ShouldQueue
{
    public string $queue = 'discovery';

    public int $tries = 3;

    public function handle(SnapshotReady $event): void
    {
        if (! Features::enabled('framework_detection')) {
            return;
        }

        $snapshot = SourceSnapshot::query()->find($event->snapshotId);

        if ($snapshot !== null) {
            app(FrameworkDetections::class)->detect($snapshot); // queued listeners get no method injection
        }
    }
}
