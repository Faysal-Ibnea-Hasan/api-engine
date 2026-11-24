<?php

namespace App\Domain\GitHub;

use App\Models\GitHubInstallation;
use Closure;

/**
 * Short-lived, read-only installation tokens, minted on demand with the App JWT.
 *
 * Deviation from the guide's "cache encrypted": the cache store is the database in production,
 * which would persist tokens. They are memoized in this (request- or job-scoped) instance only,
 * until a minute before GitHub's expiry, and never stored, logged or queued.
 */
final class GitHubInstallationTokens
{
    /** @var array<string, array{token: string, until: int}> */
    private array $memo = [];

    public function __construct(private readonly GitHubApiClient $client, private readonly GitHubAppJwt $jwt) {}

    /** A token limited to contents:read + metadata:read and, when given, to one repository (GitHub refuses if it isn't in the installation). */
    public function token(GitHubInstallation $installation, ?int $repositoryId = null): string
    {
        $key = $installation->github_installation_id.':'.($repositoryId ?? '*');

        if (isset($this->memo[$key]) && $this->memo[$key]['until'] > now()->getTimestamp()) {
            return $this->memo[$key]['token'];
        }

        $body = ['permissions' => ['contents' => 'read', 'metadata' => 'read']];
        if ($repositoryId !== null) {
            $body['repository_ids'] = [$repositoryId];
        }

        $response = $this->client->json('POST', GitHubApiClient::path('/app/installations/%s/access_tokens', $installation->github_installation_id), $this->jwt->make(), $body, [
            401 => 'github.not_configured',          // the App's own credentials were refused
            403 => 'github.installation_suspended',
            404 => 'github.permission_revoked',       // the installation was removed on GitHub
            422 => 'github.repository_not_allowed',   // that repository isn't in the installation
        ]);

        $token = $response['token'] ?? null;
        $expires = strtotime((string) ($response['expires_at'] ?? '')) ?: now()->addMinutes(10)->getTimestamp();

        if (! is_string($token) || $token === '') {
            throw GitHubError::of('github.provider_unavailable');
        }

        $this->memo[$key] = ['token' => $token, 'until' => $expires - 60];

        return $token;
    }

    /**
     * Runs $call with a token; on a 401 the token is minted again once (it may have been revoked early).
     *
     * @template T
     *
     * @param  Closure(string): T  $call
     * @return T
     */
    public function with(GitHubInstallation $installation, ?int $repositoryId, Closure $call): mixed
    {
        try {
            return $call($this->token($installation, $repositoryId));
        } catch (GitHubError $e) {
            if ($e->providerStatus !== 401) {
                throw $e;
            }

            unset($this->memo[$installation->github_installation_id.':'.($repositoryId ?? '*')]);

            return $call($this->token($installation, $repositoryId));
        }
    }
}
