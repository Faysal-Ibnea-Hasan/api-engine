<?php

namespace Tests\Feature\Analysis;

use App\Domain\Analysis\ClassIndex;
use App\Domain\Analysis\Evidence;
use App\Domain\Analysis\RuleExtractor;
use App\Domain\Sources\SnapshotReader;
use App\Domain\Workspaces\WorkspaceRole;
use App\Models\Project;
use App\Models\SourceSnapshot;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationRuleParser;
use Stringable;
use Tests\Concerns\AnalyzesFixtures;
use Tests\Concerns\BuildsSnapshots;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

/**
 * Static rule extraction must agree with what Laravel itself sees when it runs rules() on
 * the same trusted fixture classes. (Only our fixtures are ever loaded; uploads never are.)
 */
class RuleParityTest extends TestCase
{
    use AnalyzesFixtures, BuildsSnapshots, RefreshDatabase, WorksWithWorkspaces;

    private SourceSnapshot $snapshot;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('quarantine', ['throw' => true]);
        Storage::fake('snapshots', ['throw' => true]);
        $project = Project::factory()->create();
        $this->snapshot = $this->snapshotFrom($this->fixtureApp(), $project, $this->memberOf(WorkspaceRole::Developer, $project->workspace));
    }

    /** @return array<string, array<string, mixed>> field name => static result */
    private function static(string $class): array
    {
        $index = new ClassIndex(app(SnapshotReader::class), $this->snapshot, '.');
        $info = $index->find($class) ?? $this->fail("{$class} not found by the class index");
        $this->assertTrue($index->extends($info, 'Illuminate\Foundation\Http\FormRequest'));

        $extractor = new RuleExtractor($info->file, new Evidence, $info->sha256, $info->symbol('rules'), 'form_request');

        return collect($extractor->fromRulesMethod($info->method('rules')))->keyBy('name')->all();
    }

    /** @return array<string, list<array{string, list<string>}>> what Laravel's validator would see */
    private function laravel(string $class, string $file): array
    {
        require_once base_path("tests/Fixtures/analysis/app/{$file}");
        $rules = (new $class)->rules();

        $out = [];
        foreach ($rules as $field => $value) {
            $list = is_string($value) ? explode('|', $value) : $value;
            $out[$field] = array_map(function ($rule) {
                $text = is_string($rule) ? $rule : ($rule instanceof Stringable || method_exists($rule, '__toString') ? (string) $rule : null);
                if ($text === null) {
                    return [$rule instanceof Closure ? 'Closure' : 'Custom', []];
                }
                [$name, $params] = ValidationRuleParser::parse($text);

                return [(string) $name, array_map(fn ($p) => trim((string) $p, '"'), $params)];
            }, $list);
        }

        return $out;
    }

    public function test_static_rules_match_laravel_for_a_literal_form_request()
    {
        $static = $this->static('Fixture\App\Http\Requests\StoreOrderRequest');
        $laravel = $this->laravel('Fixture\App\Http\Requests\StoreOrderRequest', 'Http/Requests/StoreOrderRequest.php');

        $this->assertSame(array_keys($laravel), array_keys($static));
        foreach ($laravel as $field => $rules) {
            $this->assertSame($rules, array_map(fn ($r) => [$r['rule'], $r['params']], $static[$field]['rules']), $field);
            $this->assertSame('verified', $static[$field]['status'], $field);
        }

        $this->assertSame('required', $static['customer_id']['required']);
        $this->assertSame(['integer'], $static['customer_id']['types']);
        $this->assertTrue($static['note']['nullable']);
        $this->assertSame('optional', $static['note']['required']);
        $this->assertTrue($static['coupon']['sometimes']);
        $this->assertSame('conditional', $static['address']['required'], 'required_if is conditional');
        $this->assertSame(['delivery', 'pickup'], $static['type']['constraints']['in']);
        $this->assertSame(['1', '99'], $static['items.*.qty']['constraints']['between']);
        $this->assertSame(['file'], $static['attachment']['types']);
        $this->assertSame(['email'], $static['email']['formats']);
    }

    public function test_rules_built_in_the_method_match_laravel_and_if_branches_are_conditional()
    {
        $static = $this->static('Fixture\App\Http\Requests\UpdateProfileRequest');
        $laravel = $this->laravel('Fixture\App\Http\Requests\UpdateProfileRequest', 'Http/Requests/UpdateProfileRequest.php');

        foreach ($laravel as $field => $rules) {
            $this->assertSame($rules, array_map(fn ($r) => [$r['rule'], $r['params']], $static[$field]['rules']), $field);
            $this->assertFalse($static[$field]['conditional'], $field);
        }

        // Only added for PATCH: present statically, marked conditional, never claimed absolute.
        $this->assertArrayNotHasKey('bio', $laravel);
        $this->assertTrue($static['bio']['conditional']);
    }

    public function test_dynamic_rules_stay_unknown_without_hiding_the_verified_ones()
    {
        $extractor = null;
        $index = new ClassIndex(app(SnapshotReader::class), $this->snapshot, '.');
        $info = $index->find('Fixture\App\Http\Requests\DynamicRequest');
        $extractor = new RuleExtractor($info->file, new Evidence, $info->sha256, $info->symbol('rules'), 'form_request');
        $fields = collect($extractor->fromRulesMethod($info->method('rules')))->keyBy('name')->all();

        $rule = fn (string $field, string $name) => collect($fields[$field]['rules'])->firstWhere('rule', $name);

        $this->assertSame('required', $fields['email']['required']);
        $this->assertSame('verified', $rule('email', 'Email')['status']);
        $this->assertSame('partial', $rule('email', 'Unique')['status'], 'unique table known, ignore() is runtime');
        $this->assertSame('partial', $fields['email']['status']);

        $this->assertSame('unknown', $rule('code', 'Custom')['status']);
        $this->assertSame(['Fixture\App\Rules\Uppercase'], $rule('code', 'Custom')['params']);
        $this->assertSame('required', $fields['code']['required'], 'a custom rule never hides "required"');

        $this->assertSame('unknown', $rule('token', 'Closure')['status']);
        $this->assertSame('unknown', $rule('role', 'RequiredIf')['status']);
        $this->assertSame('conditional', $fields['role']['required']);

        $this->assertCount(4, $fields, 'the field with a dynamic name is not invented');
        $this->assertStringContainsString('dynamic name', implode(' ', $extractor->unresolved));
    }
}
