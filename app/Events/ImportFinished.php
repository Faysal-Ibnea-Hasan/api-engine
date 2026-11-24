<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A snapshot import run reached a final status (completed, failed or cancelled). Sources depends on no listener. */
final class ImportFinished
{
    use Dispatchable;

    public function __construct(public readonly string $runId) {}
}
