<?php

namespace Database\Factories;

use App\Models\ApiEndpoint;
use App\Models\RouteScan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiEndpoint>
 */
class ApiEndpointFactory extends Factory
{
    public function definition(): array
    {
        $uri = '/api/'.fake()->unique()->slug(2);

        return [
            'route_scan_id' => RouteScan::factory(),
            'workspace_id' => fn (array $a) => RouteScan::query()->whereKey($a['route_scan_id'])->firstOrFail()->workspace_id,
            'project_id' => fn (array $a) => RouteScan::query()->whereKey($a['route_scan_id'])->firstOrFail()->project_id,
            'source_snapshot_id' => fn (array $a) => RouteScan::query()->whereKey($a['route_scan_id'])->firstOrFail()->source_snapshot_id,
            'route_identity' => hash('sha256', 'GET|HEAD'.$uri),
            'methods' => ['GET', 'HEAD'],
            'methods_label' => '|GET|HEAD|',
            'uri' => $uri,
            'uri_hash' => hash('sha256', $uri),
            'handler_type' => 'controller',
            'controller' => 'App\Http\Controllers\ExampleController',
            'action' => 'index',
            'middleware' => ['api'],
            'middleware_label' => '|api|',
            'auth' => 'unknown',
            'route_group' => 'api',
            'source_path' => 'routes/api.php',
            'source_line' => 1,
            'resolution' => 'resolved',
            'reasons' => [],
        ];
    }
}
