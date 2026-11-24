<?php

namespace Tests\Feature\Docs;

use App\Domain\Docs\StaticDocumentationBuilder;
use App\Domain\Projects\ProjectDeleter;
use App\Domain\Projects\ProjectStatus;
use App\Domain\Sources\RunStatus;
use App\Domain\Workspaces\WorkspaceRole;
use App\Models\ApiEndpoint;
use App\Models\DocumentationVersion;
use App\Models\EndpointAnalysis;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AnalyzesFixtures;
use Tests\Concerns\BuildsSnapshots;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

/** RG-03: snapshot → detect → discover → analyze → static docs, with no AI anywhere. */
class StaticDocumentationFlowTest extends TestCase
{
    use AnalyzesFixtures, BuildsSnapshots, RefreshDatabase, WorksWithWorkspaces;

    private Project $project;

    private User $developer;

    private ApiEndpoint $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        config(['features.project_wizard' => true, 'features.source_snapshots' => true, 'features.framework_detection' => true, 'features.route_discovery' => true, 'features.endpoint_analysis' => true, 'features.static_docs' => true, 'app.debug' => false]);
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

    private function analyze(?ApiEndpoint $endpoint = null): EndpointAnalysis
    {
        $endpoint ??= $this->store;
        $this->actingAs($this->developer)->post("/projects/{$this->project->id}/endpoints/{$endpoint->id}/analyses")->assertRedirect();

        return EndpointAnalysis::query()->where('api_endpoint_id', $endpoint->id)->sole();
    }

    private function generateUrl(EndpointAnalysis $analysis): string
    {
        return "/projects/{$this->project->id}/analyses/{$analysis->id}/documentation";
    }

    private function showUrl(DocumentationVersion $doc): string
    {
        return "/projects/{$this->project->id}/documentation/{$doc->id}";
    }

    public function test_zip_to_analysis_to_static_docs_end_to_end(): void
    {
        $analysis = $this->analyze();
        $this->assertSame(RunStatus::Completed, $analysis->status);

        $response = $this->actingAs($this->developer)->post($this->generateUrl($analysis));
        $doc = DocumentationVersion::query()->sole();
        $response->assertRedirect($this->showUrl($doc));

        $this->assertSame($analysis->source_snapshot_id, $doc->source_snapshot_id);
        $this->assertSame($analysis->analyzer_version, $doc->analyzer_version);
        $this->assertSame(StaticDocumentationBuilder::VERSION, $doc->builder_version);
        $this->assertSame(StaticDocumentationBuilder::checksum($analysis->result), $doc->contract_checksum);

        $this->get($this->showUrl($doc))->assertOk()->assertInertia(fn ($page) => $page
            ->component('projects/documentation')
            ->where('documentation.analysisId', $analysis->id)
            ->where('documentation.snapshotId', $analysis->source_snapshot_id)
            ->where('documentation.snapshotHash', $analysis->snapshot->sha256)
            ->where('documentation.document.overview.uri', '/api/orders')
            ->where('documentation.document.overview.methods', ['POST'])
            ->where('documentation.document.overview.auth.status', 'required')
            ->where('documentation.document.curl.source', 'contract')
            ->where('documentation.document.curl.command', fn ($curl) => str_contains($curl, "-H 'Authorization: Bearer <token>'") && str_contains($curl, '{{base_url}}/api/orders'))
            ->where('documentation.document.headers.0.name', 'Authorization')
            ->where('documentation.document.validation.0.name', 'customer_id')
            ->where('documentation.document.request.payload.location', 'multipart')
            ->where('documentation.document.responses.success.0.status', 201)
            ->where('documentation.document.responses.success.0.notes.0.source', 'unread')
            ->where('documentation.document.responses.errors', fn ($errors) => collect($errors)->contains('status', 401) && collect($errors)->contains('status', 422))
            ->missing('documentation.document.evidence')
            ->where('documentation.document', fn ($d) => ! str_contains(json_encode($d), '"evidence"'))
            ->where('documentation.document.notices', [])
            ->where('documentation.markdown', fn ($md) => str_contains($md, '# POST /api/orders') && str_contains($md, "## cURL\n") && str_contains($md, '## Unknowns') && ! str_contains($md, '## Evidence'))
            ->has('drafts', 1));

        // The endpoint's documentation link opens the newest draft.
        $this->get("/projects/{$this->project->id}/endpoints/{$this->store->id}/documentation")->assertOk()
            ->assertInertia(fn ($page) => $page->where('documentation.id', $doc->id)->where('endpointId', $this->store->id));
    }

