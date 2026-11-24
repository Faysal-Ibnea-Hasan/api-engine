<?php

namespace App\Models;

use App\Domain\Sources\RunStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\EndpointAnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $project_id
 * @property string $source_snapshot_id
 * @property string|null $api_endpoint_id
 * @property string $route_identity
 * @property string $application_root
 * @property string $analyzer_version
 * @property string $schema_version
 * @property string $analysis_key
 * @property RunStatus $status
 * @property int $attempts
 * @property string|null $failure_code
 * @property array<string, mixed>|null $result
 * @property int $field_count
 * @property int $response_count
 * @property int $unresolved_count
 * @property string|null $auth_status copy of result.auth.status
 * @property Carbon|null $claimed_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read SourceSnapshot $snapshot
 * @property-read ApiEndpoint|null $endpoint
 */
#[Hidden(['analysis_key'])]
class EndpointAnalysis extends Model
{
    /** @use HasFactory<EndpointAnalysisFactory> */
    use BelongsToWorkspace, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'result' => 'array',
            'claimed_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [RunStatus::Completed, RunStatus::Partial], true);
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

    /** @return BelongsTo<ApiEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(ApiEndpoint::class, 'api_endpoint_id');
    }

    /** @return HasMany<AnalysisEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(AnalysisEvidence::class);
    }

    /** @return HasMany<AnalysisFinding, $this> */
    public function findings(): HasMany
    {
        return $this->hasMany(AnalysisFinding::class);
    }
}
