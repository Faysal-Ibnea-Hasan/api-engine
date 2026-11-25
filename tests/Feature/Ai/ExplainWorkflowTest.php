<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\AiAdapters;
use App\Domain\Ai\AiError;
use App\Domain\Ai\WorkflowContext;
use App\Domain\Ai\WorkflowExplanations;
use App\Domain\Ai\WorkflowPrompt;
use App\Domain\Analysis\EndpointAnalyses;
use App\Domain\Sources\RunStatus;
use App\Domain\Workspaces\WorkspaceRole;
use App\Events\EndpointAnalysisFinished;
use App\Jobs\AnalyzeEndpointJob;
use App\Jobs\ExplainWorkflowJob;
use App\Models\AiProvider;
use App\Models\AiRun;
use App\Models\ApiDependency;
use App\Models\ApiEndpoint;
use App\Models\AuditEvent;
use App\Models\EndpointAnalysis;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkflowExplanation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\BuildsSnapshots;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

/** Trace then explain: the request, the event → listener hand-off and the AI job (spec § 6.5, § 7). */
class ExplainWorkflowTest extends TestCase
{
    use BuildsSnapshots, RefreshDatabase, WorksWithWorkspaces;

    private const CONTROLLER = 'WorkflowFixture\App\Http\Controllers\OrderController';

    private const KEY = 'sk-ant-SECRET-key-4242';

    private const MARKDOWN = "## Overview\nPlaces an order.\n\n## Step by step\n1. Validate.";

    private User $developer;

    private ApiEndpoint $endpoint;

    private EndpointAnalysis $basic;

    private AiProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        config(['features.project_wizard' => true, 'features.source_snapshots' => true, 'features.framework_detection' => true, 'features.route_discovery' => true, 'features.endpoint_analysis' => true, 'features.advanced_analysis' => true, 'features.ai_settings' => true, 'app.debug' => false]);
        Storage::fake('quarantine', ['throw' => true]);
        Storage::fake('snapshots', ['throw' => true]);

        $files = [
            'composer.json' => json_encode(['require' => ['laravel/framework' => '^11.0'], 'autoload' => ['psr-4' => ['WorkflowFixture\\App\\' => 'app/']]]),
            'composer.lock' => json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v11.30.0']]]),
            'artisan' => "<?php\n",
            'bootstrap/app.php' => "<?php\nreturn Application::configure(basePath: dirname(__DIR__))->withRouting(api: __DIR__.'/../routes/api.php')->create();\n",
            'routes/api.php' => "<?php\nuse ".self::CONTROLLER.";\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::post('orders', [OrderController::class, 'store']);\n",
        ];
        foreach (File::allFiles(base_path('tests/Fixtures/workflow/app')) as $file) {
            $files['app/'.$file->getRelativePathname()] = $file->getContents();
        }
        $project = Project::factory()->create();
        $this->developer = $this->memberOf(WorkspaceRole::Developer, $project->workspace);
        $this->snapshotFrom($files, $project, $this->developer);
        $this->endpoint = ApiEndpoint::query()->where('project_id', $project->id)->where('action', 'store')->firstOrFail();
        $this->basic = app(EndpointAnalyses::class)->start($this->developer, $this->endpoint);