    public function test_documentation_never_shows_evidence_even_in_older_drafts(): void
    {
        $this->actingAs($this->developer)->post($this->generateUrl($this->analyze()));
        $doc = DocumentationVersion::query()->sole();
        // A draft built by static-docs-v1 stored evidence; it must not be shown any more.
        $document = $doc->document;
        $document['evidence'] = [['id' => 'ev-1', 'path' => 'app/Http/Controllers/OrderController.php', 'lines' => [3, 9]]];
        $document['validation'][0]['evidence'] = ['ev-1'];
        $doc->forceFill(['document' => $document])->save();

        $this->get($this->showUrl($doc))->assertOk()->assertInertia(fn ($page) => $page
            ->missing('documentation.document.evidence')
            ->where('documentation.document', fn ($d) => ! str_contains(json_encode($d), '"evidence"'))
            ->where('documentation.markdown', fn ($md) => ! str_contains($md, 'Evidence') && ! str_contains($md, 'ev-1')));
    }

    public function test_regenerating_after_a_reanalysis_makes_a_new_draft_and_keeps_the_old(): void
    {
        $analysis = $this->analyze();
        $endpointUrl = "/projects/{$this->project->id}/endpoints/{$analysis->api_endpoint_id}";
        $this->actingAs($this->developer)->get($endpointUrl)->assertInertia(fn ($page) => $page->where('docsState', 'none'));

        $this->actingAs($this->developer)->post($this->generateUrl($analysis));
        $first = DocumentationVersion::query()->sole();
        $this->get($endpointUrl)->assertInertia(fn ($page) => $page->where('docsState', 'current'));

        // Unchanged result: regenerating returns the same draft.
        $this->post($this->generateUrl($analysis))->assertRedirect($this->showUrl($first));
        $this->assertSame(1, DocumentationVersion::query()->count());

        // A re-analysis rewrote the result (here: one more unresolved note).
        $result = $analysis->result;
        $result['unresolved'][] = 'A value found on the second read.';
        $analysis->forceFill(['result' => $result])->save();
        $this->get($endpointUrl)->assertInertia(fn ($page) => $page->where('docsState', 'outdated'));

        $this->post($this->generateUrl($analysis));
        $this->assertSame(2, DocumentationVersion::query()->count());
        $second = DocumentationVersion::query()->latest('id')->where('id', '!=', $first->id)->sole();
        $this->assertNotSame($first->contract_checksum, $second->contract_checksum);
        $this->assertContains('A value found on the second read.', $second->document['unknowns']);
        $this->assertNotContains('A value found on the second read.', $first->refresh()->document['unknowns'], 'the old draft is untouched');
        $this->get($endpointUrl)->assertInertia(fn ($page) => $page->where('docsState', 'current'));
        $this->get($this->showUrl($second))->assertInertia(fn ($page) => $page->where('canGenerate', true)->has('drafts', 2));
    }

    public function test_generating_again_returns_the_same_draft(): void
    {
        $analysis = $this->analyze();

        $first = $this->actingAs($this->developer)->post($this->generateUrl($analysis))->headers->get('Location');
        $second = $this->actingAs($this->developer)->post($this->generateUrl($analysis))->headers->get('Location');

        $this->assertSame($first, $second);
        $this->assertSame(1, DocumentationVersion::query()->count());
    }

