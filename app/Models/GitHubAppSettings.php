<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The one platform-wide GitHub App (single row, platform admins only). The client secret and
 * private key are encrypted at rest and write-only: they never leave the server.
 *
 * @property int $id
 * @property string $app_id
 * @property string $slug
 * @property string $client_id
 * @property string|null $client_secret
 * @property string|null $private_key
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $editor
 */
#[Hidden(['client_secret', 'private_key'])]
class GitHubAppSettings extends Model
{
    protected $table = 'github_app_settings';

    protected function casts(): array
    {
        return ['client_secret' => 'encrypted', 'private_key' => 'encrypted'];
    }

    public static function current(): ?self
    {
        return self::query()->oldest('id')->first();
    }

    /** Every value needed to mint App JWTs and run the OAuth flow is present. */
    public function isComplete(): bool
    {
        return $this->app_id !== '' && $this->slug !== '' && $this->client_id !== ''
            && ($this->client_secret ?? '') !== '' && ($this->private_key ?? '') !== '';
    }

    public static function configured(): bool
    {
        return self::current()?->isComplete() === true;
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
