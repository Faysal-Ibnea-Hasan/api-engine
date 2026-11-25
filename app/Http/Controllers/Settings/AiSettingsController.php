<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Ai\AiAdapters;
use App\Domain\Ai\AiGate;
use App\Domain\Ai\AiUsage;
use App\Domain\Ai\GeminiAdapter;
use App\Domain\Audit\AuditLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\UpdateAiProviderRequest;
use App\Models\AiProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → AI (platform admins only; everyone else gets 404). API keys are write-only:
 * the page only learns whether one is set and its last four characters.
 */
class AiSettingsController extends Controller
{
    public function edit(): Response
    {
        abort_unless(Gate::allows('platform-admin'), 404);

        return Inertia::render('settings/ai', [
            'providers' => array_map(fn (string $provider) => $this->props(AiProvider::for($provider)), array_keys(AiProvider::PROVIDERS)),
        ]);
    }

    public function update(UpdateAiProviderRequest $request, string $provider): RedirectResponse
    {
        $p = $request->provider();
        $key = $request->validated('api_key');

        $p->forceFill([
            'model' => $request->validated('model'),
            'effort' => $request->validated('effort') ?? $p->effort ?? 'medium',
            'enabled' => $request->boolean('enabled'),
            'is_default' => $request->boolean('is_default'),
            'limit_period' => $request->validated('limit_period') ?? $p->limit_period ?? 'month',
            'updated_by' => $request->user()->id,
        ]);

        // The no-key card sends only the key: keep a limit set before the key was removed.
        if ($request->exists('limit_tokens')) {
            $p->limit_tokens = $request->validated('limit_tokens');
        }

        if (is_string($key)) {
            $p->forceFill([
                'api_key' => $key,
                'key_suffix' => substr($key, -4),
                'key_version' => ($p->key_version ?? 0) + 1,
                'status' => 'unchecked',
            ]);
        }

        $changed = array_diff(array_keys($p->getDirty()), ['api_key', 'key_suffix', 'key_version', 'status', 'updated_by']);

        DB::transaction(function () use ($p) {
            if ($p->is_default) {
                AiProvider::query()->where('provider', '!=', $p->provider)->update(['is_default' => false]);
            }
            $p->save();
        });

        if (is_string($key)) {
            AuditLog::record('ai.provider_key_set', null, $request->user(), $p, ['provider' => $provider]);
        }
        AuditLog::record('ai.provider_updated', null, $request->user(), $p, [
            'provider' => $provider,
            'changed_fields' => implode(',', $changed), // names only, never values
        ]);

        return $this->toast('success', __(':label settings saved.', ['label' => $this->label($provider)]));
    }

    /** Asks the provider which models this key can use. Costs no tokens. */
    public function test(Request $request, string $provider): RedirectResponse
    {
        abort_unless(Gate::allows('platform-admin'), 404);
        $p = AiProvider::for($provider);
        $label = $this->label($provider);

        if (! $p->hasKey()) {
            return $this->toast('error', __('Add an API key for :label first.', ['label' => $label]));
        }

        $result = AiAdapters::for($provider)->test((string) $p->api_key);
        $ids = array_column($result->models, 'id');
        $p->status_checked_at = Carbon::now();

        if ($result->status === 'ready') {
            $model = in_array($p->model, $ids, true) ? $p->model : null;
            $p->forceFill(['status' => 'ready', 'available_models' => $result->models, 'model' => $model ?? ($ids[0] ?? null)]);
        } elseif (! ($p->status === 'ready' && in_array($result->status, ['rate_limited', 'provider_unavailable'], true))) {
            // A failed test keeps the model list and choice; a passing hiccup doesn't un-ready a working provider.
            $p->status = $result->status;
        }
        $p->save();

        AuditLog::record('ai.provider_tested', null, $request->user(), $p, ['provider' => $provider, 'status' => $result->status]);

        return $result->status === 'ready'
            ? $this->toast('success', __(':label is connected. :count models available.', ['label' => $label, 'count' => count($ids)]))
            : $this->toast('error', __(':label is not connected: :reason.', ['label' => $label, 'reason' => AiGate::statusWords($result->status)]));
    }

    public function destroyKey(Request $request, string $provider): RedirectResponse
    {
        abort_unless(Gate::allows('platform-admin'), 404);
        $p = AiProvider::for($provider);

        if ($p->exists) {
            $p->forceFill([
                'api_key' => null,
                'key_suffix' => null,
                'available_models' => [],
                'model' => null,
                'key_version' => $p->key_version + 1,
                'status' => 'not_configured',
                'enabled' => false,
                'is_default' => false,
                'updated_by' => $request->user()->id,
            ])->save();

            AuditLog::record('ai.provider_key_removed', null, $request->user(), $p, ['provider' => $provider]);
        }

        return $this->toast('success', __(':label key removed.', ['label' => $this->label($provider)]));
    }

    /** @return array<string, mixed> */
    private function props(AiProvider $p): array
    {
        // An unsaved row has no database defaults yet.
        $status = $p->status ?? 'not_configured';

        return [
            'provider' => $p->provider,
            'label' => $this->label($p->provider),
            'configured' => $p->hasKey(),
            'keySuffix' => $p->key_suffix,
            'status' => $status,
            'statusText' => AiGate::statusWords($status),
            'checkedAt' => $p->status_checked_at?->toIso8601String(),
            'model' => $p->model,
            'models' => array_map(fn (array $m) => $m + ['free' => $p->provider === 'gemini' && GeminiAdapter::freeTier($m['id'])], $p->available_models ?? []),
            'effort' => $p->effort ?? 'medium',
            'enabled' => $p->enabled ?? false,
            'isDefault' => $p->is_default ?? false,
            'limitTokens' => $p->limit_tokens,
            'limitPeriod' => $p->limit_period ?? 'month',
            'meter' => AiUsage::meter($p),
        ];
    }

    private function label(string $provider): string
    {
        return AiProvider::PROVIDERS[$provider] ?? $provider;
    }

    private function toast(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return to_route('ai-settings.edit');
    }
}
