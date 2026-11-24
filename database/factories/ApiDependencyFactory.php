<?php

namespace Database\Factories;

use App\Models\ApiDependency;
use App\Models\EndpointAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiDependency>
 */
class ApiDependencyFactory extends Factory
{
    public function definition(): array
    {
        $analysis = fn (array $a) => EndpointAnalysis::query()->whereKey($a['endpoint_analysis_id'])->firstOrFail();
        $to = 'App\\Services\\'.fake()->unique()->word().'Service::handle';

        return [
            'endpoint_analysis_id' => EndpointAnalysis::factory(),
            'workspace_id' => fn (array $a) => $analysis($a)->workspace_id,
            'project_id' => fn (array $a) => $analysis($a)->project_id,
            'edge_hash' => hash('sha256', "App\\Http\\Controllers\\OrderController::store\n{$to}\ncalls"),
            'kind' => 'calls',
            'from_node' => 'App\\Http\\Controllers\\OrderController::store',
            'from_type' => 'controller',
            'to_node' => $to,
            'to_type' => 'service',
            'conditional' => false,
            'resolution' => 'resolved',
            'reason' => null,
            'async' => null,
            'note' => null,
            'expression' => null,
            'evidence' => [],
        ];
    }
}
