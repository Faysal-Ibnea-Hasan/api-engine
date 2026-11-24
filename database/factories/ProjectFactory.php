<?php

namespace Database\Factories;

use App\Domain\Projects\ProjectStatus;
use App\Domain\Projects\ProjectVisibility;
use App\Domain\Projects\WizardStep;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company().' API';

        return [
            'workspace_id' => Workspace::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'visibility' => ProjectVisibility::Workspace,
            'status' => ProjectStatus::Draft,
            'wizard_step' => WizardStep::Source,
        ];
    }

    public function restricted(): static
    {
        return $this->state(fn () => ['visibility' => ProjectVisibility::Restricted]);
    }

    public function archived(): static
    {
        return $this->state(fn () => [
            'status' => ProjectStatus::Archived,
            'status_before_archive' => ProjectStatus::Draft,
            'archived_at' => now(),
        ]);
    }
}
