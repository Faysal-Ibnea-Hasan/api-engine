<?php

namespace Database\Factories;

use App\Domain\Docs\StaticDocumentationBuilder;
use App\Models\DocumentationVersion;
use App\Models\EndpointAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentationVersion>
 */
class DocumentationVersionFactory extends Factory
{
    public function definition(): array
    {
        $analysis = fn (array $a) => EndpointAnalysis::query()->whereKey($a['endpoint_analysis_id'])->firstOrFail();

        return [
            'endpoint_analysis_id' => EndpointAnalysis::factory(),
            'workspace_id' => fn (array $a) => $analysis($a)->workspace_id,
            'project_id' => fn (array $a) => $analysis($a)->project_id,
            'source_snapshot_id' => fn (array $a) => $analysis($a)->source_snapshot_id,
            'route_identity' => fn (array $a) => $analysis($a)->route_identity,
            'analyzer_version' => fn (array $a) => $analysis($a)->analyzer_version,
            'builder_version' => StaticDocumentationBuilder::VERSION,
            'contract_checksum' => hash('sha256', fake()->uuid()),
            'document' => [],
        ];
    }
}
