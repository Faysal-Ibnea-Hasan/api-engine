<?php

namespace App\Models;

use Database\Factories\AnalysisFindingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $endpoint_analysis_id
 * @property string $severity
 * @property string $code
 * @property string $message
 */
class AnalysisFinding extends Model
{
    /** @use HasFactory<AnalysisFindingFactory> */
    use HasFactory;

    public const UPDATED_AT = null;
}
