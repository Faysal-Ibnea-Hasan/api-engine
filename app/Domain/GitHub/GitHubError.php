<?php

namespace App\Domain\GitHub;

use App\Domain\Shared\DomainError;

/**
 * Stable GitHub failures. Only the code is stored (runs, imports); the message is fixed per
 * code, so provider responses, URLs and tokens can never reach users, rows or audit events.
 */
final class GitHubError extends DomainError
{
    /** code => [user-safe message, HTTP status] */
    public const CODES = [
        'github.not_configured' => ['GitHub isn\'t set up yet. A platform admin must add the GitHub App in Settings → GitHub App.', 503],
        'github.connection_expired' => ['That GitHub connection link expired or was already used. Start connecting again.', 422],
        'github.oauth_denied' => ['GitHub authorization was cancelled or denied. Nothing was connected.', 422],
        'github.installation_unverified' => ['GitHub could not confirm that you can access that installation. Nothing was connected.', 403],
        'github.installation_suspended' => ['This GitHub installation is suspended. Unsuspend it on GitHub, then try again.', 409],
        'github.repository_not_allowed' => ['That repository is not available to this workspace\'s GitHub installation. Grant access on GitHub (the installation\'s Configure page).', 404],
        'github.repository_not_found' => ['The repository was not found, or its access was removed.', 404],
        'github.ref_not_found' => ['That branch, tag or commit was not found in the repository.', 422],
        'github.rate_limited' => ['GitHub is rate-limiting requests. Wait a few minutes and try again.', 429],
        'github.provider_unavailable' => ['GitHub could not be reached. Try again shortly; ZIP upload still works.', 503],
        'github.archive_too_large' => ['The repository archive is larger than allowed.', 413],
        'github.archive_download_failed' => ['The repository archive could not be downloaded. Try again.', 502],
        'github.permission_revoked' => ['GitHub access for this repository was revoked or disconnected. Reconnect GitHub, then try again.', 403],
    ];

    /** GitHub's HTTP status, for internal decisions only (a 401 refreshes the token once). Never shown or stored. */
    public ?int $providerStatus = null;

    private function __construct(private readonly string $stableCode)
    {
        parent::__construct(self::messageFor($stableCode));
    }

    public static function of(string $code): self
    {
        return new self(array_key_exists($code, self::CODES) ? $code : 'github.provider_unavailable');
    }

    public static function messageFor(string $code): string
    {
        return self::CODES[$code][0] ?? self::CODES['github.provider_unavailable'][0];
    }

    public function errorCode(): string
    {
        return $this->stableCode;
    }

    public function status(): int
    {
        return self::CODES[$this->stableCode][1];
    }

    /** Retrying later may succeed (the job may retry); every other code is final. */
    public function isTransient(): bool
    {
        return in_array($this->stableCode, ['github.rate_limited', 'github.provider_unavailable', 'github.archive_download_failed'], true);
    }
}
