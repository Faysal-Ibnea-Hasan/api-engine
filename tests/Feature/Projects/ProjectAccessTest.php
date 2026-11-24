<?php

namespace Tests\Feature\Projects;

use App\Domain\Workspaces\WorkspaceRole;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

class ProjectAccessTest extends TestCase
{
    use RefreshDatabase, WorksWithWorkspaces;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        config(['features.project_wizard' => true, 'app.debug' => false]);
        $this->withoutVite();
        $this->workspace = Workspace::factory()->create();
    }

    /** @return array<int, callable(string): TestResponse> */
    private function requests(): array
    {
        return [
            fn (string $id) => $this->getJson("/projects/{$id}"),
            fn (string $id) => $this->patchJson("/projects/{$id}", ['name' => '', 'lock_version' => 0]),
            fn (string $id) => $this->getJson("/projects/{$id}/setup/source"),
            fn (string $id) => $this->putJson("/projects/{$id}/setup/source", ['source_preference' => 'bogus']),
            fn (string $id) => $this->postJson("/projects/{$id}/archive"),
        ];
    }

    public function test_another_workspaces_project_is_indistinguishable_from_a_missing_one()
    {
        $theirs = Project::factory()->create();
        $this->actingAs($this->memberOf(WorkspaceRole::Owner, $this->workspace));
        $missing = (string) Str::uuid();

        foreach ($this->requests() as $request) {
            $this->assertSame($request($missing)->assertNotFound()->json(), $request($theirs->id)->assertNotFound()->json());
        }

        $this->assertFalse($theirs->fresh()->isArchived());
    }

    public function test_restricted_projects_are_hidden_from_non_creators_below_maintainer()
    {
        $creator = $this->memberOf(WorkspaceRole::Developer, $this->workspace);
        $project = Project::factory()->restricted()->for($this->workspace)->create(['created_by' => $creator->id]);
        Project::factory()->for($this->workspace)->create();

        foreach ([WorkspaceRole::Developer, WorkspaceRole::QA, WorkspaceRole::Viewer] as $role) {
            $this->actingAs($this->memberOf($role, $this->workspace));
            $this->getJson("/projects/{$project->id}")->assertNotFound();
            $this->get('/projects')->assertInertia(fn ($page) => $page->has('projects', 1));
        }

        foreach ([$creator, $this->memberOf(WorkspaceRole::Maintainer, $this->workspace), $this->memberOf(WorkspaceRole::Owner, $this->workspace)] as $user) {
            $this->actingAs($user)->get("/projects/{$project->id}")->assertOk();
            $this->get('/projects')->assertInertia(fn ($page) => $page->has('projects', 2));
        }
    }

    public function test_qa_and_viewers_can_look_but_not_change()
    {
        $project = Project::factory()->for($this->workspace)->create();

        foreach ([WorkspaceRole::QA, WorkspaceRole::Viewer] as $role) {
            $this->actingAs($this->memberOf($role, $this->workspace));

            $this->get("/projects/{$project->id}")->assertOk()->assertInertia(fn ($page) => $page->where('can.update', false));
            $this->get('/projects')->assertInertia(fn ($page) => $page->where('canCreate', false));
            $this->get('/projects/create')->assertForbidden();
            $this->postJson('/projects', ['name' => 'X', 'visibility' => 'workspace'])->assertForbidden();
            $this->patchJson("/projects/{$project->id}", ['name' => 'X', 'slug' => 'x', 'visibility' => 'workspace', 'lock_version' => 0])->assertForbidden();
            $this->postJson("/projects/{$project->id}/archive")->assertForbidden();
            $this->getJson("/projects/{$project->id}/setup/source")->assertForbidden();
        }
    }

    public function test_a_user_without_a_workspace_is_sent_to_create_one()
    {
        $this->actingAs(User::factory()->create());

        $this->get('/projects')->assertRedirect('/workspaces');
        $this->postJson('/projects', ['name' => 'X', 'visibility' => 'workspace'])->assertNotFound();
    }

    public function test_a_removed_member_loses_the_project_immediately()
    {
        $project = Project::factory()->for($this->workspace)->create();
        $dev = $this->memberOf(WorkspaceRole::Developer, $this->workspace);
        $this->actingAs($dev)->get("/projects/{$project->id}")->assertOk();

        $this->membershipOf($dev, $this->workspace)->delete();

        $this->getJson("/projects/{$project->id}")->assertNotFound();
    }
}
