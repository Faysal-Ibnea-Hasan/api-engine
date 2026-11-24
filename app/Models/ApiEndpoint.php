<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\ApiEndpointFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable once its scan is written; a rescan of a new version creates new rows.
 *
 * @property string $id
 * @property string $route_scan_id
 * @property string $workspace_id
 * @property string $source_snapshot_id
 * @property string $project_id
 * @property string $route_identity
 * @property list<string> $methods
 * @property string $uri
 * @property string|null $domain
 * @property string|null $name
 * @property string $handler_type
 * @property string|null $controller
 * @property string|null $action
 * @property list<string> $middleware
 * @property list<string>|null $without_middleware null when written by a scan older than P07
 * @property string $auth
 * @property string $route_group
 * @property string|null $module
 * @property string $source_path
 * @property int $source_line
 * @property string|null $expression
 * @property string $resolution
 * @property list<string> $reasons
 * @property-read RouteScan $scan
 */
class ApiEndpoint extends Model
{
    /** @use HasFactory<ApiEndpointFactory> */
    use BelongsToWorkspace, HasFactory, HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['methods' => 'array', 'middleware' => 'array', 'without_middleware' => 'array', 'reasons' => 'array', 'source_line' => 'integer'];
    }

    /** @return BelongsTo<RouteScan, $this> */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(RouteScan::class, 'route_scan_id');
    }
}
