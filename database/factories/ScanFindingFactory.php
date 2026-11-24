<?php

namespace Database\Factories;

use App\Models\RouteScan;
use App\Models\ScanFinding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScanFinding>
 */
class ScanFindingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'route_scan_id' => RouteScan::factory(),
            'severity' => 'warning',
            'code' => 'dynamic_uri',
            'message' => 'A route URI is not a fixed value.',
        ];
    }
}
