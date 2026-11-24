<?php

namespace Database\Factories;

use App\Domain\Sources\Manifest;
use App\Domain\Sources\SnapshotStatus;
use App\Models\ProjectSource;
use App\Models\SourceSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceSnapshot>
 */
class SourceSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_source_id' => ProjectSource::factory(),
            'project_id' => fn (array $a) => ProjectSource::query()->whereKey($a['project_source_id'])->firstOrFail()->project_id,
            'workspace_id' => fn (array $a) => ProjectSource::query()->whereKey($a['project_source_id'])->firstOrFail()->workspace_id,
            'status' => SnapshotStatus::Pending,
            'manifest_version' => Manifest::VERSION,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn () => [
            'status' => SnapshotStatus::Ready,
            'sha256' => hash('sha256', fake()->uuid()),
            'file_count' => 3,
            'total_bytes' => 300,
            'storage_key' => 'factory/'.fake()->uuid(),
            'ready_at' => now(),
        ]);
    }
}
