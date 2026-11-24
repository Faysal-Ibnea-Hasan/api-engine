<?php

namespace App\Domain\Projects;

use App\Domain\Shared\DomainError;

final class ProjectError extends DomainError
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

    public static function stale(): self
    {
        return new self('Someone else changed this project while you were editing. Reload to see their changes.', 'project.stale', 409);
    }

    public static function archived(): self
    {
        return new self('This project is archived. Restore it before making changes.', 'project.archived', 409);
    }

    public static function stepNotReached(): self
    {
        return new self('Finish the earlier setup steps first.', 'project.step_not_reached', 409);
    }

    public static function busy(): self
    {
        return new self('An import, route scan or analysis for this project is running right now. Wait a moment for it to finish (or cancel it), then delete.', 'project.busy', 409);
    }

    public static function notArchived(): self
    {
        return new self('This project is not archived.', 'project.not_archived', 409);
    }
}
