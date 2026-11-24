<?php

namespace Tests\Feature\Sources;

use App\Domain\Sources\FixtureSourceProvider;
use App\Domain\Workspaces\WorkspaceRole;
use App\Models\ProcessingRun;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

class SourceAccessTest extends TestCase
{
    use RefreshDatabase, WorksWithWorkspaces;

    private Project $project;

    private User $developer;

    private ProcessingRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        config(['features.project_wizard' => true, 'features.source_snapshots' => true, 'app.debug' => false]);
        $this->withoutVite();
        Storage::fake('quarantine', ['throw' => true]);
        Storage::fake('snapshots', ['throw' => true]);

        $this->project = Project::factory()->create();
        $this->developer = $this->memberOf(WorkspaceRole::Developer, $this->project->workspace);
        $result = app(FixtureSourceProvider::class)->import($this->developer, $this->project, ['fixture' => 'laravel-mini']);
        $this->run = ProcessingRun::query()->findOrFail($result->runId);
    }

    private function snapshot(): SourceSnapshot
    {
        return SourceSnapshot::query()->findOrFail($this->run->source_snapshot_id);
    }

    public function test_developers_see_the_file_list_qa_sees_status_only()
    {
        $url = "/projects/{$this->project->id}/snapshots/{$this->snapshot()->id}";

        $this->actingAs($this->developer)->get($url)->assertOk()->assertInertia(fn ($page) => $page
            ->component('projects/snapshot')
            ->where('snapshot.status', 'ready')
            ->has('files.data', 9));

        $this->actingAs($this->memberOf(WorkspaceRole::QA, $this->project->workspace))->get($url)->assertOk()->assertInertia(fn ($page) => $page
            ->where('snapshot.status', 'ready')
            ->where('snapshot.fileCount', 5)
            ->where('files', null));
    }

    public function test_no_response_leaks_storage_keys_or_server_paths()
    {
        $this->actingAs($this->developer);
        $snapshot = $this->snapshot();

        foreach ([
            $this->get("/projects/{$this->project->id}"),
            $this->get("/projects/{$this->project->id}/snapshots/{$snapshot->id}"),
            $this->getJson("/processing-runs/{$this->run->id}"),
        ] as $response) {
            $body = $response->assertOk()->getContent();
            $this->assertStringNotContainsString((string) $snapshot->storage_key, (string) $body);
            $this->assertStringNotContainsString(str_replace('/', '\/', storage_path()), (string) $body);
            $this->assertStringNotContainsString(storage_path(), (string) $body);
            $this->assertStringNotContainsString('quarantine', (string) $body);
            $this->assertStringNotContainsString($this->run->idempotency_key, (string) $body);
        }
    }

    public function test_the_project_page_shows_the_source_panel()
    {
        $this->actingAs($this->developer)->get("/projects/{$this->project->id}")->assertInertia(fn ($page) => $page
            ->where('project.status', 'active')
            ->where('sources.latestRun.status', 'completed')
            ->has('sources.snapshots', 1)
            ->where('sources.snapshots.0.status', 'ready')
            ->where('sources.canImportFixture', true));

        $this->actingAs($this->memberOf(WorkspaceRole::Viewer, $this->project->workspace))
            ->get("/projects/{$this->project->id}")
            ->assertInertia(fn ($page) => $page->where('sources.canImportFixture', false));
    }

    public function test_another_tenants_snapshot_or_run_is_indistinguishable_from_a_missing_one()
    {
        $this->actingAs($this->memberOf(WorkspaceRole::Owner));
        $snapshot = $this->snapshot();
        $missing = (string) Str::uuid();

        $this->assertSame(
            $this->getJson("/projects/{$this->project->id}/snapshots/{$missing}")->assertNotFound()->json(),
            $this->getJson("/projects/{$this->project->id}/snapshots/{$snapshot->id}")->assertNotFound()->json(),
        );
        $this->assertSame(
            $this->getJson("/processing-runs/{$missing}")->assertNotFound()->json(),
            $this->getJson("/processing-runs/{$this->run->id}")->assertNotFound()->json(),
        );
        $this->postJson("/processing-runs/{$this->run->id}/cancel")->assertNotFound();
        $this->postJson("/projects/{$this->project->id}/fixture-import")->assertNotFound();
    }

    public function test_a_snapshot_is_only_reachable_through_its_own_project()
    {
        $other = Project::factory()->for($this->project->workspace)->create();

        $this->actingAs($this->developer)
            ->getJson("/projects/{$other->id}/snapshots/{$this->snapshot()->id}")
            ->assertNotFound();
    }

    public function test_restricted_projects_hide_their_snapshots_too()
    {
        $this->project->forceFill(['visibility' => 'restricted', 'created_by' => null])->save();

        $this->actingAs($this->developer)->getJson("/processing-runs/{$this->run->id}")->assertNotFound();
        $this->getJson("/projects/{$this->project->id}/snapshots/{$this->snapshot()->id}")->assertNotFound();
    }

    public function test_only_managers_of_the_project_can_cancel_or_import()
    {
        $this->actingAs($this->memberOf(WorkspaceRole::Viewer, $this->project->workspace));

        $this->postJson("/processing-runs/{$this->run->id}/cancel")->assertForbidden();
        $this->postJson("/projects/{$this->project->id}/fixture-import")->assertForbidden();
        $this->getJson("/processing-runs/{$this->run->id}")->assertOk()->assertJsonPath('status', 'completed');
    }

    public function test_the_flag_hides_the_routes_and_the_panel()
    {
        config(['features.source_snapshots' => false]);
        $this->actingAs($this->developer);

        $this->getJson("/processing-runs/{$this->run->id}")->assertNotFound();
        $this->get("/projects/{$this->project->id}")->assertInertia(fn ($page) => $page->where('sources', null));
    }
}
