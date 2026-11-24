<?php

namespace Database\Factories;

use App\Domain\Workspaces\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceMember>
 */
class WorkspaceMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'role' => WorkspaceRole::Developer,
        ];
    }

    public function role(WorkspaceRole $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }
}
