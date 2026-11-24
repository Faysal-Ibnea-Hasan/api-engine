<?php

namespace App\Domain\Sources;

use App\Domain\Shared\DomainError;

final class UploadError extends DomainError
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

    /** The client should ask for the session's received_bytes and resume from there. */
    public static function offsetMismatch(): self
    {
        return new self('That piece does not continue where the upload stopped. Resuming from the right place.', 'upload.offset_mismatch', 409);
    }

    public static function chunkTooLarge(): self
    {
        return new self('That piece is larger than allowed.', 'upload.chunk_too_large', 413);
    }

    public static function beyondDeclaredSize(): self
    {
        return new self('More data was sent than the file size given when the upload started.', 'upload.too_much_data', 422);
    }

    public static function emptyChunk(): self
    {
        return new self('An upload piece was empty.', 'upload.empty_chunk', 422);
    }

    public static function incomplete(): self
    {
        return new self('The upload is not finished yet.', 'upload.incomplete', 409);
    }

    public static function notActive(): self
    {
        return new self('This upload has finished or expired. Start a new upload.', 'upload.not_active', 409);
    }
}
