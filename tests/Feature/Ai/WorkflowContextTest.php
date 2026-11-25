<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\WorkflowContext;
use App\Domain\Ai\WorkflowPrompt;
use App\Domain\Analysis\EndpointAnalyses;
use App\Domain\Sources\RunStatus;
use App\Domain\Workspaces\WorkspaceRole;
use App\Models\ApiEndpoint;
use App\Models\EndpointAnalysis;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSnapshots;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

/** The AI context for a stored workflow trace, built from tests/Fixtures/workflow (never loaded or run). */
class WorkflowContextTest extends TestCase
{
    use BuildsSnapshots, RefreshDatabase, WorksWithWorkspaces;

    private const CONTROLLER = 'WorkflowFixture\App\Http\Controllers\OrderController';

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        config(['features.project_wizard' => true, 'features.source_snapshots' => true, 'features.framework_detection' => true, 'features.route_discovery' => true, 'features.endpoint_analysis' => true, 'features.advanced_analysis' => true, 'app.debug' => false]);
        Storage::fake('quarantine', ['throw' => true]);
        Storage::fake('snapshots', ['throw' => true]);
    }

    /** A completed advanced analysis of OrderController::$action, with $replace applied to the fixture files and $files written over them. */
    private function advanced(string $action = 'store', array $replace = [], array $files = []): EndpointAnalysis
    {
        $base = [
            'composer.json' => json_encode(['require' => ['laravel/framework' => '^11.0'], 'autoload' => ['psr-4' => ['WorkflowFixture\\App\\' => 'app/']]]),
            'composer.lock' => json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v11.30.0']]]),
            'artisan' => "<?php\n",
            'bootstrap/app.php' => "<?php\nreturn Application::configure(basePath: dirname(__DIR__))->withRouting(api: __DIR__.'/../routes/api.php')->create();\n",
            'routes/api.php' => "<?php\nuse ".self::CONTROLLER.";\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::post('orders', [OrderController::class, '{$action}']);\n",
        ];
        foreach (File::allFiles(base_path('tests/Fixtures/workflow/app')) as $file) {
            $base['app/'.$file->getRelativePathname()] = strtr($file->getContents(), $replace);
        }
        $files = $files + $base;
        $project = Project::factory()->create();
        $developer = $this->memberOf(WorkspaceRole::Developer, $project->workspace);
        $this->snapshotFrom($files, $project, $developer);
        $endpoint = ApiEndpoint::query()->where('project_id', $project->id)->where('action', $action)->firstOrFail();

        $analysis = app(EndpointAnalyses::class)->start($developer, $endpoint, profile: EndpointAnalyses::ADVANCED)->refresh();
        $this->assertSame(RunStatus::Completed, $analysis->status);

        return $analysis;
    }

    /** @return list<string> the "### Class::method" headings, in order */
    private static function methods(string $user): array
    {
        preg_match_all('/^### (.+)$/m', $user, $m);

        return $m[1];
    }

    public function test_includes_trace_and_method_code_nearest_first(): void
    {
        $context = WorkflowContext::build($this->advanced());
        $user = $context['user'];

        $this->assertStringContainsString('## Trace', $user);
        $this->assertStringContainsString('## Method code', $user);
        $this->assertStringContainsString('## Not sent', $user);
        preg_match('/## Trace\s+```json\n(.*?)\n```/s', $user, $m);
        $trace = json_decode($m[1] ?? '', true);
        $this->assertIsArray($trace);
        $this->assertStringNotContainsString("\n", $m[1], 'compact JSON: no tokens spent on whitespace');
        $this->assertSame(['id', 'type', 'label'], array_keys($trace['nodes'][0]));
        $this->assertSame(['from', 'to', 'kind', 'conditional', 'async', 'reason'], array_keys($trace['edges'][0]));
        $this->assertArrayHasKey('coverage', $trace);
        $this->assertSame(['POST'], $trace['route']['methods']);
        $this->assertSame('/api/orders', $trace['route']['uri']);
        $this->assertSame(self::CONTROLLER.'::store', $trace['route']['action']);
        $this->assertSame('WorkflowFixture\App\Http\Requests\StoreOrderRequest', $trace['route']['form_request']);
        $fields = array_column($trace['route']['fields'], null, 'name');
        $this->assertSame(['name', 'rules'], array_keys($fields['total']), 'field names and rule names only: no evidence');
        $this->assertContains('Required', $fields['total']['rules']);
        $this->assertContains('Integer', $fields['total']['rules']);
        $this->assertContains('Nullable', $fields['notes']['rules']);

        $methods = self::methods($user);
        $this->assertSame(self::CONTROLLER.'::store', $methods[0], 'the action is nearest the route');
        $this->assertLessThan(array_search('WorkflowFixture\App\Services\OrderService::audit', $methods, true), array_search('WorkflowFixture\App\Services\OrderService::place', $methods, true));
        $this->assertStringContainsString('$this->orders->place($request->validated());', $user);
        $this->assertStringContainsString('ProcessOrder::dispatch($order);', $user);
        $this->assertSame([], $context['omitted']);
    }

    public function test_budget_moves_far_methods_to_omitted(): void
    {
        $advanced = $this->advanced();
        $full = WorkflowContext::build($advanced)['user'];
        $all = self::methods($full);
        $this->assertGreaterThan(2, count($all));

        $tiny = WorkflowContext::build($advanced, 200);
        $this->assertSame([], self::methods($tiny['user']), 'the trace alone is over 200 tokens');
        $this->assertSame($all, $tiny['omitted']);
        foreach ($tiny['omitted'] as $name) {
            $this->assertStringContainsString("- {$name}\n", $tiny['user']);
        }

        // Room for the trace and the first method only: the nearest one is sent, the far ones are listed by name.
        $firstOnly = (int) ceil(strpos($full, "\n### ", strpos($full, '### ') + 4) / 4);
        $context = WorkflowContext::build($advanced, $firstOnly);
        $this->assertSame([$all[0]], self::methods($context['user']));
        $this->assertSame(array_slice($all, 1), $context['omitted']);
        $this->assertStringContainsString(count($context['omitted']).' methods were not sent', $context['user']);
    }

    public function test_missing_class_goes_to_omitted_without_throwing(): void
    {
        $advanced = $this->advanced();
        $result = $advanced->result;
        $result['graph']['nodes'][] = ['id' => 'WorkflowFixture\App\Services\Gone::run', 'type' => 'service', 'label' => 'Gone::run', 'class' => 'WorkflowFixture\App\Services\Gone', 'method' => 'run'];
        $result['graph']['nodes'][] = ['id' => 'WorkflowFixture\App\Services\OrderService::nope', 'type' => 'service', 'label' => 'OrderService::nope', 'class' => 'WorkflowFixture\App\Services\OrderService', 'method' => 'nope'];
        $result['graph']['nodes'][] = ['id' => 'Illuminate\Support\Str::random', 'type' => 'library', 'label' => 'Str::random', 'class' => 'Illuminate\Support\Str', 'method' => 'random'];
        $advanced->result = $result;

        $context = WorkflowContext::build($advanced);

        $this->assertSame(['WorkflowFixture\App\Services\Gone::run', 'WorkflowFixture\App\Services\OrderService::nope'], $context['omitted']);
        $this->assertNotContains('Illuminate\Support\Str::random', self::methods($context['user']), 'library code is never sent');
        $this->assertContains(self::CONTROLLER.'::store', self::methods($context['user']));
    }

    public function test_a_class_whose_file_does_not_parse_goes_to_omitted_without_throwing(): void
    {
        $advanced = $this->advanced(files: ['app/Services/DeepChain.php' => "<?php\nnamespace WorkflowFixture\\App\\Services;\n\nclass DeepChain {\n    public function d1( { return \$this->;;\n"]);
        $result = $advanced->result;
        $result['graph']['nodes'][] = ['id' => 'WorkflowFixture\App\Services\DeepChain::d1', 'type' => 'service', 'label' => 'DeepChain::d1', 'class' => 'WorkflowFixture\App\Services\DeepChain', 'method' => 'd1'];
        $advanced->result = $result;

        $context = WorkflowContext::build($advanced);

        $this->assertSame(['WorkflowFixture\App\Services\DeepChain::d1'], $context['omitted']);
        $this->assertStringContainsString("- WorkflowFixture\\App\\Services\\DeepChain::d1\n", $context['user']);
        $this->assertContains(self::CONTROLLER.'::store', self::methods($context['user']));
    }

    public function test_comments_are_stripped(): void
    {
        $user = WorkflowContext::build($this->advanced())['user'];

        $this->assertStringNotContainsString('IGNORE PREVIOUS INSTRUCTIONS', $user);
        $this->assertStringNotContainsString('the same call again', $user);
        $this->assertStringContainsString('ProcessOrder::dispatch($order);', $user);
    }

    public function test_no_file_paths_in_context(): void
    {
        $this->assertStringNotContainsString('.php', WorkflowContext::build($this->advanced())['user']);
    }

    public function test_secrets_in_method_code_are_redacted(): void
    {
        $user = WorkflowContext::build($this->advanced(replace: ['Order::create($data);' => "Order::create(\$data + ['api_key' => 'sk-live-123']);"]))['user'];

        $this->assertStringNotContainsString('sk-live-123', $user);
        $this->assertStringContainsString("'api_key' => '[redacted]'", $user);
    }

    public function test_invalid_utf8_in_source_still_gives_valid_utf8_text(): void
    {
        $user = WorkflowContext::build($this->advanced(replace: ['Order::create($data);' => "Order::create(\$data + ['note' => \"caf\xC3\x28 bad\"]);"]))['user'];

        $this->assertTrue(mb_check_encoding($user, 'UTF-8'));
        $this->assertNotFalse(json_encode(['content' => $user]));
        $this->assertStringContainsString('bad', $user);
    }

    public function test_system_prompt_asks_for_the_sections_in_order_and_treats_input_as_untrusted(): void
    {
        $system = WorkflowPrompt::system();

        preg_match_all('/^## .+$/m', $system, $m);
        $this->assertSame(['## Overview', '## Step by step', '## Components', '## Data', '## Side effects', '## Branches and outcomes', '## Not covered'], $m[0]);
        $this->assertStringContainsString('untrusted data', $system);
        $this->assertStringContainsString('None in this endpoint.', $system);
        $this->assertSame('workflow-explain-v1', WorkflowPrompt::VERSION);
    }
}
