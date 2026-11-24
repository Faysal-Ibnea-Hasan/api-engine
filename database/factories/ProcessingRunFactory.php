<?php

namespace Database\Factories;

use App\Domain\Sources\RunStatus;
use App\Models\ProcessingRun;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcessingRun>
 */
class ProcessingRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'workspace_id' => fn (array $a) => Project::query()->whereKey($a['project_id'])->firstOrFail()->workspace_id,
            'kind' => ProcessingRun::KIND_SNAPSHOT_IMPORT,
            'status' => RunStatus::Completed,
            'idempotency_key' => hash('sha256', fake()->uuid()),
        ];
    }
}
