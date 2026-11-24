<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\ApiDependencyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One edge of an advanced analysis's workflow graph (P10), written with the result and never edited.
 *
 * @property int $id
 * @property string $endpoint_analysis_id
 * @property string $workspace_id
 * @property string $project_id
 * @property string $edge_hash
 * @property string $kind
 * @property string $from_node
 * @property string $from_type
 * @property string $to_node
 * @property string $to_type
 * @property bool $conditional
 * @property string $resolution
 * @property string|null $reason
 * @property bool|null $async
 * @property string|null $note
 * @property string|null $expression
 * @property list<string>|null $evidence
 * @property Carbon|null $created_at
 * @property-read EndpointAnalysis $analysis
 */
class ApiDependency extends Model
{
    /** @use HasFactory<ApiDependencyFactory> */
    use BelongsToWorkspace, HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'conditional' => 'boolean',
            'async' => 'boolean',
            'evidence' => 'array',
        ];
    }

    /** @return BelongsTo<EndpointAnalysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(EndpointAnalysis::class, 'endpoint_analysis_id');
    }
}
