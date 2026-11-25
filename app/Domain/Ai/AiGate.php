<?php

namespace App\Domain\Ai;

use App\Models\AiProvider;
use App\Support\Features;
use Illuminate\Support\Carbon;

/** Decides whether an AI run may start, with a fixed user-facing reason when it may not. */
final class AiGate
{
    private const ADMIN = 'A platform admin';

    /** @return array{available: bool, reason: string|null, code: string|null, provider: AiProvider|null} */
    public static function check(): array
    {
        if (! Features::enabled('ai_settings')) {
            return self::no('flag_off', 'AI is turned off on this platform.');
        }

        $provider = AiProvider::default();
        if (! $provider) {
            $off = AiProvider::query()->where('is_default', true)->first(); // default() skips disabled ones
            if ($off) {
                return self::no('provider_off', (AiProvider::PROVIDERS[$off->provider] ?? $off->provider).' is turned off. '.self::ADMIN.' can turn it on in Settings → AI.');
            }
            if (AiProvider::query()->where('enabled', true)->where('status', 'ready')->exists()) {
                return self::no('no_default', 'No default AI provider is chosen. '.self::ADMIN.' can choose one in Settings → AI.');
            }

            return self::no('no_provider', 'No AI provider is set up. '.self::ADMIN.' can add one in Settings → AI.');
        }

        $label = AiProvider::PROVIDERS[$provider->provider] ?? $provider->provider;

        if (! $provider->hasKey()) {
            return self::no('no_key', "{$label} has no API key. ".self::ADMIN.' can add one in Settings → AI.', $provider);
        }
        if ($provider->status !== 'ready') {
            return self::no('not_ready', "{$label} is not ready (".self::statusWords($provider->status).'). '.self::ADMIN.' must test the connection in Settings → AI.', $provider);
        }
        if ($provider->model === null) {
            return self::no('no_model', "{$label} has no model selected. ".self::ADMIN.' can pick one in Settings → AI.', $provider);
        }

        $meter = AiUsage::meter($provider);
        // shortcut: runs started at the same moment can overshoot the limit slightly, add a reservation if that matters
        if ($meter['limit'] !== null && $meter['used'] >= $meter['limit']) {
            $period = $provider->limit_period === 'day' ? 'daily' : 'monthly';
            $limit = number_format($meter['limit']);
            $resets = Carbon::parse($meter['resetsAt'])->format('j M');

            return self::no('limit_reached', "{$label}'s {$period} AI limit of {$limit} tokens is used up. It resets on {$resets}. ".self::ADMIN.' can raise the limit in Settings → AI.', $provider);
        }

        return ['available' => true, 'reason' => null, 'code' => null, 'provider' => $provider];
    }

    public static function statusWords(string $status): string
    {
        return match ($status) {
            'not_configured' => 'it is not set up yet',
            'unchecked' => 'the connection has not been tested yet',
            'ready' => 'it is ready',
            'invalid_credentials' => 'the key was rejected',
            'quota_exceeded' => 'the account has no credit',
            'rate_limited' => 'the provider is limiting requests right now',
            'provider_unavailable' => 'the provider could not be reached',
            'misconfigured' => 'the selected model was not found',
            default => 'the connection needs to be tested again',
        };
    }

    /** @return array{available: false, reason: string, code: string, provider: AiProvider|null} */
    private static function no(string $code, string $reason, ?AiProvider $provider = null): array
    {
        return ['available' => false, 'reason' => $reason, 'code' => $code, 'provider' => $provider];
    }
}
