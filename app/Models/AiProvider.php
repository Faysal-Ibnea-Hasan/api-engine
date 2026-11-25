<?php

namespace App\Models;

use Database\Factories\AiProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One platform-wide AI provider configuration (platform admins only). The API key is encrypted
 * at rest and write-only: it never leaves the server.
 *
 * @property int $id
 * @property string $provider anthropic | openai | gemini
 * @property string|null $api_key
 * @property string|null $key_suffix
 * @property int $key_version
 * @property string|null $model
 * @property list<array{id: string, effort: bool}>|null $available_models
 * @property string $effort
 * @property bool $enabled
 * @property bool $is_default
 * @property int|null $limit_tokens
 * @property string $limit_period
 * @property string $status
 * @property Carbon|null $status_checked_at
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $editor
 */
#[Hidden(['api_key'])]
class AiProvider extends Model
{
    /** @use HasFactory<AiProviderFactory> */
    use HasFactory;

    public const PROVIDERS = ['anthropic' => 'Anthropic', 'openai' => 'OpenAI', 'gemini' => 'Google Gemini'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'available_models' => 'array',
            'enabled' => 'boolean',
            'is_default' => 'boolean',
            'key_version' => 'integer',
            'limit_tokens' => 'integer',
            'status_checked_at' => 'datetime',
        ];
    }

    /** The row for $provider; unsaved when none exists yet. */
    public static function for(string $provider): self
    {
        return self::query()->where('provider', $provider)->first() ?? (new self)->forceFill(['provider' => $provider]);
    }

    public static function default(): ?self
    {
        return self::query()->where('is_default', true)->where('enabled', true)->first();
    }

    public function hasKey(): bool
    {
        return ($this->api_key ?? '') !== '';
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
