<?php

namespace Tests\Feature\Analysis;

use App\Domain\Analysis\EndpointAnalyses;
use App\Domain\Analysis\EndpointAnalyzer;
use App\Domain\Projects\ProjectDeleter;
use App\Domain\Projects\ProjectError;
use App\Domain\Projects\ProjectStatus;
use App\Domain\Sources\RunStatus;
use App\Domain\Workspaces\WorkspaceRole;
use App\Jobs\AnalyzeEndpointJob;
use App\Models\AnalysisEvidence;
use App\Models\AnalysisFinding;
use App\Models\ApiEndpoint;
use App\Models\EndpointAnalysis;
use App\Models\Project;
use App\Models\RouteScan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Concerns\AnalyzesFixtures;
use Tests\Concerns\BuildsSnapshots;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

class EndpointAnalysisFlowTest extends TestCase
{
    use AnalyzesFixtures, BuildsSnapshots, RefreshDatabase, WorksWithWorkspaces;

    private Project $project;

    private User $developer;

    private ApiEndpoint $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        config(['features.project_wizard' => true, 'features.source_snapshots' => true, 'features.framework_detection' => true, 'features.route_discovery' => true, 'features.endpoint_analysis' => true, 'app.debug' => false]);
        $this->withoutVite();
        Storage::fake('quarantine', ['throw' => true]);
        Storage::fake('snapshots', ['throw' => true]);

        $this->project = Project::factory()->create();
        $this->developer = $this->memberOf(WorkspaceRole::Developer, $this->project->workspace);
        $this->snapshotFrom($this->fixtureApp([
            'routes/api.php' => "<?php\nuse Fixture\\App\\Http\\Controllers\\OrderController;\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::apiResource('orders', OrderController::class);\n",
        ]), $this->project, $this->developer);

