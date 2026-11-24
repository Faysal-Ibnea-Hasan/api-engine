<?php

namespace Tests\Feature\Framework;

use App\Domain\Framework\FrameworkDetections;
use App\Domain\Sources\SnapshotImporter;
use App\Domain\Sources\SourceType;
use App\Domain\Workspaces\WorkspaceRole;
use App\Models\FrameworkDetection;
use App\Models\ProcessingRun;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

class FrameworkDetectionTest extends TestCase
{
    use RefreshDatabase, WorksWithWorkspaces;

    private Project $project;

    private User $developer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        config(['features.project_wizard' => true, 'features.source_snapshots' => true, 'features.framework_detection' => true, 'app.debug' => false]);
        $this->withoutVite();
        Storage::fake('quarantine', ['throw' => true]);
        Storage::fake('snapshots', ['throw' => true]);
        @unlink(sys_get_temp_dir().'/api-engine-detect-sentinel');

        $this->project = Project::factory()->create();
        $this->developer = $this->memberOf(WorkspaceRole::Developer, $this->project->workspace);
    }

    /** A Laravel app's files under $prefix ('' for the archive root). */
    private function laravelApp(string $prefix = '', ?string $locked = '11.31.0', string $constraint = '^11.0'): array
    {
        $files = [
            "{$prefix}composer.json" => json_encode(['require' => ['php' => '^8.2', 'laravel/framework' => $constraint]]),
            "{$prefix}artisan" => "#!/usr/bin/env php\n<?php\n",
            "{$prefix}bootstrap/app.php" => "<?php\nfile_put_contents(sys_get_temp_dir().'/api-engine-detect-sentinel', 'ran');\nreturn Application::configure()->withRouting(api: __DIR__.'/../routes/api.php');\n",
            "{$prefix}routes/api.php" => "<?php\nRoute::get('/users', fn () => []);\n",
            "{$prefix}routes/web.php" => "<?php\n",
        ];

        if ($locked !== null) {
            $files["{$prefix}composer.lock"] = json_encode(['packages' => [['name' => 'laravel/framework', 'version' => "v{$locked}"]]]);
        }

        return $files;
    }

    /** Imports the files as a ready snapshot (sync queue), which also triggers detection. */
    private function snapshotOf(array $files, ?Project $project = null): SourceSnapshot
    {
        $project ??= $this->project;
        [$run] = app(SnapshotImporter::class)->begin($this->developer, $project, SourceType::Zip, Str::uuid()->toString(), function (Filesystem $q, string $dir) use ($files) {
            foreach ($files as $path => $content) {
                $q->put("{$dir}/{$path}", $content);
            }
        });

        return ProcessingRun::query()->findOrFail($run->id)->snapshot ?? $this->fail('no snapshot');
    }

    private function detection(SourceSnapshot $snapshot): FrameworkDetection
    {
        return app(FrameworkDetections::class)->current($snapshot) ?? $this->fail('no detection');
    }

    public function test_a_standard_laravel_app_is_detected_automatically_after_import_without_running_it()
    {
        $detection = $this->detection($this->snapshotOf($this->laravelApp()));
        $root = $detection->result['roots'][0];

        $this->assertSame('detected', $detection->status);
        $this->assertSame('.', $detection->selected_root);
        $this->assertSame('auto', $detection->root_selection_source);
        $this->assertSame('^11.0', $root['declared_version_constraint']);
        $this->assertSame('11.31.0', $root['locked_framework_version']);
        $this->assertSame('^8.2', $root['php_constraint']);
        $this->assertSame('bootstrap_app', $root['route_layout']);
        $this->assertSame(['routes/api.php', 'routes/web.php'], $root['route_files']);
        $this->assertContains('composer.lock', $root['evidence']);
        $this->assertFileDoesNotExist(sys_get_temp_dir().'/api-engine-detect-sentinel', 'uploaded code was executed');
    }

    public function test_without_a_lock_file_only_the_constraint_is_reported()
    {
        $detection = $this->detection($this->snapshotOf($this->laravelApp(locked: null, constraint: '^12.0')));

        $this->assertSame('^12.0', $detection->result['roots'][0]['declared_version_constraint']);
        $this->assertNull($detection->result['roots'][0]['locked_framework_version']);
        $this->assertStringContainsString('only the declared constraint is known', implode(' ', $detection->result['warnings']));
    }

    public function test_a_monorepo_needs_an_explicit_choice_and_only_real_folders_are_accepted()
    {
        $snapshot = $this->snapshotOf([
            ...$this->laravelApp('backend/'),
            ...$this->laravelApp('legacy-api/'),
            'packages/sdk/composer.json' => json_encode(['require' => ['laravel/framework' => '^11.0']]), // a package, not an app
            'docs/readme.md' => '# docs',
        ]);
        $detection = $this->detection($snapshot);

        $this->assertSame('partial', $detection->status);
        $this->assertNull($detection->selected_root, 'no arbitrary default');
        $this->assertEqualsCanonicalizing(['backend', 'legacy-api'], $detection->rootPaths());
        $this->assertStringContainsString('looks like a package', implode(' ', $detection->result['warnings']));

        $url = "/projects/{$this->project->id}/snapshots/{$snapshot->id}/application-root";
        $this->actingAs($this->developer);
        foreach (['../etc', '/var/www', 'backend/../../x', 'nope'] as $bad) {
            $this->patch($url, ['root' => $bad])->assertSessionHasErrors('root');
        }
        $this->assertNull($detection->fresh()->selected_root);

        $this->patch($url, ['root' => 'legacy-api/'])->assertSessionHasNoErrors();
        $this->assertSame('legacy-api', $detection->fresh()->selected_root);
        $this->assertSame('user', $detection->fresh()->root_selection_source);

        // Any existing folder may be chosen for unusual layouts.
        $this->patch($url, ['root' => 'docs'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_events', ['event_type' => 'framework.root_selected']);
    }

    public function test_the_project_setup_root_preselects_among_several_apps()
    {
        $this->project->forceFill(['root_path' => 'backend'])->save();

        $detection = $this->detection($this->snapshotOf([...$this->laravelApp('backend/'), ...$this->laravelApp('admin/')]));

        $this->assertSame('partial', $detection->status);
        $this->assertSame('backend', $detection->selected_root);
        $this->assertSame('project_setting', $detection->root_selection_source);
    }

    public function test_non_laravel_lumen_and_empty_sources_are_reported_as_such()
    {
        $php = $this->detection($this->snapshotOf(['composer.json' => json_encode(['require' => ['symfony/console' => '^7.0']]), 'bin/app' => '<?php']));
        $this->assertSame('unsupported', $php->status);
        $this->assertNull($php->selected_root);

        $lumen = $this->detection($this->snapshotOf(['composer.json' => json_encode(['require' => ['laravel/lumen-framework' => '^10.0']]), 'artisan' => '<?php']));
        $this->assertSame('unsupported', $lumen->status);
        $this->assertStringContainsString('Lumen is not supported', implode(' ', $lumen->result['warnings']));

        $none = $this->detection($this->snapshotOf(['index.html' => '<h1>hi</h1>']));
        $this->assertSame('not_found', $none->status);
    }

    public function test_malformed_composer_json_gives_a_partial_result_with_evidence()
    {
        $files = $this->laravelApp();
        $files['composer.json'] = '{ not json';
        $detection = $this->detection($this->snapshotOf($files));

        $this->assertSame('partial', $detection->status);
        $this->assertSame('.', $detection->result['recommended_root']);
        $this->assertStringContainsString('composer.json could not be parsed', implode(' ', $detection->result['warnings']));
    }

    public function test_a_wrapper_folder_is_found_and_results_are_immutable_and_idempotent()
    {
        $snapshot = $this->snapshotOf($this->laravelApp('myapp/'));
        $detection = $this->detection($snapshot);

        $this->assertSame('detected', $detection->status);
        $this->assertSame('myapp', $detection->selected_root);
        $this->assertSame($detection->id, app(FrameworkDetections::class)->detect($snapshot)->id, 're-running is a no-op');
        $this->assertSame(1, FrameworkDetection::query()->count());

        $this->expectException(LogicException::class);
        $detection->forceFill(['status' => 'not_found'])->save();
    }

    public function test_access_to_detection_follows_project_permissions()
    {
        $snapshot = $this->snapshotOf($this->laravelApp());
        $base = "/projects/{$this->project->id}/snapshots/{$snapshot->id}";

        $this->actingAs($this->developer)->getJson("{$base}/detection")->assertOk()
            ->assertJsonPath('roots.0.evidence', fn ($e) => in_array('composer.json', $e, true));

        $qa = $this->memberOf(WorkspaceRole::QA, $this->project->workspace);
        $this->actingAs($qa)->getJson("{$base}/detection")->assertOk()
            ->assertJsonPath('status', 'detected')
            ->assertJsonPath('roots.0.lockedVersion', '11.31.0')
            ->assertJsonPath('roots.0.evidence', null);
        $this->patchJson("{$base}/application-root", ['root' => '.'])->assertForbidden();
        $this->postJson("{$base}/detect")->assertForbidden();

        $this->actingAs($this->memberOf(WorkspaceRole::Owner));
        $missing = (string) Str::uuid();
        $this->assertSame(
            $this->patchJson("/projects/{$this->project->id}/snapshots/{$missing}/application-root", [])->assertNotFound()->json(),
            $this->patchJson("{$base}/application-root", [])->assertNotFound()->json(),
        );
        $this->getJson("{$base}/detection")->assertNotFound();
    }

    public function test_the_flag_hides_detection_and_stops_automatic_runs()
    {
        config(['features.framework_detection' => false]);
        $snapshot = $this->snapshotOf($this->laravelApp());

        $this->assertSame(0, FrameworkDetection::query()->count());
        $this->actingAs($this->developer)->postJson("/projects/{$this->project->id}/snapshots/{$snapshot->id}/detect")->assertNotFound();
        $this->get("/projects/{$this->project->id}/snapshots/{$snapshot->id}")->assertInertia(fn ($page) => $page->where('detection', null));
    }

    public function test_detection_can_be_run_by_hand_and_shows_on_the_pages()
    {
        config(['features.framework_detection' => false]);
        $snapshot = $this->snapshotOf($this->laravelApp());
        config(['features.framework_detection' => true]);

        $this->actingAs($this->developer)->post("/projects/{$this->project->id}/snapshots/{$snapshot->id}/detect")->assertRedirect();

        $this->get("/projects/{$this->project->id}/snapshots/{$snapshot->id}")->assertInertia(fn ($page) => $page
            ->where('detection.result.status', 'detected')
            ->where('detection.canSelectRoot', true));
        $this->get("/projects/{$this->project->id}")->assertInertia(fn ($page) => $page
            ->where('framework.detection.selectedRoot', '.')
            ->where('framework.detection.roots.0.lockedVersion', '11.31.0'));
    }

    public function test_an_older_detector_result_stays_visible_as_outdated_and_rerunning_keeps_the_chosen_root()
    {
        $snapshot = $this->snapshotOf([...$this->laravelApp('backend/'), ...$this->laravelApp('admin/')]);
        $current = $this->detection($snapshot);
        // Pretend it was made by an older detector, with a root a person chose.
        FrameworkDetection::query()->whereKey($current->id)->update(['detector_version' => 'laravel-detector-v0', 'selected_root' => 'admin', 'root_selection_source' => 'user']);

        $base = "/projects/{$this->project->id}/snapshots/{$snapshot->id}";
        $this->actingAs($this->developer)->getJson("{$base}/detection")->assertOk()
            ->assertJsonPath('outdated', true)
            ->assertJsonPath('selectedRoot', 'admin');

        $this->post("{$base}/detect")->assertRedirect();

        $fresh = $this->detection($snapshot);
        $this->assertNotSame($current->id, $fresh->id);
        $this->assertSame('admin', $fresh->selected_root, 'the person\'s choice carries over');
        $this->assertSame('user', $fresh->root_selection_source);
        $this->getJson("{$base}/detection")->assertJsonPath('outdated', false);
    }

    public function test_the_root_must_match_a_real_folder_exactly_and_hold_stored_files()
    {
        $snapshot = $this->snapshotOf([...$this->laravelApp('backend/'), ...$this->laravelApp('admin/'), 'secrets/.env.production' => 'X=1']);
        $url = "/projects/{$this->project->id}/snapshots/{$snapshot->id}/application-root";
        $this->actingAs($this->developer);

        $this->patch($url, ['root' => 'Backend'])->assertSessionHasErrors('root');   // wrong case
        $this->patch($url, ['root' => 'secrets'])->assertSessionHasErrors('root');   // only excluded files
        $this->patch($url, ['root' => 'back'])->assertSessionHasErrors('root');      // prefix of a folder, not a folder
        $this->patch($url, ['root' => 'backend'])->assertSessionHasNoErrors();
    }

    public function test_roles_without_source_access_get_no_file_names_in_warnings()
    {
        $snapshot = $this->snapshotOf($this->laravelApp(locked: null));
        $base = "/projects/{$this->project->id}/snapshots/{$snapshot->id}";

        $this->actingAs($this->memberOf(WorkspaceRole::Viewer, $this->project->workspace))
            ->getJson("{$base}/detection")->assertOk()
            ->assertJsonPath('warnings', [])
            ->assertJsonPath('hiddenWarningCount', 1)
            ->assertJsonPath('roots.0.path', '.');

        $this->actingAs($this->developer)->getJson("{$base}/detection")
            ->assertJsonPath('hiddenWarningCount', 0)
            ->assertJsonCount(1, 'warnings');
    }

    public function test_the_number_of_inspected_composer_files_is_capped()
    {
        $files = $this->laravelApp('app/');
        for ($i = 0; $i < 60; $i++) {
            $files["libs/lib{$i}/composer.json"] = json_encode(['require' => ['psr/log' => '^3.0']]);
        }

        $detection = $this->detection($this->snapshotOf($files));

        $this->assertSame('detected', $detection->status, 'the shallow app is still found');
        $this->assertStringContainsString('only the 50 closest', implode(' ', $detection->result['warnings']));
    }

    public function test_the_project_page_follows_the_latest_import_even_when_it_reused_an_older_snapshot()
    {
        $a = $this->laravelApp(locked: '11.1.0');
        $this->snapshotOf($a);
        $this->snapshotOf($this->laravelApp(locked: '11.2.0'));
        $this->snapshotOf($a); // identical to the first: reuses that snapshot

        $this->actingAs($this->developer)->get("/projects/{$this->project->id}")->assertInertia(fn ($page) => $page
            ->where('framework.detection.roots.0.lockedVersion', '11.1.0'));
    }

    public function test_a_dot_slash_project_root_still_preselects()
    {
        $this->project->forceFill(['root_path' => './backend/'])->save();

        $detection = $this->detection($this->snapshotOf([...$this->laravelApp('backend/'), ...$this->laravelApp('admin/')]));

        $this->assertSame('backend', $detection->selected_root);
    }
}
