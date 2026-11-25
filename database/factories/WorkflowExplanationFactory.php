<?php

namespace Database\Factories;

use App\Models\EndpointAnalysis;
use App\Models\WorkflowExplanation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowExplanation>
 */
class WorkflowExplanationFactory extends Factory
{
    public function definition(): array
    {
        $analysis = fn (array $a) => EndpointAnalysis::query()->whereKey($a['endpoint_analysis_id'])->firstOrFail();

        return [
            'endpoint_analysis_id' => EndpointAnalysis::factory(),
            'workspace_id' => fn (array $a) => $analysis($a)->workspace_id,
            'project_id' => fn (array $a) => $analysis($a)->project_id,
            'status' => 'queued',
        ];
    }
}
