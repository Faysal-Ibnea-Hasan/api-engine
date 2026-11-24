<?php

namespace Database\Factories;

use App\Models\AnalysisEvidence;
use App\Models\EndpointAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalysisEvidence>
 */
class AnalysisEvidenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'endpoint_analysis_id' => EndpointAnalysis::factory(),
            'evidence_key' => 'ev-'.fake()->unique()->numberBetween(1, 100000),
            'path' => 'routes/api.php',
            'sha256' => null,
            'symbol' => null,
            'start_line' => 1,
            'end_line' => 1,
            'kind' => 'route',
        ];
    }
}
