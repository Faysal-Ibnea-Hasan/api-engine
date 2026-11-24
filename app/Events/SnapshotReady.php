<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A snapshot just became ready (or an import reused one). Later stages listen; Sources depends on none of them. */
final class SnapshotReady
{
    use Dispatchable;

    public function __construct(public readonly string $snapshotId) {}
}
