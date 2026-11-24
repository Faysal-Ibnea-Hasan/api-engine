<?php

namespace App\Contracts\Analysis;

use App\Models\SourceSnapshot;

/**
 * SHARED_CONTRACTS §5. Reads an immutable snapshot only; never boots, includes or runs it.
 * discover() (P06) and analyze() (P07) join this interface in their phases.
 */
interface FrameworkAnalyzer
{
    public function version(): string;

    public function detect(SourceSnapshot $snapshot): FrameworkDetectionResult;
}
