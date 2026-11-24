<?php

namespace App\Domain\Workspaces;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Maintainer = 'maintainer';
    case Developer = 'developer';
    case QA = 'qa';
    case Viewer = 'viewer';

    /** The single permission matrix (development-plan/P01 §5). */
    public function can(Capability $capability): bool
    {
        return match ($capability) {
            Capability::ManageMembers,
            Capability::UpdateWorkspace,
            Capability::DeleteProjects,
            Capability::ConfigureAi,
            Capability::PublishDocs => in_array($this, [self::Owner, self::Maintainer], true),

            Capability::ManageProjects,
            Capability::ImportAndAnalyze,
            Capability::SubmitDocs,
            Capability::ReadSourceEvidence => in_array($this, [self::Owner, self::Maintainer, self::Developer], true),

            Capability::ReadPublishedDocs => true,
        };
    }

    /** Owners may hand out any role; maintainers any role except Owner. */
    public function canAssign(self $role): bool
    {
        return match ($this) {
            self::Owner => true,
            self::Maintainer => $role !== self::Owner,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::QA => 'QA',
            default => ucfirst($this->value),
        };
    }

    /** @return array<string, bool> capability => allowed, for the UI */
    public function capabilities(): array
    {
        $map = [];

        foreach (Capability::cases() as $capability) {
            $map[$capability->value] = $this->can($capability);
        }

        return $map;
    }
}
