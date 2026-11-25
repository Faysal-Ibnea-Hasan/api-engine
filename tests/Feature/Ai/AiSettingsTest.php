<?php

namespace Tests\Feature\Ai;

use App\Domain\Workspaces\WorkspaceRole;
use App\Models\AiProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

class AiSettingsTest extends TestCase
{
    use RefreshDatabase, WorksWithWorkspaces;

    private const KEY = 'sk-ant-SECRETVALUE1234';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        config(['features.ai_settings' => true, 'inertia.ssr.enabled' => false]);
        $this->withoutVite();
        Http::preventStrayRequests();

        $this->admin = $this->memberOf(WorkspaceRole::Owner);
        $this->admin->forceFill(['is_platform_admin' => true])->save();
    }

    private function save(string $provider, array $fields, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->put("/settings/ai/{$provider}", $fields + [
            'effort' => 'medium', 'limit_period' => 'month', 'enabled' => '0', 'is_default' => '0',
        ]);
    }

    private function fakeAnthropicModels(int $thenStatus = 200): void
    {
        $models = ['data' => [
            ['id' => 'claude-opus-5-5', 'capabilities' => ['effort' => ['supported' => true]]],
            ['id' => 'claude-haiku-5-5', 'capabilities' => ['effort' => ['supported' => false]]],
        ]];
        $then = $thenStatus === 200 ? $models : ['error' => ['type' => 'authentication_error']];

        Http::fake(['api.anthropic.com/*' => Http::sequence()->push($models)->push($then, $thenStatus)->push($then, $thenStatus)]);
    }

    public function test_non_admin_gets_404_on_every_route(): void
    {
        $owner = $this->memberOf(WorkspaceRole::Owner); // a workspace owner is not a platform admin

        $this->actingAs($owner)->get('/settings/ai')->assertNotFound();
        $this->save('anthropic', ['api_key' => self::KEY], $owner)->assertNotFound();
        $this->actingAs($owner)->post('/settings/ai/anthropic/test')->assertNotFound();
        $this->actingAs($owner)->delete('/settings/ai/anthropic/key')->assertNotFound();
        $this->assertSame(0, AiProvider::query()->count());
    }

    public function test_flag_off_gets_404(): void
    {
        config(['features.ai_settings' => false]);

        $this->actingAs($this->admin)->get('/settings/ai')->assertNotFound();
        $this->save('anthropic', ['api_key' => self::KEY])->assertNotFound();
        $this->actingAs($this->admin)->post('/settings/ai/anthropic/test')->assertNotFound();
        $this->actingAs($this->admin)->delete('/settings/ai/anthropic/key')->assertNotFound();
    }

    public function test_unknown_provider_is_404(): void
    {
        $this->actingAs($this->admin)->post('/settings/ai/mistral/test')->assertNotFound();
    }

    public function test_edit_lists_all_three_providers_with_defaults(): void
    {
        $this->actingAs($this->admin)->get('/settings/ai')->assertOk()->assertInertia(fn ($page) => $page
            ->component('settings/ai')
            ->has('providers', 3)
            ->where('providers.1.provider', 'openai')
            ->where('providers.1.label', 'OpenAI')
            ->where('providers.1.configured', false)
            ->where('providers.1.status', 'not_configured')
            ->where('providers.1.statusText', 'it is not set up yet')
            ->where('providers.1.effort', 'medium')
            ->where('providers.1.limitPeriod', 'month')
            ->where('providers.1.models', [])
            ->where('providers.1.meter', ['used' => 0, 'limit' => null, 'left' => null, 'resetsAt' => null]));
    }

    public function test_save_key_sets_suffix_unchecked_and_bumps_version(): void
    {
        $this->save('anthropic', ['api_key' => self::KEY, 'limit_tokens' => '2000000'])
            ->assertRedirect('/settings/ai')->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Anthropic settings saved.']);

        $p = AiProvider::for('anthropic');
        $this->assertSame(self::KEY, $p->api_key);
        $this->assertSame('1234', $p->key_suffix);
        $this->assertSame(1, $p->key_version);
        $this->assertSame('unchecked', $p->status);
        $this->assertSame(2000000, $p->limit_tokens);
        $this->assertSame($this->admin->id, $p->updated_by);
        $this->assertNotSame(self::KEY, DB::table('ai_providers')->value('api_key')); // encrypted at rest

        // Saving again without a key keeps the stored one.
        $this->save('anthropic', ['limit_tokens' => ''])->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertSame(self::KEY, $p->api_key);
        $this->assertSame(1, $p->key_version);
        $this->assertNull($p->limit_tokens);

        $this->save('anthropic', ['api_key' => 'sk-ant-ANOTHERVALUE9876'])->assertSessionHasNoErrors();
        $this->assertSame(2, $p->refresh()->key_version);
        $this->assertSame('9876', $p->key_suffix);

        $events = DB::table('audit_events')->pluck('event_type')->all();
        $this->assertSame(['ai.provider_key_set', 'ai.provider_updated', 'ai.provider_updated', 'ai.provider_key_set', 'ai.provider_updated'], $events);
    }

    public function test_key_rules_reject_whitespace_and_short_keys_without_flashing_the_key(): void
    {
        $this->save('anthropic', ['api_key' => 'sk-ant SECRETVALUE1234 x'])->assertSessionHasErrors('api_key');
        $this->assertArrayNotHasKey('api_key', session('_old_input', []));
        $this->save('anthropic', ['api_key' => 'short'])->assertSessionHasErrors('api_key');
        $this->save('anthropic', ['limit_tokens' => '10'])->assertSessionHasErrors('limit_tokens');
        $this->assertSame(0, AiProvider::query()->count());
    }

    public function test_key_never_in_props_or_audit(): void
    {
        $this->fakeAnthropicModels();
        $this->save('anthropic', ['api_key' => self::KEY])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/settings/ai/anthropic/test');
        $this->actingAs($this->admin)->delete('/settings/ai/anthropic/key');

        $this->save('anthropic', ['api_key' => self::KEY])->assertSessionHasNoErrors();
        $body = $this->actingAs($this->admin)->get('/settings/ai')->assertOk()
            ->assertInertia(fn ($page) => $page->where('providers.0.keySuffix', '1234')->where('providers.0.configured', true))
            ->getContent();

        $this->assertStringNotContainsString('SECRETVALUE', $body);
        $metadata = DB::table('audit_events')->pluck('metadata');
        $this->assertCount(6, $metadata); // key_set, updated, tested, key_removed, key_set, updated
        foreach ($metadata as $json) {
            $this->assertStringNotContainsString('SECRETVALUE', (string) $json);
        }
    }

    public function test_test_connection_stores_models_and_ready_status(): void
    {
        $this->fakeAnthropicModels();
        $this->save('anthropic', ['api_key' => self::KEY]);

        $this->actingAs($this->admin)->post('/settings/ai/anthropic/test')
            ->assertRedirect('/settings/ai')
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Anthropic is connected. 2 models available.']);

        $p = AiProvider::for('anthropic');
        $this->assertSame('ready', $p->status);
        $this->assertNotNull($p->status_checked_at);
        $this->assertSame([['id' => 'claude-opus-5-5', 'effort' => true], ['id' => 'claude-haiku-5-5', 'effort' => false]], $p->available_models);
        $this->assertSame('claude-opus-5-5', $p->model); // first model picked when none was chosen
        $this->assertEquals(['provider' => 'anthropic', 'status' => 'ready'], json_decode((string) DB::table('audit_events')->where('event_type', 'ai.provider_tested')->value('metadata'), true));

        // A chosen model is kept on the next test.
        $this->save('anthropic', ['model' => 'claude-haiku-5-5'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/settings/ai/anthropic/test');
        $this->assertSame('claude-haiku-5-5', $p->refresh()->model);
    }

    public function test_failed_test_shows_the_status_in_words_and_keeps_the_models(): void
    {
        $this->fakeAnthropicModels(401);
        $this->save('anthropic', ['api_key' => self::KEY]);
        $this->actingAs($this->admin)->post('/settings/ai/anthropic/test');
        $this->save('anthropic', ['model' => 'claude-haiku-5-5'])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post('/settings/ai/anthropic/test')
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Anthropic is not connected: the key was rejected.']);

        $p = AiProvider::for('anthropic');
        $this->assertSame('invalid_credentials', $p->status); // a lasting failure is stored
        $this->assertSame('claude-haiku-5-5', $p->model);
        $this->assertCount(2, $p->available_models ?? []);
        $this->assertEquals(['provider' => 'anthropic', 'status' => 'invalid_credentials'], json_decode((string) DB::table('audit_events')->where('event_type', 'ai.provider_tested')->latest('id')->value('metadata'), true));
    }

    public function test_transient_failure_keeps_a_ready_provider_ready(): void
    {
        $this->fakeAnthropicModels(503);
        $this->save('anthropic', ['api_key' => self::KEY]);
        $this->actingAs($this->admin)->post('/settings/ai/anthropic/test');
        $this->save('anthropic', ['model' => 'claude-haiku-5-5'])->assertSessionHasNoErrors();
        $p = AiProvider::for('anthropic');
        $p->forceFill(['status_checked_at' => now()->subHour()])->save();
        $before = $p->available_models;

        $this->actingAs($this->admin)->post('/settings/ai/anthropic/test')
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Anthropic is not connected: the provider could not be reached.']);

        $p->refresh();
        $this->assertSame('ready', $p->status);
        $this->assertSame('claude-haiku-5-5', $p->model);
        $this->assertSame($before, $p->available_models);
        $this->assertTrue($p->status_checked_at->isAfter(now()->subMinute()));
        $this->assertSame('provider_unavailable', json_decode((string) DB::table('audit_events')->where('event_type', 'ai.provider_tested')->latest('id')->value('metadata'), true)['status']);
    }

    public function test_transient_failure_is_stored_when_not_ready(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([], 503)]);
        $this->save('anthropic', ['api_key' => self::KEY]);

        $this->actingAs($this->admin)->post('/settings/ai/anthropic/test');

        $this->assertSame('provider_unavailable', AiProvider::for('anthropic')->status);
    }

    public function test_test_without_a_key_does_not_call_the_provider(): void
    {
        $this->actingAs($this->admin)->post('/settings/ai/gemini/test')
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Add an API key for Google Gemini first.']);
        Http::assertNothingSent();
    }

    public function test_model_must_be_in_available_models(): void
    {
        $this->save('anthropic', ['api_key' => self::KEY, 'model' => 'claude-opus-5-5'])->assertSessionHasErrors('model');

        AiProvider::for('anthropic')->forceFill(['available_models' => [['id' => 'claude-opus-5-5', 'effort' => true]]])->save();
        $this->save('anthropic', ['model' => 'gpt-9'])->assertSessionHasErrors('model');
        $this->save('anthropic', ['model' => 'claude-opus-5-5', 'effort' => 'high'])->assertSessionHasNoErrors();
        $this->assertSame('claude-opus-5-5', AiProvider::for('anthropic')->model);
        $this->assertSame('high', AiProvider::for('anthropic')->effort);
        $this->save('anthropic', ['effort' => 'extreme'])->assertSessionHasErrors('effort');
    }

    public function test_only_one_default(): void
    {
        $this->save('anthropic', ['api_key' => self::KEY, 'is_default' => '1', 'enabled' => '1'])->assertSessionHasNoErrors();
        $this->save('openai', ['api_key' => 'sk-proj-OTHERVALUE123456', 'is_default' => '1', 'enabled' => '1'])->assertSessionHasNoErrors();

        $this->assertSame(['openai'], AiProvider::query()->where('is_default', true)->pluck('provider')->all());
        $this->assertTrue(AiProvider::for('anthropic')->enabled);

        // An unchecked box turns the flag off.
        $this->save('openai', [])->assertSessionHasNoErrors();
        $this->assertFalse(AiProvider::for('openai')->is_default);
        $this->assertFalse(AiProvider::for('openai')->enabled);
    }

    public function test_remove_key_resets_provider(): void
    {
        $this->fakeAnthropicModels();
        $this->save('anthropic', ['api_key' => self::KEY, 'is_default' => '1', 'enabled' => '1']);
        $this->actingAs($this->admin)->post('/settings/ai/anthropic/test');

        $this->actingAs($this->admin)->delete('/settings/ai/anthropic/key')->assertRedirect('/settings/ai')
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Anthropic key removed.']);

        $p = AiProvider::for('anthropic');
        $this->assertNull($p->api_key);
        $this->assertNull($p->key_suffix);
        $this->assertSame([], $p->available_models);
        $this->assertNull($p->model);
        $this->assertSame(2, $p->key_version);
        $this->assertSame('not_configured', $p->status);
        $this->assertFalse($p->enabled);
        $this->assertFalse($p->is_default);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'ai.provider_key_removed')->count());

        // The no-key card sends only the key: the limit set earlier survives.
        $p->forceFill(['limit_tokens' => 5000])->save();
        $this->actingAs($this->admin)->delete('/settings/ai/anthropic/key');
        $this->actingAs($this->admin)->put('/settings/ai/anthropic', ['api_key' => self::KEY])->assertSessionHasNoErrors();
        $this->assertSame(5000, $p->refresh()->limit_tokens);
    }

    public function test_default_requires_key(): void
    {
        $this->save('gemini', ['is_default' => '1'])->assertSessionHasErrors('is_default');
        $this->save('gemini', ['enabled' => '1'])->assertSessionHasErrors('enabled');
        $this->assertSame(0, AiProvider::query()->count());

        $this->save('gemini', ['api_key' => 'AIzaSyFAKEVALUE12345678', 'is_default' => '1', 'enabled' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue(AiProvider::for('gemini')->is_default);
    }
}
