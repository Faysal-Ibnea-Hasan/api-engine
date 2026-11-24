<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One manifest entry. Written once, in the transaction that marks its snapshot ready.
 *
 * @property int $id
 * @property string $source_snapshot_id
 * @property string $path
 * @property string $path_hash
 * @property string|null $sha256
 * @property int $bytes
 * @property string|null $language
 * @property string|null $excluded_reason
 */
class SourceFile extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['bytes' => 'integer'];
    }
}
