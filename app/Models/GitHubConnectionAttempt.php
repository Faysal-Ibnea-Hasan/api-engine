<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Short-lived OAuth state binding a GitHub callback to the user and workspace that started it.
 * Only the state's SHA-256 is stored; it is consumed exactly once.
 *
 * @property string $id
 * @property string $state_hash
 * @property string $workspace_id
 * @property int $user_id
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 */
class GitHubConnectionAttempt extends Model
{
    use BelongsToWorkspace, HasUuids;

    protected $table = 'github_connection_attempts';

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