        $this->provider = AiProvider::factory()->create([
            'provider' => 'anthropic', 'api_key' => self::KEY, 'is_default' => true,
            'model' => 'claude-test', 'available_models' => [['id' => 'claude-test', 'effort' => true]], 'effort' => 'high',
        ]);
    }

    private function fakeAi(array $body = [], int $status = 200): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($body ?: [
            'content' => [['type' => 'text', 'text' => self::MARKDOWN]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 300],
        ], $status)]);
    }

    private function request(bool $again = false): WorkflowExplanation
    {
        return app(WorkflowExplanations::class)->request($this->developer, $this->basic, $again);
    }

    private function advanced(): ?EndpointAnalysis
    {
        return EndpointAnalysis::query()->where('analysis_key', EndpointAnalyses::key($this->endpoint, EndpointAnalyses::ADVANCED))->first();
    }

    /** A real trace, then a queued explanation whose job is held back. */
    private function queued(): WorkflowExplanation
    {
        Queue::fake([ExplainWorkflowJob::class]);
        $explanation = $this->request();
        $this->assertSame('queued', $explanation->status);

        return $explanation;
    }

    /** Runs the job as the worker would on attempt $attempt. */
    private function runJob(WorkflowExplanation $explanation, int $attempt = 1): void
    {
        $job = (new ExplainWorkflowJob($explanation->id))->withFakeQueueInteractions();
        $job->job->attempts = $attempt;
        app()->call([$job, 'handle']);
    }

    private function aiRun(int $input, int $output, string $status = 'succeeded'): void
    {
        (new AiRun)->forceFill([
            'ai_provider_id' => $this->provider->id, 'provider' => 'anthropic', 'model' => 'claude-test', 'effort' => 'high',
            'input_tokens' => $input, 'output_tokens' => $output, 'status' => $status, 'duration_ms' => 1, 'prompt_version' => WorkflowPrompt::VERSION,
        ])->save();
    }

    /** The rethrown exception is reported by the worker: neither its message nor its stack frames may carry the key. */
    private function assertKeyNotIn(\Throwable $e): void
    {
        $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        $this->assertStringNotContainsString(self::KEY, json_encode(array_column($e->getTrace(), 'args')) ?: '');
        $this->assertStringNotContainsString(substr(self::KEY, 0, 12), (string) $e);
        $this->assertNull($e->getPrevious());
    }

    public function test_request_runs_trace_then_explanation_and_stores_markdown(): void
    {
        $this->fakeAi();

        $explanation = $this->request()->refresh();

        $advanced = $this->advanced();
        $this->assertSame(RunStatus::Completed, $advanced->status);
        $this->assertSame($advanced->id, $explanation->endpoint_analysis_id);
        $this->assertSame('completed', $explanation->status);
        $this->assertStringContainsString('## Overview', $explanation->markdown);
        $this->assertSame(WorkflowPrompt::VERSION, $explanation->prompt_version);
        $this->assertSame(['anthropic', 'claude-test', 'high'], [$explanation->provider, $explanation->model, $explanation->effort]);
        $this->assertSame($this->developer->id, $explanation->requested_by);
        $this->assertFalse($explanation->truncated);
        $this->assertSame([], $explanation->omitted);
        $this->assertNotNull($explanation->finished_at);

        $run = AiRun::query()->sole();
        $this->assertSame([$run->id, 'succeeded', 1200, 300], [$explanation->ai_run_id, $run->status, $run->input_tokens, $run->output_tokens]);
        $this->assertSame([$advanced->id, $advanced->project_id, $this->developer->id], [$run->endpoint_analysis_id, $run->project_id, $run->user_id]);

        Http::assertSent(fn (Request $r) => $r['model'] === 'claude-test' && $r['max_tokens'] === 16000 && $r['output_config'] === ['effort' => 'high'] && $r['system'] === WorkflowPrompt::system());

        $audit = AuditEvent::query()->where('event_type', 'ai.workflow_explained')->sole();
        $this->assertSame($advanced->workspace_id, $audit->workspace_id);
        $metadata = $audit->metadata;
        ksort($metadata); // MySQL JSON columns reorder keys
        $this->assertSame(['analysis_id' => $advanced->id, 'input_units' => 1200, 'model' => 'claude-test', 'output_units' => 300, 'provider' => 'anthropic'], $metadata);
    }

    public function test_effort_is_omitted_for_a_model_without_it(): void
    {
        $this->provider->forceFill(['available_models' => [['id' => 'claude-test', 'effort' => false]]])->save();
        $this->fakeAi();

        $this->assertSame('completed', $this->request()->refresh()->status);
        Http::assertSent(fn (Request $r) => ! array_key_exists('output_config', $r->data()));
    }

    public function test_existing_fresh_trace_is_reused(): void
    {
        $advanced = app(EndpointAnalyses::class)->start($this->developer, $this->endpoint, profile: EndpointAnalyses::ADVANCED)->refresh();
        $this->assertSame(RunStatus::Completed, $advanced->status);
        Queue::fake([AnalyzeEndpointJob::class, ExplainWorkflowJob::class]);

        $explanation = $this->request();

        $this->assertSame('queued', $explanation->status);
        Queue::assertNotPushed(AnalyzeEndpointJob::class);
        Queue::assertPushed(ExplainWorkflowJob::class, fn (ExplainWorkflowJob $job) => $job->explanationId === $explanation->id);
        $this->assertSame(0, AuditEvent::query()->where('event_type', 'analysis.reanalyzed')->count());
        $this->assertSame([$advanced->attempts, (string) $advanced->finished_at], [$this->advanced()->attempts, (string) $this->advanced()->finished_at]);
    }

    public function test_explain_again_reruns_the_trace(): void
    {
        $this->fakeAi();
        $this->request();
        Queue::fake([AnalyzeEndpointJob::class, ExplainWorkflowJob::class]);

        $again = $this->request(again: true);

        Queue::assertPushed(AnalyzeEndpointJob::class);
        Queue::assertNotPushed(ExplainWorkflowJob::class);
        $this->assertSame('tracing', $again->status);
        $this->assertSame([null, null, null], [$again->markdown, $again->failure_code, $again->finished_at]);
        $this->assertSame(1, WorkflowExplanation::query()->count());
    }

    public function test_second_request_while_active_returns_same_row(): void
    {
        $first = $this->queued();

        $second = $this->request(again: true);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('queued', $second->status);
        $this->assertSame(1, WorkflowExplanation::query()->count());
        Queue::assertPushedTimes(ExplainWorkflowJob::class, 1);
    }

    public function test_lost_tracing_job_recovers(): void
    {
        $this->fakeAi();
        Queue::fake([AnalyzeEndpointJob::class]);
        $explanation = $this->request();
        $this->assertSame('tracing', $explanation->status);
        Queue::assertPushedTimes(AnalyzeEndpointJob::class, 1);

        // The trace job was lost: P10's recovery re-dispatches a trace queued for longer than a job may run.
        EndpointAnalysis::query()->whereKey($this->advanced()->id)->update(['updated_at' => now()->subSeconds(EndpointAnalyses::JOB_TIMEOUT + 1)]);
        $this->assertSame($explanation->id, $this->request()->id);
        Queue::assertPushedTimes(AnalyzeEndpointJob::class, 2);

        // The trace finished but its event was missed: the next request hands it to the AI step.
        $advanced = $this->advanced();
        Event::fakeFor(fn () => app(EndpointAnalyses::class)->process($advanced->id), [EndpointAnalysisFinished::class]);
        $this->assertSame(RunStatus::Completed, $advanced->refresh()->status);
        $this->assertSame('tracing', $explanation->refresh()->status);

        $this->assertSame('completed', $this->request()->status);
        Http::assertSentCount(1);
        $this->assertSame(1, WorkflowExplanation::query()->count());
    }

    public function test_stale_queued_row_is_restarted(): void
    {
        $explanation = $this->queued();
        Queue::assertPushedTimes(ExplainWorkflowJob::class, 1);

        $this->travel(1901)->seconds();
        $again = $this->request();

        $this->assertSame([$explanation->id, 'queued'], [$again->id, $again->status]);
        $this->assertTrue($again->updated_at->gt($explanation->updated_at));
        Queue::assertPushedTimes(ExplainWorkflowJob::class, 2);
    }

    public function test_fresh_queued_row_is_returned_without_dispatch(): void
    {
        $explanation = $this->queued();

        $this->travel(1800)->seconds();
        $again = $this->request(again: true);

        $this->assertSame([$explanation->id, 'queued', (string) $explanation->updated_at], [$again->id, $again->status, (string) $again->updated_at]);
        Queue::assertPushedTimes(ExplainWorkflowJob::class, 1);
    }

    public function test_gate_unavailable_refuses_request_without_queueing(): void
    {
        Queue::fake();
        $this->provider->forceFill(['status' => 'invalid_credentials'])->save();

        try {
            $this->request();
            $this->fail('expected the gate to refuse');
        } catch (AiError $e) {
            $this->assertSame('ai.unavailable', $e->errorCode());
            $this->assertStringContainsString('Anthropic is not ready', $e->getMessage());
        }

        $this->provider->forceFill(['status' => 'ready', 'limit_tokens' => 100])->save();
        $this->aiRun(90, 20);

        try {
            $this->request();
            $this->fail('expected the limit to refuse');
        } catch (AiError $e) {
            $this->assertSame('ai.limit_reached', $e->errorCode());
        }

        Queue::assertNothingPushed();
        $this->assertSame(0, WorkflowExplanation::query()->count());
        $this->assertNull($this->advanced());
    }

    public function test_key_changed_while_queued_fails_without_http_call(): void
    {
        $explanation = $this->queued();
        $this->provider->forceFill(['api_key' => 'sk-ant-new', 'key_version' => 2])->save();
        Http::fake();

        $this->runJob($explanation);

        $explanation->refresh();
        $this->assertSame(['failed', 'ai.key_changed'], [$explanation->status, $explanation->failure_code]);
        $this->assertNotNull($explanation->finished_at);
        Http::assertNothingSent();
        $this->assertSame(0, AiRun::query()->count());
    }

    public function test_provider_disabled_while_queued_fails_unavailable(): void
    {
        $explanation = $this->queued();
        Http::fake();
        $this->provider->forceFill(['enabled' => false])->save();

        $this->runJob($explanation);

        $this->assertSame(['failed', 'ai.unavailable'], [$explanation->refresh()->status, $explanation->failure_code]);

        // Another provider became the default while it waited: the row's provider is no longer the one to use.
        $explanation->forceFill(['status' => 'queued', 'failure_code' => null])->save();
        $this->provider->forceFill(['enabled' => true, 'is_default' => false])->save();
        AiProvider::factory()->create(['provider' => 'openai', 'is_default' => true]);

        $this->runJob($explanation);

        $this->assertSame(['failed', 'ai.unavailable'], [$explanation->refresh()->status, $explanation->failure_code]);
        Http::assertNothingSent();
        $this->assertSame(0, AiRun::query()->count());
    }

    public function test_limit_reached_in_worker_fails_without_call(): void
    {
        $explanation = $this->queued();
        Http::fake();
        $this->provider->forceFill(['limit_tokens' => 100])->save();
        $this->aiRun(100, 50);

        $this->runJob($explanation);

        $this->assertSame(['failed', 'ai.limit_reached'], [$explanation->refresh()->status, $explanation->failure_code]);
        Http::assertNothingSent();
        $this->assertSame(1, AiRun::query()->count());
    }

    public function test_rate_limited_retries_then_fails(): void
    {
        $explanation = $this->queued();
        $this->fakeAi(['error' => ['type' => 'rate_limit_error']], 429);
        $job = new ExplainWorkflowJob($explanation->id);
        $this->assertSame(['ai', 3, [30, 120], 600], [$job->queue, $job->tries, $job->backoff, $job->timeout]);
        $this->assertTrue($job->afterCommit);

        foreach ([1, 2] as $attempt) {
            try {
                $this->runJob($explanation, $attempt);
                $this->fail('a retryable failure must be rethrown so the queue retries');
            } catch (AiError $e) {
                $this->assertSame('ai.rate_limited', $e->errorCode());
                $this->assertKeyNotIn($e);
            }
            $this->assertSame(['queued', null], [$explanation->refresh()->status, $explanation->failure_code]);
        }

        $this->runJob($explanation, 3);

        $this->assertSame(['failed', 'ai.rate_limited'], [$explanation->refresh()->status, $explanation->failure_code]);
        $this->assertSame(3, AiRun::query()->where('status', 'failed')->where('failure_code', 'ai.rate_limited')->count());
        $this->assertSame('ready', $this->provider->refresh()->status, 'a rate limit does not change the provider status');
    }

    public function test_failed_job_marks_active_row_unavailable(): void
    {
        $explanation = $this->queued();

        (new ExplainWorkflowJob($explanation->id))->failed(null);

        $this->assertSame(['failed', 'ai.internal'], [$explanation->refresh()->status, $explanation->failure_code]);

        $explanation->forceFill(['status' => 'completed', 'failure_code' => null])->save();
        (new ExplainWorkflowJob($explanation->id))->failed(null);
        $this->assertSame('completed', $explanation->refresh()->status, 'a finished row is left alone');
    }

    /** The prompt-size estimate the explainer meters when the provider reports no input usage. */
    private function inputEstimate(WorkflowExplanation $explanation): int
    {
        return intdiv(strlen(WorkflowPrompt::system().WorkflowContext::build($explanation->analysis)['user']), 4);
    }

    public function test_unexpected_error_after_the_provider_answered_is_metered_and_not_retried(): void
    {
        $explanation = $this->queued();
        $this->fakeAi();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });
        AuditEvent::creating(fn (AuditEvent $e) => $e->event_type === 'ai.workflow_explained' ? throw new RuntimeException('audit down') : null);

        $this->runJob($explanation); // attempt 1 of 3: nothing may escape to the queue

        Http::assertSentCount(1);
        $this->assertSame(['failed', 'ai.internal', null, null], [$explanation->refresh()->status, $explanation->failure_code, $explanation->markdown, $explanation->ai_run_id]);
        $run = AiRun::query()->sole();
        $this->assertSame(['failed', 'ai.internal', 1200, 300], [$run->status, $run->failure_code, $run->input_tokens, $run->output_tokens]);
        $this->assertSame('Something went wrong while writing the explanation. Run it again.', AiError::of('ai.internal')->getMessage());
        $this->assertSame([500, false, null], [AiError::of('ai.internal')->status(), AiError::of('ai.internal')->retryable(), AiError::of('ai.internal')->providerStatus()]);
        $this->assertNotEmpty(array_filter($logged, fn ($l) => str_contains($l, 'audit down')), 'the unexpected error is logged');
        foreach ($logged as $record) {
            $this->assertStringNotContainsString(self::KEY, $record);
        }
    }

    public function test_timeout_is_metered_with_an_input_estimate(): void
    {
        $explanation = $this->queued();
        Http::fake(['api.anthropic.com/*' => Http::failedConnection()]);

        $this->runJob($explanation, 3);

        $this->assertSame(['failed', 'ai.provider_unavailable'], [$explanation->refresh()->status, $explanation->failure_code]);
        $run = AiRun::query()->sole();
        $this->assertGreaterThan(0, $this->inputEstimate($explanation));
        $this->assertSame(['failed', $this->inputEstimate($explanation), 0], [$run->status, $run->input_tokens, $run->output_tokens]);
        $this->assertSame(540, config('services.ai.timeout'));
    }

    public function test_missing_usage_is_metered_with_an_input_estimate(): void
    {
        $explanation = $this->queued();
        $this->fakeAi(['content' => [['type' => 'text', 'text' => self::MARKDOWN]], 'stop_reason' => 'end_turn']);

        $this->runJob($explanation);

        $this->assertSame('completed', $explanation->refresh()->status);
        $run = AiRun::query()->sole();
        $this->assertSame(['succeeded', $this->inputEstimate($explanation), 0], [$run->status, $run->input_tokens, $run->output_tokens]);
    }

    public function test_invalid_key_sets_provider_status_and_fails_once(): void
    {
        $explanation = $this->queued();
        $this->fakeAi(['error' => ['type' => 'authentication_error']], 401);

        $this->runJob($explanation);

        $this->assertSame(['failed', 'ai.invalid_credentials'], [$explanation->refresh()->status, $explanation->failure_code]);
        $this->assertSame('invalid_credentials', $this->provider->refresh()->status);
        $this->assertSame('ai.invalid_credentials', AiRun::query()->sole()->failure_code);
    }

    public function test_late_credential_error_from_a_replaced_key_leaves_the_provider_alone(): void
    {
        $explanation = $this->queued();
        // The admin replaces the key and re-tests while the old key's call is still in flight.
        Http::fake(['api.anthropic.com/*' => function () {
            $this->provider->forceFill(['api_key' => 'sk-ant-new-key-0000', 'key_version' => 2, 'status' => 'ready'])->save();

            return Http::response(['error' => ['type' => 'authentication_error']], 401);
        }]);

        $this->runJob($explanation);

        $this->assertSame(['failed', 'ai.invalid_credentials'], [$explanation->refresh()->status, $explanation->failure_code]);
        $this->assertSame('ready', $this->provider->refresh()->status);
    }

    public function test_empty_output_counts_tokens(): void
    {
        $explanation = $this->queued();
        $this->fakeAi(['content' => [], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 900, 'output_tokens' => 4]]);

        $this->runJob($explanation);

        $this->assertSame(['failed', 'ai.empty_output'], [$explanation->refresh()->status, $explanation->failure_code]);
        $run = AiRun::query()->sole();
        $this->assertSame(['failed', 'ai.empty_output', 900, 4], [$run->status, $run->failure_code, $run->input_tokens, $run->output_tokens]);
        $this->assertSame(0, AuditEvent::query()->where('event_type', 'ai.workflow_explained')->count());
    }

    public function test_failed_trace_marks_explanation_trace_failed(): void
    {
        Http::fake();
        Queue::fake([AnalyzeEndpointJob::class]);
        $explanation = $this->request();
        $this->assertSame('tracing', $explanation->status);

        app(EndpointAnalyses::class)->fail($this->advanced()->id, 'internal');

        $explanation->refresh();
        $this->assertSame(['failed', 'ai.trace_failed'], [$explanation->status, $explanation->failure_code]);
        $this->assertNotNull($explanation->finished_at);
        Http::assertNothingSent();
    }

    public function test_trace_finishing_in_the_worker_queues_the_explanation(): void
    {
        $this->fakeAi();
        Queue::fake([AnalyzeEndpointJob::class]);
        $explanation = $this->request();
        $this->assertSame('tracing', $explanation->status);

        app(EndpointAnalyses::class)->process($this->advanced()->id);

        $this->assertSame('completed', $explanation->refresh()->status);
        $this->assertStringContainsString('## Overview', $explanation->markdown);
    }

    public function test_partial_trace_is_still_explained(): void
    {
        $this->fakeAi();
        $advanced = app(EndpointAnalyses::class)->start($this->developer, $this->endpoint, profile: EndpointAnalyses::ADVANCED)->refresh();
        $result = $advanced->result;
        $result['graph']['coverage']['complete'] = false;
        $result['graph']['coverage']['bounds'] = [['code' => 'depth', 'detail' => 'Bound reached: call depth 7; deeper calls were not followed']];
        $advanced->forceFill(['status' => RunStatus::Partial, 'result' => $result])->save();
        $explanation = WorkflowExplanation::factory()->create(['endpoint_analysis_id' => $advanced->id, 'status' => 'tracing', 'ai_provider_id' => $this->provider->id, 'key_version' => 1, 'provider' => 'anthropic', 'model' => 'claude-test', 'effort' => 'high']);

        EndpointAnalysisFinished::dispatch($advanced->id);

        $this->assertSame('completed', $explanation->refresh()->status);
        Http::assertSent(fn (Request $r) => str_contains($r['messages'][0]['content'], 'Bound reached: call depth 7; deeper calls were not followed'));

        // Partial without a graph has nothing to explain.
        EndpointAnalysis::query()->whereKey($advanced->id)->update(['result' => json_encode(['analyzer_version' => 'x'])]);
        $explanation->forceFill(['status' => 'tracing'])->save();

        EndpointAnalysisFinished::dispatch($advanced->id);

        $this->assertSame(['failed', 'ai.trace_failed'], [$explanation->refresh()->status, $explanation->failure_code]);
        Http::assertSentCount(1);
    }

    public function test_trace_kept_when_ai_fails(): void
    {
        $explanation = $this->queued();
        $advanced = $this->advanced();
        $edges = ApiDependency::query()->where('endpoint_analysis_id', $advanced->id)->count();
        $this->assertGreaterThan(0, $edges);
        $this->fakeAi([], 500);

        $this->runJob($explanation, 3);

        $this->assertSame(['failed', 'ai.provider_unavailable'], [$explanation->refresh()->status, $explanation->failure_code]);
        $after = $this->advanced();
        $this->assertSame(RunStatus::Completed, $after->status);
        $this->assertSame($advanced->result['graph'], $after->result['graph']);
        $this->assertSame($edges, ApiDependency::query()->where('endpoint_analysis_id', $advanced->id)->count());
    }

    public function test_key_never_in_an_unexpected_adapter_exception(): void
    {
        Http::fake(fn () => throw new RuntimeException('boom'));

        foreach (['anthropic', 'openai', 'gemini'] as $provider) {
            foreach (['generate', 'test'] as $call) {
                try {
                    $adapter = AiAdapters::for($provider);
                    $call === 'generate' ? $adapter->generate(self::KEY, 'm', 'high', 'sys', 'user', 10) : $adapter->test(self::KEY);
                    $this->fail("{$provider} {$call}: expected the exception to escape");
                } catch (RuntimeException $e) {
                    $this->assertSame('boom', $e->getMessage());
                    $this->assertStringNotContainsString(self::KEY, json_encode(array_column($e->getTrace(), 'args')) ?: '', "{$provider} {$call}");
                    $this->assertStringNotContainsString(substr(self::KEY, 0, 12), (string) $e, "{$provider} {$call}");
                }
            }
        }
    }

    public function test_key_never_in_job_payload_logs_or_audit(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.json_encode(array_map(fn ($v) => $v instanceof \Throwable ? (string) $v.json_encode(array_column($v->getTrace(), 'args')) : $v, $e->context));
        });
        Log::info('capture check');
        $this->assertCount(1, $logged, 'the log listener captures records');

        $explanation = $this->queued();
        Queue::assertPushed(ExplainWorkflowJob::class, function (ExplainWorkflowJob $job) {
            $this->assertStringNotContainsString(self::KEY, serialize($job));

            return true;
        });

        Http::fakeSequence('api.anthropic.com/*')
            ->push(['error' => ['type' => 'overloaded_error']], 529)
            ->push(['content' => [['type' => 'text', 'text' => self::MARKDOWN]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 5, 'output_tokens' => 5]]);
        try {
            $this->runJob($explanation);
            $this->fail('a 529 is retried');
        } catch (AiError $e) {
            $this->assertKeyNotIn($e);
        }
        $this->runJob($explanation, 2);
        $this->assertSame('completed', $explanation->refresh()->status);

        foreach ($logged as $record) {
            $this->assertStringNotContainsString(self::KEY, $record);
        }
        $this->assertStringNotContainsString(self::KEY, DB::table('audit_events')->get()->toJson());
        $this->assertStringNotContainsString(self::KEY, DB::table('ai_runs')->get()->toJson());
        $this->assertStringNotContainsString(self::KEY, DB::table('workflow_explanations')->get()->toJson());

        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);
        foreach ($recorded as [$request]) {
            $this->assertSame([self::KEY], $request->header('x-api-key'));
            $headers = $request->headers();
            unset($headers['x-api-key']);
            $this->assertStringNotContainsString(self::KEY, $request->url().$request->body().json_encode($headers));
        }
    }
}
