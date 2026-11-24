<?php

namespace Database\Factories;

use App\Domain\Discovery\RouteDiscoverer;
use App\Domain\Sources\RunStatus;
use App\Models\RouteScan;
use App\Models\SourceSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RouteScan>
 */
class RouteScanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source_snapshot_id' => SourceSnapshot::factory()->ready(),
            'project_id' => fn (array $a) => SourceSnapshot::query()->whereKey($a['source_snapshot_id'])->firstOrFail()->project_id,
            'workspace_id' => fn (array $a) => SourceSnapshot::query()->whereKey($a['source_snapshot_id'])->firstOrFail()->workspace_id,
            'application_root' => '.',
            'analyzer_version' => RouteDiscoverer::VERSION,
            'scan_key' => hash('sha256', fake()->uuid()),
            'status' => RunStatus::Completed,
        ];
    }
}
