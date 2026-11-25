<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** An endpoint analysis of any profile reached completed, partial or failed. Listeners filter. */
final class EndpointAnalysisFinished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly string $analysisId) {}
}
