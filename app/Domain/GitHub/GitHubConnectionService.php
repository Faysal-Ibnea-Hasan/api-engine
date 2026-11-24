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
 * Links GitHub App installations to a workspace, only after proving them:
 * our one-time state (bound to user + workspace) → GitHub user authorization → that user
 * can see the installation (GET /user/installations) → App-authenticated metadata.
 * Connect starts with user authorization, which always returns to us (an install page does not
 * when the app is already installed): every installation of this app the user can see is linked.
 * With none, the browser goes on to the install page, whose callback carries installation_id.
 * Seeing an installation is not owning it (a collaborator on one repository of an organization
 * sees the whole installation), so a link outside the user's own account is limited to the
 * repositories that user can reach (GET /user/installations/{id}/repositories).
 * The installation_id from the browser is never trusted on its own (GitHub documents that
 * setup callbacks can be spoofed). The GitHub user token is used once and discarded.
 */
final class GitHubConnectionService
{
    private const MAX_INSTALLATION_PAGES = 10;

    public function __construct(private readonly GitHubApiClient $client, private readonly GitHubAppJwt $jwt) {}

    /** @return string the GitHub authorization URL to send the browser to */
    public function start(User $actor, Workspace $workspace): string
    {
        $settings = self::settings();
        AuditLog::record('github.connection_started', $workspace, $actor);

        return rtrim((string) config('github.web_url'), '/').'/login/oauth/authorize?'.http_build_query([
            'client_id' => $settings->client_id,
            'redirect_uri' => self::callbackUrl(),
            'state' => $this->newState($actor, $workspace),
        ]);
    }

    /**
     * Verifies the callback and links the installation(s). Returns the workspace they were linked to,
     * or the install URL to send the browser to when the user can see no installation of this app yet.
     */
    public function complete(User $actor, string $state, ?string $code, ?string $installationId, ?string $error): Workspace|string
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

        // Present only when coming back from the install page; must then be a plain positive integer.
        if ($installationId !== null && preg_match('/^[1-9]\d{0,18}$/', $installationId) !== 1) {
            throw GitHubError::of('github.installation_unverified');
        }

        $userToken = $this->client->exchangeCode($settings, $code, self::callbackUrl());
        $visible = $this->visibleInstallations($userToken, $settings);

        if ($installationId !== null) {
            if (! array_key_exists((int) $installationId, $visible)) {
                throw GitHubError::of('github.installation_unverified');
            }
            $visible = [(int) $installationId => $visible[(int) $installationId]];
        }

        $scopes = $this->scopes($userToken, $visible);
        unset($userToken); // not kept: nothing later needs GitHub-user access

        if ($scopes === []) {
            return rtrim((string) config('github.web_url'), '/').GitHubApiClient::path('/apps/%s/installations/new', $settings->slug)
                .'?'.http_build_query(['state' => $this->newState($actor, $workspace)]);
        }

        foreach ($scopes as $id => $repositoryIds) {
            $this->link($actor, $workspace, $id, $repositoryIds, $settings);
        }