        $this->store = ApiEndpoint::query()->where('project_id', $this->project->id)->where('uri', '/api/orders')->where('methods_label', 'like', '%|POST|%')->firstOrFail();
    }

    private function analyzeUrl(?ApiEndpoint $endpoint = null): string
    {
        return "/projects/{$this->project->id}/endpoints/".($endpoint ?? $this->store)->id.'/analyses';
    }

    public function test_analyze_runs_once_per_fingerprint_and_stores_a_valid_result(): void
    {
        $this->actingAs($this->developer)->post($this->analyzeUrl())->assertRedirect();
        $this->actingAs($this->developer)->post($this->analyzeUrl())->assertRedirect();

        $analysis = EndpointAnalysis::query()->sole();
        $this->assertSame(RunStatus::Completed, $analysis->status, json_encode($analysis->result['unresolved'] ?? $analysis->failure_code));
        $this->assertSame(1, $analysis->attempts, 'the second click reuses the finished analysis');
        $this->assertSame(EndpointAnalyzer::VERSION, $analysis->result['analyzer_version']);
        $this->assertSame($this->store->id, $analysis->result['endpoint_id']);
        $this->assertSame(count($analysis->result['evidence']), AnalysisEvidence::query()->where('endpoint_analysis_id', $analysis->id)->count());

        $this->get("/projects/{$this->project->id}/endpoints/{$this->store->id}")->assertInertia(fn ($page) => $page
            ->component('projects/endpoint')
            ->where('analysis.status', 'completed')
            ->where('canAnalyze', true)
            ->has('analysis.result.evidence'));
    }

    public function test_viewers_see_no_line_numbers_in_unknowns(): void
    {
        $dynamic = ApiEndpoint::factory()->create([
            'route_scan_id' => $this->store->route_scan_id, 'controller' => 'Fixture\App\Http\Controllers\OrderController',
            'action' => 'dynamic', 'uri' => '/api/dynamic', 'route_identity' => hash('sha256', 'dynamic-viewer'),
        ]);
        $this->actingAs($this->developer)->post($this->analyzeUrl($dynamic));
        $analysis = EndpointAnalysis::query()->where('api_endpoint_id', $dynamic->id)->sole();
        $this->assertMatchesRegularExpression('/\(line \d+\)/', implode(' ', $analysis->result['unresolved']), 'the stored result keeps lines for source readers');

        $viewer = $this->memberOf(WorkspaceRole::Viewer, $this->project->workspace);
        $json = $this->actingAs($viewer)->get("/projects/{$this->project->id}/analyses/{$analysis->id}")->assertOk()->json();

        $this->assertNotEmpty($json['result']['unresolved']);
        $this->assertDoesNotMatchRegularExpression('/\(line \d+\)/', implode(' ', $json['result']['unresolved']));
        $this->assertDoesNotMatchRegularExpression('/line \d+/', json_encode($json['result']['auth']), 'auth notes carry no source lines for viewers');
        $this->assertArrayNotHasKey('evidence', $json['result']);
    }

    public function test_a_queued_analysis_whose_job_was_lost_is_dispatched_again(): void
    {
        Queue::fake();
        $this->actingAs($this->developer)->post($this->analyzeUrl());
        $this->actingAs($this->developer)->post($this->analyzeUrl());
        Queue::assertPushed(AnalyzeEndpointJob::class, 1);

        EndpointAnalysis::query()->update(['updated_at' => now()->subSeconds(EndpointAnalyses::JOB_TIMEOUT + 5)]);
        $this->actingAs($this->developer)->post($this->analyzeUrl());
        Queue::assertPushed(AnalyzeEndpointJob::class, 2);
    }

    public function test_a_double_click_while_queued_dispatches_one_job(): void
    {
        Queue::fake();
        $this->actingAs($this->developer)->post($this->analyzeUrl());
        $this->actingAs($this->developer)->post($this->analyzeUrl());

        Queue::assertPushed(AnalyzeEndpointJob::class, 1);
        Queue::assertPushedOn('analysis', AnalyzeEndpointJob::class);
        $this->assertSame(1, EndpointAnalysis::query()->count());
    }

    public function test_evidence_excerpt_returns_the_exact_lines_for_source_readers_only(): void
    {
        $this->actingAs($this->developer)->post($this->analyzeUrl());
        $analysis = EndpointAnalysis::query()->sole();
        $ev = AnalysisEvidence::query()->where('endpoint_analysis_id', $analysis->id)->where('kind', 'validation_rule')->orderBy('start_line')->firstOrFail();

        $response = $this->get("/projects/{$this->project->id}/analyses/{$analysis->id}/evidence/{$ev->evidence_key}")->assertOk();
        $hits = collect($response->json('excerpt'))->where('hit', true);
        $this->assertSame($ev->start_line, $hits->first()['n']);
        $this->assertStringContainsString('customer_id', $hits->first()['text']);
        $this->assertSame($ev->sha256, $response->json('sha256'));

        $qa = $this->memberOf(WorkspaceRole::QA, $this->project->workspace);
        $this->actingAs($qa)->get("/projects/{$this->project->id}/analyses/{$analysis->id}/evidence/{$ev->evidence_key}")->assertForbidden();

        // QA sees the contract, but no evidence, paths or symbols.
        $json = $this->actingAs($qa)->get("/projects/{$this->project->id}/analyses/{$analysis->id}")->assertOk()->json();
        $this->assertArrayNotHasKey('evidence', $json['result']);
        $this->assertArrayNotHasKey('evidence', $json['result']['request']['fields'][0]);
        $raw = json_encode($json, JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('app/Http', $raw);
        $this->assertStringNotContainsString('.php', $raw);
        $this->assertStringNotContainsString('sha256', $raw);
        $this->assertDoesNotMatchRegularExpression('/\(line \d+\)/', $raw);
        $this->actingAs($qa)->post($this->analyzeUrl())->assertForbidden();
    }

    public function test_other_workspaces_and_other_projects_get_a_uniform_404(): void
    {
        $this->actingAs($this->developer)->post($this->analyzeUrl());
        $analysis = EndpointAnalysis::query()->sole();
        $ev = AnalysisEvidence::query()->where('endpoint_analysis_id', $analysis->id)->firstOrFail();

        $elsewhere = Workspace::factory()->create();
        $stranger = $this->memberOf(WorkspaceRole::Owner, $elsewhere);
        $this->actingAs($stranger)->post($this->analyzeUrl())->assertNotFound();
        $this->actingAs($stranger)->get("/projects/{$this->project->id}/analyses/{$analysis->id}")->assertNotFound();
        $this->actingAs($stranger)->get("/projects/{$this->project->id}/analyses/{$analysis->id}/evidence/{$ev->evidence_key}")->assertNotFound();

        // The stranger's own project can't be used to reach this analysis or endpoint.
        $own = Project::factory()->for($elsewhere)->create();
        $this->actingAs($stranger)->get("/projects/{$own->id}/analyses/{$analysis->id}")->assertNotFound();
        $this->actingAs($stranger)->post("/projects/{$own->id}/endpoints/{$this->store->id}/analyses")->assertNotFound();
        $this->actingAs($this->developer)->get("/projects/{$this->project->id}/analyses/{$analysis->id}/evidence/ev-99999")->assertNotFound();
    }

    public function test_the_flag_off_hides_analysis_routes_but_keeps_the_endpoint_page(): void
    {
        config(['features.endpoint_analysis' => false]);

        $this->actingAs($this->developer)->post($this->analyzeUrl())->assertNotFound();
        $this->get("/projects/{$this->project->id}/endpoints/{$this->store->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('analysisEnabled', false)->where('analysis', null));
    }

    public function test_a_malformed_result_is_never_stored_as_complete(): void
    {
        Queue::fake();
        $this->actingAs($this->developer)->post($this->analyzeUrl());
        $analysis = EndpointAnalysis::query()->sole();

        $broken = Mockery::mock(EndpointAnalyzer::class);
        $broken->shouldReceive('analyze')->andReturn(['schema_version' => '1.0', 'status' => 'completed']);
        (new EndpointAnalyses($broken))->process($analysis->id);

        $analysis->refresh();
        $this->assertSame(RunStatus::Failed, $analysis->status);
        $this->assertSame('invalid_result', $analysis->failure_code);
        $this->assertNull($analysis->result);

        // Asking again queues the failed analysis once more.
        $this->actingAs($this->developer)->post($this->analyzeUrl());
        $this->assertSame(RunStatus::Queued, $analysis->refresh()->status);
        Queue::assertPushed(AnalyzeEndpointJob::class, 2);
    }

    public function test_partial_analysis_records_findings_for_unknowns(): void
    {
        $dynamic = ApiEndpoint::factory()->create([
            'route_scan_id' => $this->store->route_scan_id, 'controller' => 'Fixture\App\Http\Controllers\OrderController',
            'action' => 'dynamic', 'uri' => '/api/dynamic', 'route_identity' => hash('sha256', 'dynamic'),
        ]);

        $this->actingAs($this->developer)->post($this->analyzeUrl($dynamic));
        $analysis = EndpointAnalysis::query()->where('api_endpoint_id', $dynamic->id)->sole();

        $this->assertSame(RunStatus::Partial, $analysis->status);
        $this->assertGreaterThan(0, $analysis->unresolved_count);
        $this->assertSame($analysis->unresolved_count, AnalysisFinding::query()->where('endpoint_analysis_id', $analysis->id)->count());
    }

    public function test_archived_projects_cannot_start_analyses(): void
    {
        $this->project->forceFill(['status' => ProjectStatus::Archived, 'archived_at' => now()])->save();

        $this->actingAs($this->developer)->post($this->analyzeUrl());
        $this->assertSame(0, EndpointAnalysis::query()->count());
    }

    public function test_deleting_the_project_removes_its_analyses(): void
    {
        $this->actingAs($this->developer)->post($this->analyzeUrl());
        $owner = $this->memberOf(WorkspaceRole::Owner, $this->project->workspace);

        app(ProjectDeleter::class)->delete($owner, $this->project);

        $this->assertSame(0, EndpointAnalysis::query()->count());
        $this->assertSame(0, AnalysisEvidence::query()->count());
        $this->assertSame(0, AnalysisFinding::query()->count());
    }

    public function test_a_rescan_that_resolves_the_handler_differently_gets_a_fresh_analysis(): void
    {
        $this->actingAs($this->developer)->post($this->analyzeUrl());

        // Same snapshot and route identity, but a newer discovery resolved another handler.
        $rescan = RouteScan::factory()->create(['source_snapshot_id' => $this->store->source_snapshot_id, 'analyzer_version' => 'route-analyzer-v99']);
        $moved = ApiEndpoint::factory()->create([
            'route_scan_id' => $rescan->id, 'route_identity' => $this->store->route_identity, 'uri' => $this->store->uri,
            'methods' => $this->store->methods, 'controller' => $this->store->controller, 'action' => 'legacy',
        ]);
        $this->actingAs($this->developer)->post($this->analyzeUrl($moved));

        $this->assertSame(2, EndpointAnalysis::query()->count());
        $this->assertNotSame(EndpointAnalyses::key($this->store), EndpointAnalyses::key($moved));
    }

    public function test_a_running_analysis_blocks_project_deletion(): void
    {
        Queue::fake();
        $this->actingAs($this->developer)->post($this->analyzeUrl());
        EndpointAnalysis::query()->update(['status' => RunStatus::Running->value, 'claimed_at' => now()]);
        $owner = $this->memberOf(WorkspaceRole::Owner, $this->project->workspace);

        try {
            app(ProjectDeleter::class)->delete($owner, $this->project);
            $this->fail('deletion should be refused while an analysis runs');
        } catch (ProjectError $e) {
            $this->assertSame('project.busy', $e->errorCode());
        }
        $this->assertTrue(Project::query()->whereKey($this->project->id)->exists());

        // An abandoned claim (older than the job timeout) no longer blocks.
        EndpointAnalysis::query()->update(['claimed_at' => now()->subSeconds(EndpointAnalyses::JOB_TIMEOUT + 5)]);
        app(ProjectDeleter::class)->delete($owner, $this->project);
        $this->assertFalse(Project::query()->whereKey($this->project->id)->exists());
    }

    public function test_the_explorer_shows_and_filters_auth_from_analyses(): void
    {
        $this->actingAs($this->developer)->post($this->analyzeUrl());
        $explorer = fn (string $q = '') => $this->get("/projects/{$this->project->id}/endpoints{$q}");
        $total = ApiEndpoint::query()->where('route_scan_id', $this->store->route_scan_id)->count();

        $explorer()->assertOk()->assertInertia(fn ($page) => $page
            ->where('analysisEnabled', true)
            ->where('endpoints.total', $total)
            ->where('endpoints.data', fn ($rows) => collect($rows)->firstWhere('id', $this->store->id)['analysisAuth'] === 'required'
                && collect($rows)->where('id', '!=', $this->store->id)->every(fn ($r) => $r['analysisAuth'] === null)));

        $explorer('?auth=required')->assertInertia(fn ($page) => $page->where('endpoints.total', 1)->where('endpoints.data.0.id', $this->store->id));
        $explorer('?auth=not_analyzed')->assertInertia(fn ($page) => $page->where('endpoints.total', $total - 1));
        $explorer('?auth=not_required')->assertInertia(fn ($page) => $page->where('endpoints.total', 0));
        $explorer('?auth=bogus')->assertInertia(fn ($page) => $page->where('endpoints.total', $total));

        // Auth status is not source: QA sees it too.
        $qa = $this->memberOf(WorkspaceRole::QA, $this->project->workspace);
        $this->actingAs($qa)->get("/projects/{$this->project->id}/endpoints?auth=required")->assertInertia(fn ($page) => $page->where('endpoints.total', 1));

        // A result from an older analyzer is stale: it neither shows nor filters.
        EndpointAnalysis::query()->update(['analyzer_version' => 'laravel-ast-v1']);
        $this->actingAs($this->developer);
        $explorer('?auth=required')->assertInertia(fn ($page) => $page->where('endpoints.total', 0));
        $explorer('?auth=not_analyzed')->assertInertia(fn ($page) => $page->where('endpoints.total', $total));

        // With analysis off, the explorer falls back to discovery's middleware names.
        config(['features.endpoint_analysis' => false]);
        $explorer()->assertInertia(fn ($page) => $page->where('analysisEnabled', false)->where('endpoints.total', $total)->where('endpoints.data.0.analysisAuth', null));
        $explorer('?auth=required')->assertInertia(fn ($page) => $page->where('endpoints.total', 0)); // route has no auth middleware by name
    }

    public function test_a_result_from_an_older_analyzer_is_never_stored_under_the_new_version(): void
    {
        Queue::fake();
        $this->actingAs($this->developer)->post($this->analyzeUrl());
        $analysis = EndpointAnalysis::query()->sole();

        // A worker still running old code produces a valid result stamped with its own (older) version.
        $old = app(EndpointAnalyzer::class)->analyze($analysis->snapshot, $this->store, $analysis->application_root);
        $old['analyzer_version'] = 'laravel-ast-v1';
        $outdated = Mockery::mock(EndpointAnalyzer::class);
        $outdated->shouldReceive('analyze')->andReturn($old);
        (new EndpointAnalyses($outdated))->process($analysis->id);

        $analysis->refresh();
        $this->assertSame(RunStatus::Failed, $analysis->status);
        $this->assertSame('analyzer_mismatch', $analysis->failure_code);
        $this->assertNull($analysis->result);
    }

    public function test_a_stale_stored_result_is_offered_and_requeued(): void
    {
        Queue::fake();
        $this->actingAs($this->developer)->post($this->analyzeUrl());
        $analysis = EndpointAnalysis::query()->sole();
        $result = app(EndpointAnalyzer::class)->analyze($analysis->snapshot, $this->store, $analysis->application_root);
        $result['analyzer_version'] = 'laravel-ast-v5';
        unset($result['status'], $result['contract']);
        $analysis->forceFill(['status' => RunStatus::Completed, 'result' => $result])->save();

        $this->get("/projects/{$this->project->id}/endpoints/{$this->store->id}")
            ->assertInertia(fn ($page) => $page->where('analysis.stale', true));

        $this->actingAs($this->developer)->post($this->analyzeUrl());
        $this->assertSame(RunStatus::Queued, $analysis->refresh()->status);
        Queue::assertPushed(AnalyzeEndpointJob::class, 2);
    }

    public function test_reanalyze_runs_a_finished_analysis_again(): void
    {
        Queue::fake();
        $this->actingAs($this->developer)->post($this->analyzeUrl());
        $analysis = EndpointAnalysis::query()->sole();
        $analysis->forceFill(['status' => RunStatus::Completed, 'result' => ['analyzer_version' => EndpointAnalyzer::VERSION]])->save();

        // A plain click returns the finished analysis; Reanalyze queues it again.
        $this->actingAs($this->developer)->post($this->analyzeUrl());
        $this->assertSame(RunStatus::Completed, $analysis->refresh()->status);

        $this->actingAs($this->developer)->post($this->analyzeUrl(), ['reanalyze' => true])->assertSessionHasNoErrors();
        $this->assertSame(RunStatus::Queued, $analysis->refresh()->status);
        Queue::assertPushed(AnalyzeEndpointJob::class, 2);
        $this->assertSame(1, EndpointAnalysis::query()->count(), 'the same row is re-run, not duplicated');

        $qa = $this->memberOf(WorkspaceRole::QA, $this->project->workspace);
        $this->actingAs($qa)->post($this->analyzeUrl(), ['reanalyze' => true])->assertForbidden();
    }

    public function test_analysis_job_timeout_fits_inside_the_worker_timeout(): void
    {
        $this->assertLessThan((int) config('horizon.defaults.supervisor-1.timeout'), EndpointAnalyses::JOB_TIMEOUT);
        $this->assertContains('analysis', config('horizon.defaults.supervisor-1.queue'));
    }
}
