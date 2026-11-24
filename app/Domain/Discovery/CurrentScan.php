<?php

namespace App\Domain\Discovery;

use App\Domain\Framework\FrameworkDetections;
use App\Domain\Sources\LatestSnapshot;
use App\Models\FrameworkDetection;
use App\Models\Project;
use App\Models\RouteScan;
use App\Models\SourceSnapshot;

/** Which scan the explorer shows: latest completed import's snapshot + its current root + current analyzer. */
final class CurrentScan
{
    /** @return array{snapshot: SourceSnapshot|null, detection: FrameworkDetection|null, scan: RouteScan|null} */
    public static function for(Project $project): array
    {
        $snapshot = LatestSnapshot::of($project);
        $detection = $snapshot === null ? null : app(FrameworkDetections::class)->current($snapshot);
        $scan = null;

        if ($snapshot !== null && $detection?->selected_root !== null) {
            $key = hash('sha256', implode("\n", [$snapshot->id, RouteDiscoverer::VERSION, $detection->selected_root]));
            $scan = RouteScan::query()->where('scan_key', $key)->first();
        }

        return compact('snapshot', 'detection', 'scan');
    }
}
