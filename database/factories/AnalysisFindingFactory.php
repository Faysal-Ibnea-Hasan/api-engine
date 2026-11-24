<?php

namespace Database\Factories;

use App\Models\AnalysisFinding;
use App\Models\EndpointAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalysisFinding>
 */
class AnalysisFindingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'endpoint_analysis_id' => EndpointAnalysis::factory(),
            'severity' => 'warning',
            'code' => 'unresolved',
            'message' => 'A value is computed at runtime.',
        ];
    }
}
