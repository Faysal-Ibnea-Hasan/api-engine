<?php

namespace Tests\Feature\Ai;

use App\Domain\Projects\ProjectDeleter;
use App\Domain\Workspaces\WorkspaceRole;
use App\Models\AiProvider;
use App\Models\AiRun;
use App\Models\EndpointAnalysis;
use App\Models\WorkflowExplanation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

class AiModelsTest extends TestCase
{
    use RefreshDatabase, WorksWithWorkspaces;

    public function test_api_key_is_encrypted_at_rest_and_never_serialized(): void
    {
        $provider = AiProvider::factory()->create(['provider' => 'anthropic', 'api_key' => 'sk-ant-test-1234']);

        $this->assertNotSame('sk-ant-test-1234', DB::table('ai_providers')->where('id', $provider->id)->value('api_key'));
        $this->assertSame('sk-ant-test-1234', $provider->fresh()->api_key);
        $this->assertArrayNotHasKey('api_key', $provider->toArray());
        $this->assertStringNotContainsString('sk-ant', json_encode($provider));
        $this->assertTrue($provider->hasKey());
        $this->assertFalse(AiProvider::for('openai')->hasKey());
    }

    public function test_for_returns_unsaved_row_for_known_provider(): void
    {
        $provider = AiProvider::for('openai');

        $this->assertFalse($provider->exists);
        $this->assertSame('openai', $provider->provider);
    }

    public function test_default_is_the_enabled_default_provider(): void
    {
        AiProvider::factory()->create(['provider' => 'openai', 'is_default' => true, 'enabled' => false]);
        $this->assertNull(AiProvider::default());

        $anthropic = AiProvider::factory()->create(['provider' => 'anthropic', 'is_default' => true, 'enabled' => true]);
        $this->assertTrue(AiProvider::default()?->is($anthropic));
    }

    public function test_project_deletion_removes_explanations_but_keeps_ai_run_counts(): void
    {
        $analysis = EndpointAnalysis::factory()->create(['profile' => 'advanced']);
        $explanation = WorkflowExplanation::factory()->create(['endpoint_analysis_id' => $analysis->id]);
        $this->assertTrue($explanation->isActive());
        AiRun::query()->forceCreate([
            'ai_provider_id' => AiProvider::factory()->create()->id,
            'provider' => 'anthropic', 'model' => 'm', 'effort' => 'medium',
            'project_id' => $analysis->project_id, 'workspace_id' => $analysis->workspace_id,
            'input_tokens' => 500, 'status' => 'succeeded', 'duration_ms' => 10, 'prompt_version' => 'v1',
        ]);
        $project = $analysis->project;

        app(ProjectDeleter::class)->delete($this->memberOf(WorkspaceRole::Owner, $project->workspace), $project);

        $this->assertSame(0, WorkflowExplanation::query()->count());
        $this->assertSame(500, (int) AiRun::query()->sum('input_tokens'));
    }

    public function test_ai_settings_flag_defaults_off(): void
    {
        $this->assertFalse(config('features.ai_settings'));
    }
}
