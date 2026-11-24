<?php

namespace Tests\Feature\Projects;

use App\Domain\Projects\ProjectStatus;
use App\Domain\Sources\SnapshotImporter;
use App\Domain\Workspaces\WorkspaceRole;
use App\Models\ApiEndpoint;
use App\Models\FrameworkDetection;
use App\Models\ProcessingRun;
use App\Models\Project;
use App\Models\RouteScan;
use App\Models\ScanFinding;
use App\Models\SourceFile;
use App\Models\SourceSnapshot;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsSnapshots;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

class ProjectDeletionTest extends TestCase
{
    use BuildsSnapshots, RefreshDatabase, WorksWithWorkspaces;

    private Project $project;

    private User $maintainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        config(['features.project_wizard' => true, 'features.source_snapshots' => true, 'features.framework_detection' => true, 'features.route_discovery' => true, 'app.debug' => false]);
        $this->withoutVite();
        Storage::fake('quarantine', ['throw' => true]);
        Storage::fake('snapshots', ['throw' => true]);

        $this->project = Project::factory()->create(['name' => 'Billing API']);
        $this->maintainer = $this->memberOf(WorkspaceRole::Maintainer, $this->project->workspace);
    }

    /** A project with a full pipeline behind it: snapshot, detection, scan, endpoints, findings. */
    private function populate(Project $project): void
    {
        $files = $this->laravel11();
        $files['routes/api.php'] = "<?php\nRoute::get('users', [\\App\\Http\\Controllers\\UserController::class, 'index']);\nRoute::get(\$dynamic, fn () => 1);\n";
        $this->snapshotFrom($files, $project, $this->maintainer);
    }

    private function deleteProject(Project $project, string $name)
    {
        return $this->delete("/projects/{$project->id}", ['confirm_name' => $name]);
    }

    public function test_deleting_removes_every_row_and_stored_file_but_leaves_other_projects_alone()
    {
        $this->populate($this->project);
        $other = Project::factory()->for($this->project->workspace)->create();
        $this->populate($other);
        $this->assertGreaterThan(0, ApiEndpoint::query()->where('project_id', $this->project->id)->count());
        $this->assertNotEmpty(Storage::disk('snapshots')->allFiles("{$this->project->workspace_id}/{$this->project->id}"));

        $this->actingAs($this->maintainer)->deleteProject($this->project, 'Billing API')->assertRedirect('/projects');

        $this->assertNull(Project::query()->find($this->project->id));
        foreach ([SourceSnapshot::class, ProcessingRun::class, FrameworkDetection::class, RouteScan::class, ApiEndpoint::class, UploadSession::class] as $model) {
            $this->assertSame(0, $model::query()->where('project_id', $this->project->id)->count(), $model);
        }
        $this->assertSame(0, SourceFile::query()->whereNotIn('source_snapshot_id', SourceSnapshot::query()->select('id'))->count());
        $this->assertSame(0, ScanFinding::query()->whereNotIn('route_scan_id', RouteScan::query()->select('id'))->count());
        $this->assertSame([], Storage::disk('snapshots')->allFiles("{$this->project->workspace_id}/{$this->project->id}"));

        // The other project is untouched, and the deletion is on record.
        $this->assertNotNull($other->fresh());
        $this->assertGreaterThan(0, ApiEndpoint::query()->where('project_id', $other->id)->count());
        $this->assertNotEmpty(Storage::disk('snapshots')->allFiles("{$other->workspace_id}/{$other->id}"));
        $this->assertDatabaseHas('audit_events', ['event_type' => 'project.deleted', 'actor_id' => $this->maintainer->id]);
    }

    public function test_archived_projects_can_be_deleted_too()
    {
        $this->project->forceFill(['status' => ProjectStatus::Archived, 'status_before_archive' => ProjectStatus::Draft, 'archived_at' => now()])->save();

        $this->actingAs($this->maintainer)->deleteProject($this->project, 'Billing API')->assertRedirect('/projects');

        $this->assertNull(Project::query()->find($this->project->id));
    }

    public function test_the_typed_name_must_match_exactly()
    {
        $this->actingAs($this->maintainer);

        $this->deleteProject($this->project, 'billing api')->assertSessionHasErrors('confirm_name');
        $this->delete("/projects/{$this->project->id}")->assertSessionHasErrors('confirm_name');

        $this->assertNotNull($this->project->fresh());
    }

    public function test_only_owners_and_maintainers_may_delete()
    {
        foreach ([WorkspaceRole::Developer, WorkspaceRole::QA, WorkspaceRole::Viewer] as $role) {
            $this->actingAs($this->memberOf($role, $this->project->workspace))
                ->deleteJson("/projects/{$this->project->id}", ['confirm_name' => 'Billing API'])->assertForbidden();
        }

        // Another tenant: identical to a missing project, even with a wrong confirmation.
        $this->actingAs($this->memberOf(WorkspaceRole::Owner));
        $this->assertSame(
            $this->deleteJson('/projects/'.Str::uuid(), [])->assertNotFound()->json(),
            $this->deleteJson("/projects/{$this->project->id}", [])->assertNotFound()->json(),
        );

        $this->assertNotNull($this->project->fresh());
        $this->actingAs($this->memberOf(WorkspaceRole::Owner, $this->project->workspace))->deleteProject($this->project, 'Billing API')->assertRedirect('/projects');
    }

    public function test_deleting_is_refused_only_while_a_worker_is_actually_running_something()
    {
        $run = ProcessingRun::factory()->create(['project_id' => $this->project->id, 'status' => 'running', 'claimed_at' => now()]);

        $this->actingAs($this->maintainer)->deleteJson("/projects/{$this->project->id}", ['confirm_name' => 'Billing API'])
            ->assertStatus(409)->assertJsonPath('error.code', 'project.busy');
        $this->assertNotNull($this->project->fresh());

        // The same run with a stale claim (its worker died) no longer blocks.
        $run->forceFill(['claimed_at' => now()->subHours(2)])->save();
        $this->deleteProject($this->project, 'Billing API')->assertRedirect('/projects');
        $this->assertNull($this->project->fresh());
    }

    public function test_unfinished_uploads_and_queued_work_do_not_block_and_are_discarded()
    {
        // e.g. a big upload that was started and abandoned: open for 24h, 0 bytes received.
        $upload = UploadSession::factory()->create(['project_id' => $this->project->id, 'workspace_id' => $this->project->workspace_id, 'created_by' => $this->maintainer->id, 'total_bytes' => 2554765442]);
        Storage::disk('quarantine')->put("uploads/{$upload->id}/archive.part", 'partial');
        $queued = ProcessingRun::factory()->create(['project_id' => $this->project->id, 'status' => 'queued']);

        $this->actingAs($this->maintainer)->deleteProject($this->project, 'Billing API')->assertRedirect('/projects');

        $this->assertNull($this->project->fresh());
        $this->assertNull(UploadSession::query()->find($upload->id));
        $this->assertNull(ProcessingRun::query()->find($queued->id));
        $this->assertSame([], Storage::disk('quarantine')->allFiles("uploads/{$upload->id}"));

        // A job for the deleted run that starts later finds nothing to claim and stops quietly.
        app(SnapshotImporter::class)->process($queued->id);
        $this->assertNull(ProcessingRun::query()->find($queued->id));
    }

    public function test_the_project_page_offers_delete_only_to_those_who_may()
    {
        $this->actingAs($this->maintainer)->get("/projects/{$this->project->id}")->assertInertia(fn ($page) => $page->where('can.delete', true));
        $this->actingAs($this->memberOf(WorkspaceRole::Developer, $this->project->workspace))
            ->get("/projects/{$this->project->id}")->assertInertia(fn ($page) => $page->where('can.delete', false)->where('can.update', true));
    }
}