        return $workspace;
    }

    /** @param  list<int>|null  $repositoryIds  null: the whole installation */
    private function link(User $actor, Workspace $workspace, int $installationId, ?array $repositoryIds, GitHubAppSettings $settings): void
    {
        $meta = $this->client->json('GET', GitHubApiClient::path('/app/installations/%s', (string) $installationId), $this->jwt->make($settings), [], [
            404 => 'github.installation_unverified',
            401 => 'github.not_configured',
        ]);

        $installation = DB::transaction(function () use ($meta, $installationId, $repositoryIds, $workspace, $actor) {
            $installation = GitHubInstallation::query()->where('github_installation_id', $installationId)->lockForUpdate()->first() ?? new GitHubInstallation;
            $installation->forceFill([
                'github_installation_id' => $installationId,
                'github_account_id' => (int) ($meta['account']['id'] ?? 0),
                'account_login' => mb_substr((string) ($meta['account']['login'] ?? ''), 0, 255),
                'account_type' => ($meta['account']['type'] ?? '') === 'Organization' ? 'Organization' : 'User',
                'repository_selection' => ($meta['repository_selection'] ?? '') === 'all' ? 'all' : 'selected',
                'permissions' => array_filter((array) ($meta['permissions'] ?? []), 'is_string'),
                'suspended_at' => isset($meta['suspended_at']) ? now() : null,
                'last_verified_at' => now(),
            ])->save();

            // Reconnecting replaces the scope: it always reflects what this user can reach now.
            $workspace->githubInstallations()->syncWithoutDetaching([$installation->id => [
                'connected_by' => $actor->id,
                'repository_ids' => $repositoryIds === null ? null : json_encode($repositoryIds),
            ]]);

            return $installation;
        });

        AuditLog::record('github.installation_connected', $workspace, $actor, $installation, [
            'installation_id' => $installation->github_installation_id,
            'account_login' => $installation->account_login,
            'scope' => $repositoryIds === null ? 'all' : count($repositoryIds).' repositories',
        ]);
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

    /**
     * What each visible installation may expose to the workspace: everything on the user's own
     * account, elsewhere only the repositories this user can reach. Installations where they reach none are dropped.
     *
     * @param  array<int, int>  $visible  installation id => account id
     * @return array<int, list<int>|null>
     */
    private function scopes(string $userToken, array $visible): array
    {
        if ($visible === []) {
            return [];
        }

        $me = $this->client->json('GET', '/user', $userToken, [], [401 => 'github.installation_unverified', 403 => 'github.installation_unverified']);
        $myId = is_int($me['id'] ?? null) ? $me['id'] : null;
        $scopes = [];

        foreach ($visible as $installationId => $accountId) {
            if ($myId !== null && $accountId === $myId) {
                $scopes[$installationId] = null;

                continue;
            }

            $ids = [];
            for ($page = 1; $page <= self::MAX_INSTALLATION_PAGES; $page++) {
                $list = $this->client->json('GET', GitHubApiClient::path('/user/installations/%s/repositories', $installationId), $userToken, ['per_page' => 100, 'page' => $page], [
                    401 => 'github.installation_unverified',
                    403 => 'github.installation_unverified',
                    404 => 'github.installation_unverified',
                ]);
                $repositories = (array) ($list['repositories'] ?? []);

                foreach ($repositories as $repo) {
                    if (is_array($repo) && is_int($repo['id'] ?? null)) {
                        $ids[] = $repo['id'];
                    }
                }

                if (count($repositories) < 100) {
                    break;
                }
            }

            // shortcut: the scope is fixed at connect time; a user who later loses access keeps it until someone reconnects or disconnects
            if ($ids !== []) {
                $scopes[$installationId] = array_values(array_unique($ids));
            }
        }

        return $scopes;
    }

    /** A one-time state bound to this user and workspace; only its hash is stored. */
    private function newState(User $actor, Workspace $workspace): string
    {
        $state = bin2hex(random_bytes(32));

        $attempt = new GitHubConnectionAttempt;
        $attempt->forceFill([
            'state_hash' => hash('sha256', $state),
            'workspace_id' => $workspace->id,
            'user_id' => $actor->id,
            'expires_at' => now()->addMinutes((int) config('github.connect_ttl_minutes')),
        ])->save();

        return $state;
    }

    /** @return array<int, int> installation id => account id, for this app's installations the GitHub user can access */
    private function visibleInstallations(string $userToken, GitHubAppSettings $settings): array
    {
        $ids = [];

        for ($page = 1; $page <= self::MAX_INSTALLATION_PAGES; $page++) {
            $list = $this->client->json('GET', '/user/installations', $userToken, ['per_page' => 100, 'page' => $page], [
                401 => 'github.installation_unverified',
                403 => 'github.installation_unverified',
            ]);
            $installations = (array) ($list['installations'] ?? []);

            foreach ($installations as $item) {
                if (is_array($item) && is_int($item['id'] ?? null) && $item['id'] > 0 && (string) ($item['app_id'] ?? '') === (string) $settings->app_id) {
                    $ids[$item['id']] = is_int($item['account']['id'] ?? null) ? $item['account']['id'] : 0;
                }
            }

            if (count($installations) < 100) {
                break;
            }
        }

        return $ids;
    }
}
