<?php

namespace App\Domain\Docs;

use App\Domain\Shared\DomainError;

final class DocsError extends DomainError
{
    private function __construct(string $message, private readonly string $stableCode, private readonly int $httpStatus)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->stableCode;
    }

    public function status(): int
    {
        return $this->httpStatus;
    }

    public static function analysisNotReady(): self
    {
        return new self('Documentation is built from a finished analysis. Analyze this endpoint first, then try again.', 'docs.analysis_not_ready', 409);
    }

    public static function invalidResult(): self
    {
        return new self('This analysis result could not be read. Analyze the endpoint again, then generate documentation.', 'docs.invalid_result', 422);
    }

    public static function endpointMissing(): self
    {
        return new self('The endpoint this analysis belongs to is no longer available. Analyze it again from the API Explorer.', 'docs.endpoint_missing', 409);
    }
}
