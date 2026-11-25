<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\AiGate;
use App\Domain\Ai\AiUsage;
use App\Models\AiProvider;
use App\Models\AiRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AiGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.ai_settings' => true]);
        Carbon::setTestNow('2026-11-24 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function provider(array $attributes = []): AiProvider
    {
        return AiProvider::factory()->create(array_merge(
            ['provider' => 'anthropic', 'is_default' => true, 'status' => 'ready'],
            $attributes,
        ));
    }

    private function ledger(AiProvider $provider, int $input, int $output, string $at): void
    {
        AiRun::query()->forceCreate([
            'ai_provider_id' => $provider->id, 'provider' => $provider->provider, 'model' => 'test-model',
            'effort' => 'medium', 'status' => 'succeeded', 'duration_ms' => 10, 'prompt_version' => 'v1',
            'input_tokens' => $input, 'output_tokens' => $output, 'created_at' => $at,
        ]);
    }

    public function test_meter_counts_only_current_month(): void
    {
        $provider = $this->provider(['limit_tokens' => 1000, 'limit_period' => 'month']);
        $this->ledger($provider, 300, 200, '2026-10-31 23:59:59');
        $this->ledger($provider, 100, 50, '2026-11-01 00:00:00');
        $other = $this->provider(['provider' => 'openai', 'is_default' => false]);
        $this->ledger($other, 999, 999, '2026-11-02 00:00:00');

        $meter = AiUsage::meter($provider);

        $this->assertSame(150, $meter['used']);
        $this->assertSame(1000, $meter['limit']);
        $this->assertSame(850, $meter['left']);
        $this->assertSame('2026-12-01', Carbon::parse($meter['resetsAt'])->toDateString());
        $this->assertSame('2026-12-01T00:00:00+00:00', $meter['resetsAt']);
    }

    public function test_meter_daily_period_resets_at_midnight(): void
    {
        $provider = $this->provider(['limit_tokens' => 1000, 'limit_period' => 'day']);
        $this->ledger($provider, 400, 100, '2026-11-23 23:59:59');
        $this->ledger($provider, 10, 5, '2026-11-24 00:00:00');

        $meter = AiUsage::meter($provider);

        $this->assertSame(15, $meter['used']);
        $this->assertSame('2026-11-25T00:00:00+00:00', $meter['resetsAt']);
        $this->assertSame(0, AiUsage::meter($provider, Carbon::parse('2026-11-25 09:00:00'))['used']);
    }

    public function test_meter_without_a_limit_has_no_left_or_reset(): void
    {
        $provider = $this->provider(['limit_tokens' => null]);
        $this->ledger($provider, 10, 5, '2026-11-24 00:00:00');

        $this->assertSame(['used' => 15, 'limit' => null, 'left' => null, 'resetsAt' => null], AiUsage::meter($provider));
    }

    public function test_left_never_negative_when_limit_lowered_below_used(): void
    {
        $provider = $this->provider(['limit_tokens' => 1000, 'limit_period' => 'month']);
        $this->ledger($provider, 1000, 500, '2026-11-10 00:00:00');

        $meter = AiUsage::meter($provider);

        $this->assertSame(1500, $meter['used']);
        $this->assertSame(0, $meter['left']);
        $this->assertSame('limit_reached', AiGate::check()['code']);
    }

    public function test_gate_reasons_in_order(): void
    {
        config(['features.ai_settings' => false]);
        $this->assertGate('flag_off', 'AI is turned off on this platform.');

        config(['features.ai_settings' => true]);
        $this->assertGate('no_provider', 'No AI provider is set up. A platform admin can add one in Settings → AI.');

        $provider = $this->provider(['api_key' => null, 'status' => 'not_configured']);
        $this->assertGate('no_key', 'Anthropic has no API key. A platform admin can add one in Settings → AI.');

        $provider->forceFill(['api_key' => 'sk-test-1234', 'status' => 'invalid_credentials'])->save();
        $this->assertGate('not_ready', 'Anthropic is not ready (the key was rejected). A platform admin must test the connection in Settings → AI.');

        $provider->forceFill(['status' => 'ready', 'model' => null])->save();
        $this->assertGate('no_model', 'Anthropic has no model selected. A platform admin can pick one in Settings → AI.');

        $provider->forceFill(['model' => 'test-model', 'limit_tokens' => 2000000, 'limit_period' => 'month'])->save();
        $this->ledger($provider, 2000000, 0, '2026-11-05 00:00:00');
        $this->assertGate('limit_reached', "Anthropic's monthly AI limit of 2,000,000 tokens is used up. It resets on 1 Dec. A platform admin can raise the limit in Settings → AI.");

        $provider->forceFill(['limit_period' => 'day'])->save();
        $this->ledger($provider, 5, 0, '2026-11-24 01:00:00');
        $provider->forceFill(['limit_tokens' => 5])->save();
        $this->assertGate('limit_reached', "Anthropic's daily AI limit of 5 tokens is used up. It resets on 25 Nov. A platform admin can raise the limit in Settings → AI.");

        $provider->forceFill(['limit_tokens' => null])->save();
        $result = AiGate::check();
        $this->assertTrue($result['available']);
        $this->assertNull($result['reason']);
        $this->assertNull($result['code']);
        $this->assertTrue($provider->is($result['provider']));
    }

    public function test_gate_names_a_turned_off_default_and_a_missing_default(): void
    {
        $default = $this->provider(['enabled' => false]);
        $this->assertGate('provider_off', 'Anthropic is turned off. A platform admin can turn it on in Settings → AI.');

        $default->forceFill(['is_default' => false, 'enabled' => true])->save();
        $this->assertGate('no_default', 'No default AI provider is chosen. A platform admin can choose one in Settings → AI.');

        $default->forceFill(['status' => 'unchecked'])->save();
        $this->assertGate('no_provider', 'No AI provider is set up. A platform admin can add one in Settings → AI.');
    }

    public function test_gate_reason_texts_contain_no_unknown(): void
    {
        $bad = "/unknown|\?|not stated|can't tell/i";
        $reasons = [];

        config(['features.ai_settings' => false]);
        $reasons[] = AiGate::check()['reason'];
        config(['features.ai_settings' => true]);
        $reasons[] = AiGate::check()['reason'];

        $provider = $this->provider(['enabled' => false]);
        $reasons[] = AiGate::check()['reason'];
        $provider->forceFill(['enabled' => true, 'is_default' => false])->save();
        $reasons[] = AiGate::check()['reason'];
        $provider->forceFill(['api_key' => null, 'is_default' => true])->save();
        $reasons[] = AiGate::check()['reason'];
        $provider->forceFill(['api_key' => 'sk-test-1234'])->save();

        foreach (['not_configured', 'unchecked', 'ready', 'invalid_credentials', 'quota_exceeded', 'rate_limited', 'provider_unavailable', 'misconfigured', 'something_else'] as $status) {
            $reasons[] = AiGate::statusWords($status);
            $provider->forceFill(['status' => $status])->save();
            $reasons[] = AiGate::check()['reason'];
        }

        $provider->forceFill(['status' => 'ready', 'model' => null])->save();
        $reasons[] = AiGate::check()['reason'];

        foreach (array_filter($reasons) as $reason) {
            $this->assertDoesNotMatchRegularExpression($bad, $reason);
        }
        $this->assertNotSame('', AiGate::statusWords('something_else'));
    }

    private function assertGate(string $code, string $reason): void
    {
        $result = AiGate::check();

        $this->assertFalse($result['available']);
        $this->assertSame($code, $result['code']);
        $this->assertSame($reason, $result['reason']);
    }
}
