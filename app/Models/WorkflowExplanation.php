<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\WorkflowExplanationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The AI explanation of one advanced analysis's workflow (one row per analysis).
 *
 * @property int $id
 * @property string $endpoint_analysis_id
 * @property string $workspace_id
 * @property string $project_id
 * @property int|null $requested_by
 * @property string $status tracing | queued | running | completed | failed
 * @property string|null $failure_code
 * @property int|null $ai_provider_id
 * @property int|null $key_version
 * @property string|null $provider
 * @property string|null $model
 * @property string|null $effort
 * @property string|null $prompt_version
 * @property string|null $markdown
 * @property bool $truncated
 * @property list<string>|null $omitted
 * @property int|null $ai_run_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read EndpointAnalysis $analysis
 */
class WorkflowExplanation extends Model
{
    /** @use HasFactory<WorkflowExplanationFactory> */
    use BelongsToWorkspace, HasFactory;

    protected function casts(): array
    {
        return [
            'truncated' => 'boolean',
            'omitted' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** Still being produced: tracing, queued or running. */
    public function isActive(): bool
    {
        return in_array($this->status, ['tracing', 'queued', 'running'], true);
    }

    /** @return BelongsTo<EndpointAnalysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(EndpointAnalysis::class, 'endpoint_analysis_id');
    }
}
