<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Audit\AuditLog;
use App\Domain\GitHub\GitHubApiClient;
use App\Domain\GitHub\GitHubAppJwt;
use App\Domain\GitHub\GitHubConnectionService;
use App\Domain\GitHub\GitHubError;
use App\Http\Controllers\Controller;
use App\Http\Requests\GitHub\UpdateGitHubAppSettingsRequest;
use App\Models\GitHubAppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform settings for the one GitHub App (platform admins only; everyone else gets 404).
 * The client secret and private key are write-only: the page only learns whether they are set.
 */
class GitHubAppSettingsController extends Controller
{
    public function edit(): Response
    {
        abort_unless(Gate::allows('platform-admin'), 404);
        $settings = GitHubAppSettings::current();

        return Inertia::render('settings/github-app', [
            'settings' => [
                'appId' => $settings->app_id ?? '',
                'slug' => $settings->slug ?? '',
                'clientId' => $settings->client_id ?? '',
                'clientSecretSet' => ($settings->client_secret ?? '') !== '',
                'privateKeySet' => ($settings->private_key ?? '') !== '',
                'configured' => $settings?->isComplete() === true,
                'updatedAt' => $settings?->updated_at?->toIso8601String(),
                'updatedBy' => $settings?->editor?->name,
            ],
            'urls' => [
                'homepage' => url('/'),
                'callback' => GitHubConnectionService::callbackUrl(),
                'newApp' => rtrim((string) config('github.web_url'), '/').'/settings/apps/new',
                'publicPage' => $settings === null || $settings->slug === '' ? null : rtrim((string) config('github.web_url'), '/').'/apps/'.rawurlencode($settings->slug),
            ],
        ]);
    }

    public function update(UpdateGitHubAppSettingsRequest $request): RedirectResponse
    {
        $settings = GitHubAppSettings::current() ?? new GitHubAppSettings;
        $settings->forceFill([
            'app_id' => $request->string('app_id')->value(),
            'slug' => $request->string('slug')->value(),
            'client_id' => $request->string('client_id')->value(),
            'updated_by' => $request->user()->id,
        ]);

        // Blank secret fields keep what is stored.
        if ($request->filled('client_secret')) {
            $settings->client_secret = $request->string('client_secret')->trim()->value();
        }
        if (($key = $request->privateKey()) !== null) {
            $settings->private_key = $key;
        }

        $changed = array_keys($settings->getDirty());
        $settings->save();

        AuditLog::record('github_app.settings_updated', null, $request->user(), $settings, [
            'app_id' => $settings->app_id,
            'changed_fields' => implode(',', array_diff($changed, ['updated_by', 'updated_at', 'created_at'])), // names only, never values
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('GitHub App settings saved.')]);

        return to_route('github-app.edit');
    }

    /** Mints an App JWT from the stored settings and asks GitHub which app it is. */
    public function test(GitHubApiClient $client, GitHubAppJwt $jwt): RedirectResponse
    {
        abort_unless(Gate::allows('platform-admin'), 404);

        try {
            $app = $client->json('GET', '/app', $jwt->make(), [], [401 => 'github.not_configured', 403 => 'github.not_configured', 404 => 'github.not_configured']);
            $message = __('Connected to the GitHub App “:name” (:slug).', ['name' => (string) ($app['name'] ?? ''), 'slug' => (string) ($app['slug'] ?? '')]);
            Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
        } catch (GitHubError $e) {
            $message = $e->errorCode() === 'github.not_configured'
                ? __('GitHub did not accept these settings. Check the App ID and private key, then save and test again.')
                : $e->getMessage();
            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);
        }

        return to_route('github-app.edit');
    }
}