    public function test_an_old_draft_stays_unchanged_after_a_newer_analysis_gets_its_own(): void
    {
        $analysis = $this->analyze();
        $this->actingAs($this->developer)->post($this->generateUrl($analysis));
        $old = DocumentationVersion::query()->sole();
        $before = $this->get($this->showUrl($old))->viewData('page')['props']['documentation'];

        // A newer analyzer version analyzed the same route again (its result is an older-style one, to vary it).
        $result = $analysis->result;
        unset($result['contract']);
        $result['analyzer_version'] = 'laravel-ast-v99';
        $newer = EndpointAnalysis::factory()->create([
            'api_endpoint_id' => $this->store->id, 'analyzer_version' => 'laravel-ast-v99', 'status' => RunStatus::Completed, 'result' => $result,
        ]);
        $this->actingAs($this->developer)->post($this->generateUrl($newer))->assertRedirect();

        $this->assertSame(2, DocumentationVersion::query()->count());
        $this->assertSame($before, $this->get($this->showUrl($old))->viewData('page')['props']['documentation']);
        $this->get("/projects/{$this->project->id}/endpoints/{$this->store->id}/documentation")->assertInertia(fn ($page) => $page
            ->where('documentation.analysisId', $newer->id)
            ->where('documentation.document.notices', ['legacy_result'])
            ->where('drafts.1.id', $old->id)
            ->has('drafts', 2));
    }

    public function test_a_partial_analysis_gives_partial_docs_with_a_warning(): void
    {
        $dynamic = ApiEndpoint::factory()->create([
            'route_scan_id' => $this->store->route_scan_id, 'controller' => 'Fixture\App\Http\Controllers\OrderController',
            'action' => 'dynamic', 'uri' => '/api/dynamic', 'route_identity' => hash('sha256', 'dynamic-docs'),
        ]);
        $analysis = $this->analyze($dynamic);
        $this->assertSame(RunStatus::Partial, $analysis->status);

        $this->actingAs($this->developer)->post($this->generateUrl($analysis));
        $doc = DocumentationVersion::query()->sole();

        $this->assertContains('partial', $doc->document['notices']);
        $this->assertNotEmpty($doc->document['unknowns']);
        $this->assertMatchesRegularExpression('/\(line \d+\)/', implode(' ', $doc->document['unknowns']), 'source readers keep the lines');

        $qa = $this->memberOf(WorkspaceRole::QA, $this->project->workspace);
        $unknowns = $this->actingAs($qa)->get($this->showUrl($doc))->viewData('page')['props']['documentation']['document']['unknowns'];
        $this->assertNotEmpty($unknowns);
        $this->assertDoesNotMatchRegularExpression('/line \d+/', implode(' ', $unknowns));
    }

    public function test_qa_reads_docs_without_source_locations_and_cannot_generate(): void
    {
        $analysis = $this->analyze();
        $this->actingAs($this->developer)->post($this->generateUrl($analysis));
        $doc = DocumentationVersion::query()->sole();

        foreach ([WorkspaceRole::QA, WorkspaceRole::Viewer] as $role) {
            $reader = $this->memberOf($role, $this->project->workspace);
            $props = $this->actingAs($reader)->get($this->showUrl($doc))->assertOk()->viewData('page')['props'];

            $this->assertFalse($props['canViewSource']);
            $this->assertNull($props['documentation']['checksum']);
            $this->assertNull($props['documentation']['snapshotHash']);
            $this->assertArrayNotHasKey('evidence', $props['documentation']['document']);
            $this->assertArrayNotHasKey('evidence', $props['documentation']['document']['validation'][0]);
            $raw = json_encode($props['documentation'], JSON_UNESCAPED_SLASHES);
            $this->assertStringNotContainsString('.php', $raw);
            $this->assertStringNotContainsString('sha256', $raw);
            $this->assertDoesNotMatchRegularExpression('/"ev-\d+"/', $raw);
            $this->assertDoesNotMatchRegularExpression('/\bline \d+/', $raw);
            $this->assertStringNotContainsString('## Evidence', $props['documentation']['markdown']);
            $this->assertSame($doc->document['curl'], $props['documentation']['document']['curl'], 'the contract itself is the same for every role');

            $this->actingAs($reader)->post($this->generateUrl($analysis))->assertForbidden();
        }
        $this->assertSame(1, DocumentationVersion::query()->count());
    }

