<?php

namespace App\Domain\GitHub;

use App\Models\GitHubAppSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every request to GitHub goes through here: fixed hosts only (config github.api_url, the
 * github.com OAuth endpoint, and codeload for archive redirects), no automatic redirects,
 * bounded retries for 429/5xx, and failures mapped to stable GitHubError codes. Tokens are
 * never logged: failures log only GitHub's status and request id.
 */
final class GitHubApiClient
{
    public const API_VERSION = '2022-11-28';

    /** JSON endpoints are small; refuse anything larger instead of decoding it. */
    private const MAX_JSON_BYTES = 5 * 1024 * 1024;

    /** Retry 429 and transient 5xx twice (200 ms, then 1 s). 401/403/404 are never retried here. */
    private const RETRY_DELAYS_MS = [200, 1000];

    /**
     * A JSON call to the API host.
     *
     * @param  array<string, mixed>  $data  query (GET) or JSON body
     * @param  array<int, string>  $codes  status => GitHubError code, for endpoint-specific meanings
     * @return array<mixed>
     */
    public function json(string $method, string $path, string $bearer, array $data = [], array $codes = []): array
    {
        $response = $this->send($this->pending($bearer, (int) config('github.http_timeout_seconds')), $method, $this->apiUrl($path), $data, $codes);

        if (strlen($response->body()) > self::MAX_JSON_BYTES || ! is_array($decoded = $response->json())) {
            throw GitHubError::of('github.provider_unavailable');
        }

        return $decoded;
    }

    /**
     * A streamed GET for the archive download. Redirects are returned, not followed: the caller
     * checks each Location with assertAllowed(). The bearer is only ever sent to the API host.
     *
     * @param  array<int, string>  $codes
     */
    public function stream(string $url, string $bearer, int $timeoutSeconds, array $codes = []): Response
    {
        $this->assertAllowed($url, archive: true);
        $toApi = strtolower((string) parse_url($url, PHP_URL_HOST)) === $this->apiHost();

        $request = $this->pending($toApi ? $bearer : null, $timeoutSeconds)->withOptions([
            'stream' => true,
            // Abort a stalled transfer: under 1 KB/s for 60 s.
            'curl' => [CURLOPT_LOW_SPEED_LIMIT => 1024, CURLOPT_LOW_SPEED_TIME => 60],
        ]);

        return $this->send($request, 'GET', $url, [], $codes, allowRedirect: true);
    }

    /** Exchanges a one-time OAuth code for a GitHub user token. The caller uses it once and discards it. */
    public function exchangeCode(GitHubAppSettings $settings, string $code, string $redirectUri): string
    {
        try {
            $response = $this->pending(null, (int) config('github.http_timeout_seconds'))
                ->asForm()
                ->post(rtrim((string) config('github.web_url'), '/').'/login/oauth/access_token', [
                    'client_id' => $settings->client_id,
                    'client_secret' => $settings->client_secret,
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                ]);
        } catch (ConnectionException) {
            throw GitHubError::of('github.provider_unavailable');
        }

        $token = $response->successful() ? $response->json('access_token') : null;

        if (! is_string($token) || $token === '') {
            $this->logFailure($response);
            throw GitHubError::of($response->serverError() ? 'github.provider_unavailable' : 'github.oauth_denied');
        }

        return $token;
    }

    /** API path with each dynamic segment URL-encoded, e.g. path('/repos/%s/%s', $owner, $repo). */
    public static function path(string $template, string|int ...$segments): string
    {
        return vsprintf($template, array_map(fn (string|int $s) => rawurlencode((string) $s), $segments));
    }

    /** HTTPS to the API host, or (for archives) to an allowlisted archive host. Everything else is refused. */
    public function assertAllowed(string $url, bool $archive = false): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $allowed = $host === $this->apiHost() || ($archive && in_array($host, (array) config('github.archive_hosts'), true));

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443) || ! $allowed) {
            throw GitHubError::of('github.archive_download_failed');
        }
    }

    private function pending(?string $bearer, int $timeout): PendingRequest
    {
        $request = Http::withHeaders([
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => self::API_VERSION,
            'User-Agent' => 'api-engine',
        ])
            ->connectTimeout(10)
            ->timeout($timeout)
            ->withoutRedirecting()
            // $e is null for a redirect (not a failure): never retried.
            ->retry(self::RETRY_DELAYS_MS, when: fn (?Throwable $e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && ($e->response->status() === 429 || $e->response->serverError())), throw: false);

        return $bearer === null ? $request : $request->withToken($bearer);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $codes
     */
    private function send(PendingRequest $request, string $method, string $url, array $data, array $codes, bool $allowRedirect = false): Response
    {
        try {
            $response = $request->send($method, $url, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
        } catch (ConnectionException) {
            throw GitHubError::of('github.provider_unavailable'); // its message names the URL: never report it
        }

        if ($response->successful() || ($allowRedirect && $response->redirect())) {
            return $response;
        }

        $this->logFailure($response);
        $status = $response->status();
        $rateLimited = $status === 429 || ($status === 403 && ($response->header('x-ratelimit-remaining') === '0' || $response->header('retry-after') !== ''));

        $error = GitHubError::of($codes[$status] ?? match (true) {
            $rateLimited => 'github.rate_limited',
            $status === 401, $status === 403 => 'github.permission_revoked',
            $status === 404 => 'github.repository_not_found',
            default => 'github.provider_unavailable',
        });
        $error->providerStatus = $status;

        throw $error;
    }

    private function logFailure(Response $response): void
    {
        Log::warning('GitHub request failed', ['status' => $response->status(), 'github_request_id' => $response->header('x-github-request-id')]);
    }

    private function apiUrl(string $path): string
    {
        return rtrim((string) config('github.api_url'), '/').'/'.ltrim($path, '/');
    }

    private function apiHost(): string
    {
        return strtolower((string) parse_url((string) config('github.api_url'), PHP_URL_HOST));
    }
}
