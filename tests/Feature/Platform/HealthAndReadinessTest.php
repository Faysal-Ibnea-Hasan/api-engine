<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HealthAndReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_liveness_responds_without_login()
    {
        $this->get('/health')->assertOk();
    }

    public function test_guests_cannot_see_readiness()
    {
        $this->get(route('ready'))->assertRedirect(route('login'));
        $this->getJson(route('ready'))->assertUnauthorized();
    }

    public function test_readiness_is_for_platform_admins_only()
    {
        $this->actingAs(User::factory()->create());

        $this->getJson(route('ready'))->assertForbidden();
    }

    public function test_readiness_reports_each_dependency_without_leaking_config()
    {
        Storage::fake('snapshots');
        $this->actingAs(User::factory()->platformAdmin()->create());

        $this->getJson(route('ready'))
            ->assertOk()
            ->assertExactJson([
                'status' => 'ready',
                'checks' => ['database' => 'ok', 'queue' => 'ok', 'storage' => 'ok'],
            ]);
    }

    public function test_horizon_is_for_platform_admins_only_even_in_local()
    {
        $this->app['env'] = 'local';

        $this->get('/horizon')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden();
        $this->actingAs(User::factory()->platformAdmin()->create())->get('/horizon')->assertOk();
    }
}
