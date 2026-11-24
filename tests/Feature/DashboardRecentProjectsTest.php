<?php

namespace Tests\Feature;

use App\Domain\Workspaces\WorkspaceRole;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

// AC41: the dashboard's recentProjects prop.
class DashboardRecentProjectsTest extends TestCase
{
    use RefreshDatabase, WorksWithWorkspaces;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        config(['features.project_wizard' => true]);
        $this->withoutVite();
    }

    private function dashboard(User $user): TestResponse
    {
        return $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_owner_sees_the_five_most_recently_updated_projects()
    {
        $workspace = Workspace::factory()->create();
        $owner = $this->memberOf(WorkspaceRole::Owner, $workspace);

        $mine = collect(range(0, 5))->map(fn (int $i) => Project::factory()->for($workspace)->create([
            'updated_at' => now()->subMinutes($i + 1),
        ]));
        $other = Project::factory()->create(['updated_at' => now()->addMinute()]);

        $expected = $mine->take(5)->pluck('id')->all();

        $this->dashboard($owner)->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard')
            ->has('recentProjects', 5)
            ->where('recentProjects', fn ($rows) => collect($rows)->pluck('id')->all() === $expected
                && ! collect($rows)->pluck('id')->contains($other->id))
            ->has('recentProjects.0', fn ($p) => $p
                ->where('id', $mine[0]->id)
                ->where('name', $mine[0]->name)
                ->where('slug', $mine[0]->slug)
                ->where('status', 'draft')
                ->where('visibility', 'workspace')
                ->where('setupComplete', false)
                ->where('updatedAt', fn ($v) => is_string($v) && $v !== '')));
    }

    public function test_restricted_projects_are_listed_only_for_roles_that_can_see_them()
    {
        $workspace = Workspace::factory()->create();
        $creator = $this->memberOf(WorkspaceRole::Developer, $workspace);
        $project = Project::factory()->restricted()->for($workspace)->create(['created_by' => $creator->id]);

        foreach ([
            $this->memberOf(WorkspaceRole::Owner, $workspace),
            $this->memberOf(WorkspaceRole::Maintainer, $workspace),
            $creator,
        ] as $user) {
            $this->dashboard($user)->assertInertia(fn (AssertableInertia $page) => $page
                ->component('dashboard')
                ->has('recentProjects', 1)
                ->where('recentProjects.0.id', $project->id));
        }

        foreach ([
            $this->memberOf(WorkspaceRole::Developer, $workspace),
            $this->memberOf(WorkspaceRole::Viewer, $workspace),
            $this->memberOf(WorkspaceRole::QA, $workspace),
        ] as $user) {
            $this->dashboard($user)->assertInertia(fn (AssertableInertia $page) => $page
                ->component('dashboard')
                ->where('recentProjects', []));
        }
    }

    public function test_archived_projects_are_left_out()
    {
        $workspace = Workspace::factory()->create();
        $owner = $this->memberOf(WorkspaceRole::Owner, $workspace);
        Project::factory()->archived()->for($workspace)->create(['updated_at' => now()->subMinute()]);
        $draft = Project::factory()->for($workspace)->create(['updated_at' => now()->subHour()]);

        $this->dashboard($owner)->assertInertia(fn (AssertableInertia $page) => $page
            ->has('recentProjects', 1)
            ->where('recentProjects.0.id', $draft->id));
    }

    public function test_a_user_without_a_workspace_gets_no_recent_projects()
    {
        $this->dashboard(User::factory()->create())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('dashboard')
                ->missing('recentProjects'));
    }

    public function test_recent_projects_need_both_feature_flags()
    {
        $workspace = Workspace::factory()->create();
        $owner = $this->memberOf(WorkspaceRole::Owner, $workspace);
        Project::factory()->for($workspace)->create();

        config(['features.project_wizard' => false]);
        $this->dashboard($owner)->assertInertia(fn (AssertableInertia $page) => $page->missing('recentProjects'));

        config(['features.project_wizard' => true, 'features.workspace_management' => false]);
        $this->dashboard($owner)->assertInertia(fn (AssertableInertia $page) => $page->missing('recentProjects'));
    }
}
