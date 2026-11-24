<?php

namespace App\Domain\Workspaces;

use App\Domain\Shared\DomainError;

final class InvitationError extends DomainError
{
    public function errorCode(): string
    {
        return 'invitation.invalid';
    }

    public function status(): int
    {
        return 404;
    }

    /** One message for unknown, expired, used and revoked: the link reveals nothing. */
    public static function invalid(): self
    {
        return new self('This invitation link is invalid or has expired. Ask for a new one.');
    }
}
