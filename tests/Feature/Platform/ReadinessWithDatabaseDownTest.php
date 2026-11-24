<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// No RefreshDatabase here: its teardown would need the database this test breaks.
class ReadinessWithDatabaseDownTest extends TestCase
{
    public function test_database_down_makes_readiness_fail_while_liveness_still_responds()
    {
        Storage::fake('snapshots');
        $this->actingAs(User::factory()->platformAdmin()->make());

        $default = config('database.default');
        config(["database.connections.{$default}" => [
            'driver' => 'sqlite',
            'database' => '/nonexistent/readiness-test.sqlite',
        ]]);
        DB::purge($default);

        $this->getJson(route('ready'))
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'unavailable',
                'checks' => ['database' => 'fail', 'queue' => 'ok', 'storage' => 'ok'],
            ]);

        $this->get('/health')->assertOk();
    }
}
