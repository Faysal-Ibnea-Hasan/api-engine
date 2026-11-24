<?php

namespace App\Domain\GitHub;

use App\Domain\Audit\AuditLog;
use App\Domain\Workspaces\Capability;
use App\Models\GitHubAppSettings;
use App\Models\GitHubConnectionAttempt;
use App\Models\GitHubInstallation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Links a GitHub App installation to a workspace, only after proving it:
 * our one-time state (bound to user + workspace) → GitHub user authorization → that user
 * can see the installation (GET /user/installations) → App-authenticated metadata.
 * The installation_id from the browser is never trusted on its own (GitHub documents that
 * setup callbacks can be spoofed). The GitHub user token is used once and discarded.
 */
final class GitHubConnectionService
{
    private const MAX_INSTALLATION_PAGES = 10;

    public function __construct(private readonly GitHubApiClient $client, private readonly GitHubAppJwt $jwt) {}

    /** @return string the GitHub install/authorize URL to send the browser to */
    public function start(User $actor, Workspace $workspace): string
    {
        $settings = self::settings();
        $state = bin2hex(random_bytes(32));

        $attempt = new GitHubConnectionAttempt;
        $attempt->forceFill([
            'state_hash' => hash('sha256', $state),
            'workspace_id' => $workspace->id,
            'user_id' => $actor->id,
            'expires_at' => now()->addMinutes((int) config('github.connect_ttl_minutes')),
        ])->save();

        AuditLog::record('github.connection_started', $workspace, $actor);

        return rtrim((string) config('github.web_url'), '/').GitHubApiClient::path('/apps/%s/installations/new', $settings->slug).'?'.http_build_query(['state' => $state]);
    }

    /** Verifies the callback and links the installation. Returns the workspace it was linked to. */
    public function complete(User $actor, string $state, ?string $code, ?string $installationId, ?string $error): Workspace
    {
        $settings = self::settings();
        $attempt = $this->consume($actor, $state);
        $workspace = Workspace::query()->findOrFail($attempt->workspace_id);

        // Still allowed now, not just when the flow started.
        if (! Gate::forUser($actor)->inspect('perform', [$workspace, Capability::ManageIntegrations])->allowed()) {
            throw GitHubError::of('github.installation_unverified');
        }

        if ($error !== null || $code === null || $code === '') {
            throw GitHubError::of('github.oauth_denied');
        }

        if ($installationId === null || preg_match('/^[1-9]\d{0,18}$/', $installationId) !== 1) {
            throw GitHubError::of('github.installation_unverified');
        }

        $userToken = $this->client->exchangeCode($settings, $code, self::callbackUrl());
        $this->assertUserCanSee($userToken, (int) $installationId, $settings);
        unset($userToken); // not kept: nothing later needs GitHub-user access

        $meta = $this->client->json('GET', GitHubApiClient::path('/app/installations/%s', $installationId), $this->jwt->make($settings), [], [
            404 => 'github.installation_unverified',
            401 => 'github.not_configured',
        ]);

        $installation = DB::transaction(function () use ($meta, $installationId, $workspace, $actor) {
            $installation = GitHubInstallation::query()->where('github_installation_id', (int) $installationId)->lockForUpdate()->first() ?? new GitHubInstallation;
            $installation->forceFill([
                'github_installation_id' => (int) $installationId,
                'github_account_id' => (int) ($meta['account']['id'] ?? 0),
                'account_login' => mb_substr((string) ($meta['account']['login'] ?? ''), 0, 255),
                'account_type' => ($meta['account']['type'] ?? '') === 'Organization' ? 'Organization' : 'User',
                'repository_selection' => ($meta['repository_selection'] ?? '') === 'all' ? 'all' : 'selected',
                'permissions' => array_filter((array) ($meta['permissions'] ?? []), 'is_string'),
                'suspended_at' => isset($meta['suspended_at']) ? now() : null,
                'last_verified_at' => now(),
            ])->save();

            $workspace->githubInstallations()->syncWithoutDetaching([$installation->id => ['connected_by' => $actor->id]]);

            return $installation;
        });

        AuditLog::record('github.installation_connected', $workspace, $actor, $installation, [
            'installation_id' => $installation->github_installation_id,
            'account_login' => $installation->account_login,
        ]);

        return $workspace;
    }

    /** Unlinks the installation from this workspace only. Snapshots and documentation stay. */
    public function disconnect(User $actor, Workspace $workspace, GitHubInstallation $installation): void
    {
        $workspace->githubInstallations()->detach($installation->id);

        AuditLog::record('github.installation_disconnected', $workspace, $actor, $installation, [
            'installation_id' => $installation->github_installation_id,
            'account_login' => $installation->account_login,
        ]);
    }

    public static function callbackUrl(): string
    {
        return route('github.callback');
    }

    /** The complete platform settings, or github.not_configured. */
    public static function settings(): GitHubAppSettings
    {
        $settings = GitHubAppSettings::current();

        if ($settings === null || ! $settings->isComplete()) {
            throw GitHubError::of('github.not_configured');
        }

        return $settings;
    }

    /** One conditional update: the state must be this user's, unused and unexpired. Used once, even if verification fails. */
    private function consume(User $actor, string $state): GitHubConnectionAttempt
    {
        $hash = hash('sha256', $state);

        $consumed = $state !== '' && strlen($state) <= 128 && GitHubConnectionAttempt::query()
            ->where('state_hash', $hash)
            ->where('user_id', $actor->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->update(['consumed_at' => now(), 'updated_at' => now()]) === 1;

        if (! $consumed) {
            throw GitHubError::of('github.connection_expired');
        }

        return GitHubConnectionAttempt::query()->where('state_hash', $hash)->firstOrFail();
    }

    private function assertUserCanSee(string $userToken, int $installationId, GitHubAppSettings $settings): void
    {
        for ($page = 1; $page <= self::MAX_INSTALLATION_PAGES; $page++) {
            $list = $this->client->json('GET', '/user/installations', $userToken, ['per_page' => 100, 'page' => $page], [
                401 => 'github.installation_unverified',
                403 => 'github.installation_unverified',
            ]);
            $installations = (array) ($list['installations'] ?? []);

            foreach ($installations as $item) {
                if (is_array($item) && ($item['id'] ?? null) === $installationId && (string) ($item['app_id'] ?? '') === (string) $settings->app_id) {
                    return;
                }
            }

            if (count($installations) < 100) {
                break;
            }
        }

        throw GitHubError::of('github.installation_unverified');
    }
}
