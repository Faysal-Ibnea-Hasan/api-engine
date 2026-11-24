<?php

namespace Tests\Feature\Projects;

use App\Domain\Projects\ProjectStatus;
use App\Domain\Projects\WizardStep;
use App\Domain\Workspaces\WorkspaceRole;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

class ProjectWizardTest extends TestCase
{
    use RefreshDatabase, WorksWithWorkspaces;

    private Workspace $workspace;

    private User $developer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        config(['features.project_wizard' => true]);
        $this->withoutVite();

        $this->workspace = Workspace::factory()->create();
        $this->developer = $this->memberOf(WorkspaceRole::Developer, $this->workspace);
        $this->actingAs($this->developer);
    }

    private function createProject(array $details = []): Project
    {
        $before = Project::query()->pluck('id');
        $this->post('/projects', $details + ['name' => 'Billing API', 'visibility' => 'workspace'])->assertSessionHasNoErrors();

        return Project::query()->whereNotIn('id', $before)->sole();
    }

    private function save(Project $project, string $step, array $data)
    {
        return $this->put("/projects/{$project->id}/setup/{$step}", $data + ['lock_version' => $project->fresh()->lock_version]);
    }

    public function test_the_feature_flag_hides_everything()
    {
        config(['features.project_wizard' => false]);

        $this->get('/projects')->assertNotFound();
        $this->post('/projects', ['name' => 'X', 'visibility' => 'workspace'])->assertNotFound();
    }

    public function test_details_create_a_draft_in_the_current_workspace_and_nothing_starts()
    {
        Queue::fake();
        Http::preventStrayRequests();

        $this->post('/projects', ['name' => 'Billing API', 'description' => 'Payments', 'visibility' => 'workspace'])
            ->assertRedirect();

        $project = Project::query()->sole();
        $this->assertSame($this->workspace->id, $project->workspace_id);
        $this->assertSame('billing-api', $project->slug);
        $this->assertSame($this->developer->id, $project->created_by);
        $this->assertSame(ProjectStatus::Draft, $project->status);
        $this->assertSame(WizardStep::Source, $project->wizard_step);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'project.created', 'target_id' => $project->id]);
        Queue::assertNothingPushed();
    }

    public function test_the_wizard_resumes_with_saved_values_after_a_refresh()
    {
        $project = $this->createProject();

        $this->save($project, 'source', ['source_preference' => 'git'])->assertRedirect("/projects/{$project->id}/setup/framework");
        $this->save($project, 'framework', ['framework' => 'laravel', 'root_path' => 'backend'])->assertRedirect("/projects/{$project->id}/setup/analysis");

        // "Refresh at step 3": jumping ahead resumes at the furthest saved step, with the saved values.
        $this->get("/projects/{$project->id}/setup/review")->assertRedirect("/projects/{$project->id}/setup/analysis");
        $this->get("/projects/{$project->id}/setup/analysis")->assertOk()->assertInertia(fn ($page) => $page
            ->component('projects/wizard')
            ->where('step', 'analysis')
            ->where('project.sourcePreference', 'git')
            ->where('project.rootPath', 'backend'));

        $this->save($project, 'analysis', ['analysis_mode' => 'static', 'documentation_mode' => 'all_endpoints'])->assertRedirect("/projects/{$project->id}/setup/review");
        $this->save($project, 'review', [])->assertRedirect("/projects/{$project->id}");

        $project->refresh();
        $this->assertNotNull($project->wizard_completed_at);
        $this->assertSame(ProjectStatus::Draft, $project->status, 'stays a draft until a source is imported');
        $this->assertDatabaseHas('audit_events', ['event_type' => 'project.setup_completed']);
    }

    public function test_unsupported_or_unsafe_choices_are_rejected()
    {
        $project = $this->createProject();
        $this->save($project, 'source', ['source_preference' => 'zip']);

        foreach (['../etc', '/var/www', 'a/../../b', 'back\\slash'] as $path) {
            $this->save($project, 'framework', ['framework' => 'laravel', 'root_path' => $path])->assertSessionHasErrors('root_path');
        }
        $this->save($project, 'framework', ['framework' => 'symfony', 'root_path' => '.'])->assertSessionHasErrors('framework');

        $this->save($project, 'framework', ['framework' => 'laravel', 'root_path' => '.']);
        $this->save($project, 'analysis', ['analysis_mode' => 'static_with_ai', 'documentation_mode' => 'on_demand'])
            ->assertSessionHasErrors('analysis_mode');
        $this->get('/projects/'.$project->id.'/setup/nonsense')->assertNotFound();
    }

    public function test_slugs_are_unique_per_workspace_only()
    {
        $this->createProject(['name' => 'Billing API']);
        $second = $this->createProject(['name' => 'Billing API']);
        $this->assertSame('billing-api-2', $second->slug, 'auto slugs get a free suffix');

        $this->post('/projects', ['name' => 'Other', 'slug' => 'billing-api', 'visibility' => 'workspace'])->assertSessionHasErrors('slug');
        $this->post('/projects', ['name' => 'Other', 'slug' => 'Not A Slug', 'visibility' => 'workspace'])->assertSessionHasErrors('slug');

        $elsewhere = Workspace::factory()->create();
        $this->actingAs($this->memberOf(WorkspaceRole::Owner, $elsewhere))
            ->post('/projects', ['name' => 'Billing API', 'slug' => 'billing-api', 'visibility' => 'workspace'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Project::query()->forWorkspace($elsewhere)->where('slug', 'billing-api')->count());
    }

    public function test_a_stale_edit_is_refused_instead_of_overwriting()
    {
        $project = $this->createProject();
        $readVersion = $project->lock_version;

        $this->patch("/projects/{$project->id}", ['name' => 'First edit', 'slug' => $project->slug, 'visibility' => 'workspace', 'lock_version' => $readVersion])->assertRedirect();
        $this->patchJson("/projects/{$project->id}", ['name' => 'Second edit', 'slug' => $project->slug, 'visibility' => 'workspace', 'lock_version' => $readVersion])
            ->assertStatus(409)->assertJsonPath('error.code', 'project.stale');

        $this->assertSame('First edit', $project->fresh()->name);
    }

    public function test_archive_makes_it_read_only_and_restore_brings_it_back()
    {
        $project = $this->createProject();

        $this->post("/projects/{$project->id}/archive")->assertRedirect();
        $this->assertSame(ProjectStatus::Archived, $project->fresh()->status);

        $this->patchJson("/projects/{$project->id}", ['name' => 'Nope', 'slug' => $project->slug, 'visibility' => 'workspace', 'lock_version' => $project->fresh()->lock_version])
            ->assertStatus(409)->assertJsonPath('error.code', 'project.archived');
        $this->save($project, 'source', ['source_preference' => 'git']);
        $this->assertNull($project->fresh()->source_preference);

        $this->get('/projects')->assertInertia(fn ($page) => $page->has('projects', 0));
        $this->get('/projects?archived=1')->assertInertia(fn ($page) => $page->has('projects', 1));

        $this->post("/projects/{$project->id}/restore")->assertRedirect();
        $this->assertSame(ProjectStatus::Draft, $project->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'project.restored']);
    }

    public function test_search_filters_the_list()
    {
        $this->createProject(['name' => 'Billing API']);
        $this->createProject(['name' => 'Search Service']);

        $this->get('/projects?q=bill')->assertInertia(fn ($page) => $page
            ->has('projects', 1)
            ->where('projects.0.name', 'Billing API'));
        $this->get('/projects?q=%25')->assertInertia(fn ($page) => $page->has('projects', 0));
    }

    public function test_saving_a_step_ahead_of_the_furthest_one_is_refused()
    {
        $project = $this->createProject();

        $this->putJson("/projects/{$project->id}/setup/review", ['lock_version' => $project->lock_version])
            ->assertStatus(409)->assertJsonPath('error.code', 'project.step_not_reached');
        $this->putJson("/projects/{$project->id}/setup/analysis", ['analysis_mode' => 'static', 'documentation_mode' => 'on_demand', 'lock_version' => $project->lock_version])
            ->assertStatus(409);

        $this->assertNull($project->fresh()->wizard_completed_at);
        $this->assertSame(WizardStep::Source, $project->fresh()->wizard_step);
    }
}
