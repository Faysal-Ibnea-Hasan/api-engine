<?php

namespace Database\Factories;

use App\Domain\Workspaces\WorkspaceRole;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Pass ['token_hash' => WorkspaceInvitation::hashToken($token)] to know the token.
 *
 * @extends Factory<WorkspaceInvitation>
 */
class WorkspaceInvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => WorkspaceRole::Developer,
            'token_hash' => WorkspaceInvitation::hashToken(Str::random(48)),
            'expires_at' => now()->addDays(WorkspaceInvitation::TTL_DAYS),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }
}
