<?php

namespace App\Domain\Ai;

use App\Domain\Shared\DomainError;

/**
 * Stable AI failures. Only the code is stored (ai_runs.failure_code); the message is fixed per
 * code, so provider responses and keys can never reach users, rows or audit events.
 */
final class AiError extends DomainError
{
    /** code => [user-safe message, HTTP status] */
    public const CODES = [
        'ai.invalid_credentials' => ['The AI provider rejected the API key. A platform admin must replace it in Settings → AI.', 503],
        'ai.quota_exceeded' => ['The AI provider account has no credit or reached its spend cap. A platform admin must fix billing with the provider.', 503],
        'ai.rate_limited' => ['The AI provider is rate-limiting requests. Try again in a minute.', 429],
        'ai.provider_unavailable' => ['The AI provider could not be reached or timed out. Try again shortly.', 503],
        'ai.misconfigured' => ['The selected model is not available for this key. A platform admin must pick another model and test the connection.', 503],
        'ai.request_rejected' => ['The AI provider rejected the request (for example the effort setting or its size). A platform admin can pick another model or a lower effort in Settings → AI.', 422],
        'ai.refused' => ['The AI provider declined to explain this code.', 422],
        'ai.empty_output' => ['The AI provider returned no text.', 502],
        // The gate codes get their real message from AiGate via withMessage(); these are fallbacks.
        'ai.unavailable' => ['AI is not available right now.', 503],
        'ai.limit_reached' => ['The AI usage limit is used up.', 429],
        'ai.key_changed' => ['The AI key was changed while this was waiting. Run it again.', 409],
        'ai.trace_failed' => ['The workflow trace failed, so there was nothing to explain. Run it again.', 422],
        'ai.internal' => ['Something went wrong while writing the explanation. Run it again.', 500],
    ];

    /** Codes that set ai_providers.status, so the gate blocks until an admin fixes and re-tests. */
    private const PROVIDER_STATUSES = [
        'ai.invalid_credentials' => 'invalid_credentials',
        'ai.quota_exceeded' => 'quota_exceeded',
        'ai.misconfigured' => 'misconfigured',
    ];

    /** Provider-reported usage when the failure came after a response, so it is still metered. */
    public int $inputTokens = 0;

    public int $outputTokens = 0;

    /** No response arrived (connect or read timeout): the provider may still bill the input, so it is estimated. */
    public bool $noResponse = false;

    private function __construct(private readonly string $stableCode, ?string $message = null)
    {
        parent::__construct($message ?? self::CODES[$stableCode][0]);
    }

    public static function of(string $code): self
    {
        return new self(array_key_exists($code, self::CODES) ? $code : 'ai.provider_unavailable');
    }

    /** For ai.unavailable / ai.limit_reached, whose text AiGate builds (provider, limit, reset date). */
    public static function withMessage(string $code, string $message): self
    {
        return array_key_exists($code, self::CODES) ? new self($code, $message) : self::of($code);
    }

    /** The shared HTTP-status mapping; adapters check provider-specific quota/credential cases first. */
    public static function forHttpStatus(int $status): self
    {
        return self::of(match (true) {
            $status === 401, $status === 403 => 'ai.invalid_credentials',
            $status === 402 => 'ai.quota_exceeded',
            $status === 404 => 'ai.misconfigured',
            $status === 429 => 'ai.rate_limited',
            $status === 408 => 'ai.provider_unavailable', // a request timeout is transient
            $status >= 400 && $status < 500 => 'ai.request_rejected', // 400, 413, 422...: final, not a stored status
            default => 'ai.provider_unavailable', // 5xx, 529
        });
    }

    public function withTokens(int $input, int $output): self
    {
        $this->inputTokens = $input;
        $this->outputTokens = $output;

        return $this;
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
    public function retryable(): bool
    {
        return in_array($this->stableCode, ['ai.rate_limited', 'ai.provider_unavailable'], true);
    }

    /** The ai_providers.status to store, or null for errors that must not change it. */
    public function providerStatus(): ?string
    {
        return self::PROVIDER_STATUSES[$this->stableCode] ?? null;
    }
}