    public function test_other_workspaces_and_other_projects_get_a_uniform_404(): void
    {
        $analysis = $this->analyze();
        $this->actingAs($this->developer)->post($this->generateUrl($analysis));
        $doc = DocumentationVersion::query()->sole();

        $elsewhere = Workspace::factory()->create();
        $stranger = $this->memberOf(WorkspaceRole::Owner, $elsewhere);
        $this->actingAs($stranger)->get($this->showUrl($doc))->assertNotFound();
        $this->actingAs($stranger)->post($this->generateUrl($analysis))->assertNotFound();
        $this->actingAs($stranger)->get("/projects/{$this->project->id}/endpoints/{$this->store->id}/documentation")->assertNotFound();

        $own = Project::factory()->for($elsewhere)->create();
        $this->actingAs($stranger)->get("/projects/{$own->id}/documentation/{$doc->id}")->assertNotFound();
        $this->actingAs($stranger)->post("/projects/{$own->id}/analyses/{$analysis->id}/documentation")->assertNotFound();
        $this->actingAs($stranger)->get("/projects/{$own->id}/endpoints/{$this->store->id}/documentation")->assertNotFound();
        $this->assertSame(1, DocumentationVersion::query()->count());
    }

    public function test_archived_projects_cannot_generate(): void
    {
        $analysis = $this->analyze();
        $this->project->forceFill(['status' => ProjectStatus::Archived, 'archived_at' => now()])->save();

        $this->actingAs($this->developer)->postJson($this->generateUrl($analysis))->assertStatus(409)->assertJsonPath('error.code', 'project.archived');
        $this->assertSame(0, DocumentationVersion::query()->count());
    }

    public function test_an_unfinished_analysis_cannot_be_documented(): void
    {
        $queued = EndpointAnalysis::factory()->create(['api_endpoint_id' => $this->store->id, 'status' => RunStatus::Queued]);

        $this->actingAs($this->developer)->postJson($this->generateUrl($queued))->assertStatus(409)->assertJsonPath('error.code', 'docs.analysis_not_ready');
        $this->assertSame(0, DocumentationVersion::query()->count());
    }

    public function test_the_flag_off_hides_every_docs_route(): void
    {
        $analysis = $this->analyze();
        $this->actingAs($this->developer)->post($this->generateUrl($analysis));
        $doc = DocumentationVersion::query()->sole();
        config(['features.static_docs' => false]);

        $this->actingAs($this->developer)->post($this->generateUrl($analysis))->assertNotFound();
        $this->get($this->showUrl($doc))->assertNotFound();
        $this->get("/projects/{$this->project->id}/endpoints/{$this->store->id}/documentation")->assertNotFound();
        $this->get("/projects/{$this->project->id}/endpoints/{$this->store->id}")->assertOk();
    }

    public function test_deleting_the_project_removes_its_drafts(): void
    {
        $analysis = $this->analyze();
        $this->actingAs($this->developer)->post($this->generateUrl($analysis));
        $owner = $this->memberOf(WorkspaceRole::Owner, $this->project->workspace);

        app(ProjectDeleter::class)->delete($owner, $this->project);

        $this->assertSame(0, DocumentationVersion::query()->count());
        $this->assertSame(0, EndpointAnalysis::query()->count());
    }
}
