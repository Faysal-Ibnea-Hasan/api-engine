<?php

namespace App\Domain\Workspaces;

use App\Domain\Shared\DomainError;

final class MembershipError extends DomainError
{
    private function __construct(string $message, private readonly string $stableCode)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->stableCode;
    }

    public static function lastOwner(): self
    {
        return new self('A workspace needs at least one owner. Make someone else an owner first.', 'workspace.last_owner');
    }

    public static function cannotAssign(WorkspaceRole $role): self
    {
        return new self("Your role can't assign the {$role->label()} role.", 'workspace.role_not_assignable');
    }

    public static function cannotManageOwner(): self
    {
        return new self('Only owners can change or remove another owner.', 'workspace.owner_protected');
    }

    public static function alreadyMember(): self
    {
        return new self('That person is already a member of this workspace.', 'workspace.already_member');
    }

    public static function soleOwnerCannotDeleteAccount(): self
    {
        return new self('You are the only owner of a workspace. Make someone else an owner before deleting your account.', 'account.sole_owner');
    }
}
