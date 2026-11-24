<?php

namespace Database\Factories;

use App\Domain\Framework\LaravelDetector;
use App\Models\FrameworkDetection;
use App\Models\SourceSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FrameworkDetection>
 */
class FrameworkDetectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source_snapshot_id' => SourceSnapshot::factory()->ready(),
            'project_id' => fn (array $a) => SourceSnapshot::query()->whereKey($a['source_snapshot_id'])->firstOrFail()->project_id,
            'workspace_id' => fn (array $a) => SourceSnapshot::query()->whereKey($a['source_snapshot_id'])->firstOrFail()->workspace_id,
            'detector_version' => LaravelDetector::VERSION,
            'snapshot_sha256' => str_repeat('a', 64),
            'status' => 'detected',
            'result' => ['roots' => [['path' => '.']], 'warnings' => [], 'evidence' => []],
            'selected_root' => '.',
            'root_selection_source' => 'auto',
        ];
    }
}
