<?php

namespace App\Domain\Shared;

use RuntimeException;

/**
 * Base for expected business failures. The message is shown to the user,
 * so it must never contain secrets, paths or source snippets.
 */
abstract class DomainError extends RuntimeException
{
    /** Stable machine-readable code, e.g. "snapshot.rejected". */
    abstract public function errorCode(): string;

    public function status(): int
    {
        return 422;
    }
}
