<?php

namespace App\Models;

use Database\Factories\AnalysisEvidenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One source location an analysis fact rests on: an exact file version and line range.
 *
 * @property int $id
 * @property string $endpoint_analysis_id
 * @property string $evidence_key
 * @property string $path
 * @property string|null $sha256
 * @property string|null $symbol
 * @property int $start_line
 * @property int $end_line
 * @property string $kind
 * @property-read EndpointAnalysis $analysis
 */
class AnalysisEvidence extends Model
{
    /** @use HasFactory<AnalysisEvidenceFactory> */
    use HasFactory;

    protected $table = 'analysis_evidence';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['start_line' => 'integer', 'end_line' => 'integer'];
    }

    /** @return BelongsTo<EndpointAnalysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(EndpointAnalysis::class, 'endpoint_analysis_id');
    }
}
