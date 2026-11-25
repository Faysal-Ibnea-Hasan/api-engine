<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One provider call in the usage ledger. Written once, never edited; the workspace, project,
 * analysis and user ids are plain columns so the rows (and the usage counts) outlive their project.
 *
 * @property int $id
 * @property int $ai_provider_id
 * @property string $provider
 * @property string $model
 * @property string $effort
 * @property string|null $workspace_id
 * @property string|null $project_id
 * @property string|null $endpoint_analysis_id
 * @property int|null $user_id
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string $status succeeded | failed
 * @property string|null $failure_code
 * @property int $duration_ms
 * @property string $prompt_version
 * @property Carbon|null $created_at
 * @property-read AiProvider $aiProvider
 */
class AiRun extends Model
{
    public const UPDATED_AT = null;

    /** @return BelongsTo<AiProvider, $this> */
    public function aiProvider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class);
    }
}
