<?php

namespace App\Domain\GitHub;

use App\Models\GitHubAppSettings;

/**
 * A short-lived RS256 JWT that authenticates as the GitHub App (iss = App ID), signed with the
 * private key from the platform settings. Never logged; the key never leaves this method.
 *
 * Deviation from the guide's "prefer a JWT library": no JWT package is installed, and RS256 is
 * one openssl_sign() call. Covered by GitHubAppJwtTest (verified with openssl_verify).
 */
final class GitHubAppJwt
{
    /** GitHub accepts at most 10 minutes; stay under it and allow 60s of clock drift. */
    public const LIFETIME_SECONDS = 540;

    public function make(?GitHubAppSettings $settings = null): string
    {
        $settings ??= GitHubAppSettings::current();

        if ($settings === null || ! $settings->isComplete()) {
            throw GitHubError::of('github.not_configured');
        }

        $key = openssl_pkey_get_private((string) $settings->private_key);
        if ($key === false) {
            while (openssl_error_string() !== false); // drop OpenSSL's error queue: it can describe the key
            throw GitHubError::of('github.not_configured');
        }

        $now = now()->getTimestamp();
        $segments = [
            self::base64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::base64url((string) json_encode(['iat' => $now - 60, 'exp' => $now + self::LIFETIME_SECONDS, 'iss' => $settings->app_id])),
        ];

        if (! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw GitHubError::of('github.not_configured');
        }

        return implode('.', $segments).'.'.self::base64url($signature);
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
