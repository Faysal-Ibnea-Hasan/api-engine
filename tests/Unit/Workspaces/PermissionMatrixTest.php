<?php

namespace Tests\Unit\Workspaces;

use App\Domain\Workspaces\Capability;
use App\Domain\Workspaces\WorkspaceRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PermissionMatrixTest extends TestCase
{
    /** development-plan/P01 §5 "Minimum role matrix", plus the two workspace-admin capabilities. */
    private const MATRIX = [
        // capability                       owner maint  dev    qa     viewer
        'manage_members' => [true,  true,  false, false, false],
        'update_workspace' => [true,  true,  false, false, false],
        'delete_projects' => [true,  true,  false, false, false],
        'manage_projects' => [true,  true,  true,  false, false],
        'import_and_analyze' => [true,  true,  true,  false, false],
        'configure_ai' => [true,  true,  false, false, false],
        'submit_docs' => [true,  true,  true,  false, false],
        'publish_docs' => [true,  true,  false, false, false],
        'read_published_docs' => [true,  true,  true,  true,  true],
        'read_source_evidence' => [true,  true,  true,  false, false],
        'manage_integrations' => [true,  true,  false, false, false],
    ];

    /** @return iterable<string, array{Capability, list<bool>}> */
    public static function capabilities(): iterable
    {
        foreach (self::MATRIX as $capability => $allowed) {
            yield $capability => [Capability::from($capability), $allowed];
        }
    }

    /** @param  list<bool>  $allowed */
    #[DataProvider('capabilities')]
    public function test_matrix_matches_the_plan(Capability $capability, array $allowed)
    {
        foreach (WorkspaceRole::cases() as $i => $role) {
            $this->assertSame($allowed[$i], $role->can($capability), "{$role->value} → {$capability->value}");
        }
    }

    public function test_every_capability_is_covered()
    {
        $this->assertCount(count(Capability::cases()), self::MATRIX);
    }

    public function test_only_owners_can_hand_out_the_owner_role()
    {
        $this->assertTrue(WorkspaceRole::Owner->canAssign(WorkspaceRole::Owner));
        $this->assertFalse(WorkspaceRole::Maintainer->canAssign(WorkspaceRole::Owner));
        $this->assertTrue(WorkspaceRole::Maintainer->canAssign(WorkspaceRole::Viewer));
        $this->assertFalse(WorkspaceRole::Developer->canAssign(WorkspaceRole::Viewer));
    }
}
