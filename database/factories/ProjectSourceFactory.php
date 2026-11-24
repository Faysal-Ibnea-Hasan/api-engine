<?php

namespace Database\Factories;

use App\Domain\Sources\SourceType;
use App\Models\Project;
use App\Models\ProjectSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectSource>
 */
class ProjectSourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'workspace_id' => fn (array $a) => Project::query()->whereKey($a['project_id'])->firstOrFail()->workspace_id,
            'type' => SourceType::Fixture,
        ];
    }
}
