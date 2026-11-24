<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\DocumentationVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A static documentation draft. Written once by StaticDocumentation, never edited.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $project_id
 * @property string $source_snapshot_id
 * @property string $endpoint_analysis_id
 * @property string $route_identity
 * @property string $analyzer_version
 * @property string $builder_version
 * @property string $status
 * @property string $contract_checksum
 * @property array<string, mixed> $document
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read SourceSnapshot $snapshot
 * @property-read EndpointAnalysis $analysis
 */
class DocumentationVersion extends Model
{
    /** @use HasFactory<DocumentationVersionFactory> */
    use BelongsToWorkspace, HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['document' => 'array', 'created_by' => 'integer'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<SourceSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(SourceSnapshot::class, 'source_snapshot_id');
    }

    /** @return BelongsTo<EndpointAnalysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(EndpointAnalysis::class, 'endpoint_analysis_id');
    }
}
