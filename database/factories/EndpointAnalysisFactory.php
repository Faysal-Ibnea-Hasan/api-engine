<?php

namespace Database\Factories;

use App\Domain\Analysis\EndpointAnalyzer;
use App\Domain\Sources\RunStatus;
use App\Models\ApiEndpoint;
use App\Models\EndpointAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EndpointAnalysis>
 */
class EndpointAnalysisFactory extends Factory
{
    public function definition(): array
    {
        $endpoint = fn (array $a) => ApiEndpoint::query()->whereKey($a['api_endpoint_id'])->firstOrFail();

        return [
            'api_endpoint_id' => ApiEndpoint::factory(),
            'workspace_id' => fn (array $a) => $endpoint($a)->workspace_id,
            'project_id' => fn (array $a) => $endpoint($a)->project_id,
            'source_snapshot_id' => fn (array $a) => $endpoint($a)->source_snapshot_id,
            'route_identity' => fn (array $a) => $endpoint($a)->route_identity,
            'application_root' => '.',
            'analyzer_version' => EndpointAnalyzer::VERSION,
            'schema_version' => EndpointAnalyzer::SCHEMA_VERSION,
            'analysis_key' => hash('sha256', fake()->uuid()),
            'status' => RunStatus::Queued,
        ];
    }
}
