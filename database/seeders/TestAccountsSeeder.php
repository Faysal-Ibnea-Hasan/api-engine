<?php

namespace Database\Seeders;

use App\Domain\Projects\ProjectService;
use App\Domain\Workspaces\WorkspaceRole;
use App\Domain\Workspaces\WorkspaceService;
use App\Models\Project;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Database\Seeder;

/**
 * Testing-only accounts for Codewright runtime checks and personas. Every password is "password".
 * Run with: php artisan db:seed --class=TestAccountsSeeder --env=testing (safe to re-run).
 */
class TestAccountsSeeder extends Seeder
{
    public function run(WorkspaceService $workspaces, ProjectService $projects): void
    {
        abort_unless(app()->environment('testing'), 500, 'TestAccountsSeeder runs only in the testing environment.');

        $this->account('verified@test.local', 'Verified User');
        $this->account('unverified@test.local', 'Unverified User', User::factory()->unverified());
        $this->account('twofactor@test.local', 'Two Factor User', User::factory()->withTwoFactor());
        $this->account('admin@test.local', 'Platform Admin', User::factory()->platformAdmin());

        // "Test Workspace" with one member per role, plus an outsider who owns a different workspace.
        if (! User::where('email', 'owner@test.local')->exists()) {
            $workspace = $workspaces->create($this->account('owner@test.local', 'Workspace Owner'), 'Test Workspace');

            foreach ([WorkspaceRole::Maintainer, WorkspaceRole::Developer, WorkspaceRole::QA, WorkspaceRole::Viewer] as $role) {
                $user = $this->account("{$role->value}@test.local", "Workspace {$role->label()}");
                $workspaces->addMember($workspace, $user, $role);
                $user->switchWorkspace($workspace->id);
            }

            $workspaces->create($this->account('outsider@test.local', 'Other Tenant Owner'), 'Other Workspace');
        }

        // One project everyone in Test Workspace can see, one only the developer (its creator) and managers can.
        if (! Project::query()->where('slug', 'billing-api')->exists()) {
            $developer = User::where('email', 'developer@test.local')->firstOrFail();
            $workspace = $developer->currentMembership()?->workspace;

            if ($workspace !== null) {
                $projects->create($developer, $workspace, ['name' => 'Billing API', 'slug' => 'billing-api', 'visibility' => 'workspace']);
                $projects->create($developer, $workspace, ['name' => 'Internal Admin API', 'slug' => 'internal-admin-api', 'visibility' => 'restricted']);
            }
        }
    }

    private function account(string $email, string $name, ?UserFactory $factory = null): User
    {
        return User::where('email', $email)->first()
            ?? ($factory ?? User::factory())->createOne(['name' => $name, 'email' => $email]);
    }
}
