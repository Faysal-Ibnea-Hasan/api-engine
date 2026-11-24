<?php

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\GitHubConnectionService;
use App\Domain\Projects\ProjectDeleter;
use App\Domain\Projects\ProjectError;
use App\Domain\Sources\RunStatus;
use App\Domain\Sources\SnapshotImporter;
use App\Domain\Sources\SnapshotStatus;
use App\Domain\Sources\SourceType;
use App\Domain\Workspaces\WorkspaceRole;
use App\Jobs\FetchGitHubArchiveJob;
use App\Models\AuditEvent;
use App\Models\GitHubImport;
use App\Models\GitHubInstallation;
use App\Models\GitHubRepositorySource;
use App\Models\ProcessingRun;
use App\Models\Project;
use App\Models\SourceFile;
use App\Models\SourceSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesGitHub;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

class GitHubImportTest extends TestCase
{
    use FakesGitHub, RefreshDatabase, WorksWithWorkspaces;

    private Project $project;

    private User $developer;

    /** @var list<string> */
    private array $logs = [];

    private const FILES = [
        'composer.json' => '{"require":{"laravel/framework":"^11.0"}}',
        'artisan' => "<?php\n",
        'routes/api.php' => "<?php\nRoute::get('/users', fn () => []);\n",
        '.env' => "APP_KEY=GIT-SECRET-DO-NOT-STORE\n",
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        config(['features.project_wizard' => true, 'features.source_snapshots' => true, 'features.zip_import' => true, 'features.git_import' => true, 'app.debug' => false]);
        $this->withoutVite();
        Storage::fake('quarantine', ['throw' => true]);
        Storage::fake('snapshots', ['throw' => true]);
        Sleep::fake();
        Event::listen(MessageLogged::class, fn (MessageLogged $e) => $this->logs[] = $e->message.json_encode($e->context));

        $this->configureGitHubApp();
        $this->project = Project::factory()->create();
        $this->developer = $this->memberOf(WorkspaceRole::Developer, $this->project->workspace);
        $this->linkedRepository($this->project);
        $this->fakeGitHub(self::FILES);
    }

    private function import(array $body = ['ref_type' => 'branch', 'ref' => 'main'], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->developer)->post("/projects/{$this->project->id}/github/imports", $body);
    }

    public function test_a_private_repository_branch_becomes_a_ready_snapshot_through_the_existing_importer()
    {
        $this->import()->assertRedirect("/projects/{$this->project->id}");

        $import = GitHubImport::query()->sole();
        $run = ProcessingRun::query()->findOrFail($import->processing_run_id);
        $snapshot = SourceSnapshot::query()->findOrFail($run->source_snapshot_id);

        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame(ProcessingRun::KIND_SNAPSHOT_IMPORT, $run->kind);
        $this->assertSame(SnapshotStatus::Ready, $snapshot->status);
        $this->assertSame(SourceType::Git, $snapshot->source->type);
        $this->assertSame('completed', $import->status);
        $this->assertSame(self::SHA_A, $import->commit_sha);
        $this->assertSame('main', $import->requested_ref);
        $this->assertSame($snapshot->id, $import->source_snapshot_id);
        $this->assertSame(self::SHA_A, GitHubRepositorySource::query()->sole()->last_imported_sha);
        $this->assertSame($import->id, $snapshot->githubImport?->id);

        // GitHub's wrapper folder is dropped; the secret is excluded exactly like a ZIP upload.
        $paths = SourceFile::query()->where('source_snapshot_id', $snapshot->id)->pluck('excluded_reason', 'path')->all();
        $this->assertArrayHasKey('routes/api.php', $paths);
        $this->assertNull($paths['composer.json']);
        $this->assertNotNull($paths['.env']);
        $this->assertStringNotContainsString('GIT-SECRET', implode("\n", array_map(fn ($f) => Storage::disk('snapshots')->get($f), Storage::disk('snapshots')->allFiles())));
        $this->assertSame([], Storage::disk('quarantine')->allFiles()); // archive removed

        foreach (['github.import_started', 'github.import_completed', 'source.snapshot_ready'] as $event) {
            $this->assertTrue(AuditEvent::query()->where('event_type', $event)->exists(), $event);
        }
    }

    public function test_the_snapshot_stays_tied_to_the_resolved_sha_even_if_the_branch_moves_during_import()
    {
        Queue::fake();
        $this->import()->assertRedirect();
        $import = GitHubImport::query()->sole();
        $this->assertSame(self::SHA_A, $import->commit_sha);

        $this->gh['refs']['main'] = self::SHA_B; // main moves before the worker runs
        $this->gh['archives'][self::SHA_B] = ['moved.php' => '<?php // newer'];

        app()->call([new FetchGitHubArchiveJob($import->processing_run_id), 'handle']);
        app(SnapshotImporter::class)->process($import->processing_run_id);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/zipball/'.self::SHA_A));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/zipball/main') || str_contains($r->url(), self::SHA_B));
        $snapshot = ProcessingRun::query()->findOrFail($import->processing_run_id)->snapshot;
        $this->assertTrue(SourceFile::query()->where('source_snapshot_id', $snapshot?->id)->where('path', 'routes/api.php')->exists());
        $this->assertFalse(SourceFile::query()->where('path', 'moved.php')->exists());
    }

    public function test_reimporting_the_same_sha_is_idempotent_and_a_moved_branch_creates_a_new_snapshot()
    {
        $this->import();
        $this->import()->assertSessionHasNoErrors();
        $this->import(['ref_type' => 'commit', 'ref' => self::SHA_A]);
        $this->import(['ref_type' => 'tag', 'ref' => 'v1.0.0']);

        $this->assertSame(1, GitHubImport::query()->count());
        $this->assertSame(1, ProcessingRun::query()->count());
        $this->assertSame(1, SourceSnapshot::query()->count());

        $this->gh['refs']['main'] = self::SHA_B;
        $this->gh['archives'][self::SHA_B] = self::FILES + ['app/New.php' => "<?php\n"];
        $this->import();

        $this->assertSame(2, GitHubImport::query()->count());
        $this->assertSame(2, SourceSnapshot::query()->where('status', SnapshotStatus::Ready->value)->count());
        $this->assertSame(self::SHA_B, GitHubRepositorySource::query()->sole()->last_imported_sha);
    }

    public function test_a_new_sha_with_identical_content_records_its_origin_and_reuses_the_snapshot()
    {
        $this->import();
        $this->gh['refs']['main'] = self::SHA_B; // e.g. an empty commit
        $this->import();

        $this->assertSame(2, GitHubImport::query()->count());
        $this->assertSame(1, SourceSnapshot::query()->count());
        $this->assertSame(1, GitHubImport::query()->distinct()->count('source_snapshot_id'));
    }

    public function test_the_default_branch_and_a_full_commit_sha_resolve_through_github()
    {
        $this->import(['ref_type' => 'default'])->assertRedirect();
        $this->assertSame('main', GitHubImport::query()->sole()->requested_ref);

        $this->import(['ref_type' => 'commit', 'ref' => 'abc'])->assertSessionHasNoErrors();
        $this->assertSame(1, GitHubImport::query()->count()); // short SHA refused before any import
        $this->import(['ref_type' => 'branch', 'ref' => '../../etc'])->assertRedirect();
        $this->import(['ref_type' => 'branch', 'ref' => 'missing'])->assertRedirect();
        $this->assertSame(1, GitHubImport::query()->count());
        $this->import(['ref_type' => 'nonsense', 'ref' => 'x'])->assertSessionHasErrors('ref_type');
    }

    public function test_only_import_roles_may_import_and_other_workspaces_get_404_everywhere()
    {
        $qa = $this->memberOf(WorkspaceRole::QA, $this->project->workspace);
        $this->import(as: $qa)->assertForbidden();

        // StackFood: its own workspace and installation. It cannot see 6amMart's installation, repo, runs or snapshots.
        $other = Project::factory()->create();
        $outsider = $this->memberOf(WorkspaceRole::Owner, $other->workspace);
        $this->linkedRepository($other, installationId: 702, repoId: 9002, fullName: 'stackfood/api');
        $this->gh['installations'][702] = ['repos' => [9002 => 'stackfood/api']];
        $this->import();
        $import = GitHubImport::query()->where('project_id', $this->project->id)->sole();
        $mine = GitHubInstallation::query()->where('github_installation_id', 501)->sole();

        $this->actingAs($outsider);
        $this->getJson("/projects/{$this->project->id}/github/installations")->assertNotFound();
        $this->getJson("/projects/{$this->project->id}/github/installations/{$mine->id}/repositories")->assertNotFound();
        $this->getJson("/projects/{$other->id}/github/installations/{$mine->id}/repositories")->assertNotFound();
        $this->getJson("/projects/{$this->project->id}/github/refs")->assertNotFound();
        $this->post("/projects/{$this->project->id}/github/imports", ['ref_type' => 'default'])->assertNotFound();
        $this->put("/projects/{$other->id}/github/source", ['installation_id' => $mine->id, 'repository_id' => 9001])->assertNotFound();
        $this->getJson("/processing-runs/{$import->processing_run_id}")->assertNotFound();
        $this->get("/projects/{$this->project->id}/snapshots/{$import->source_snapshot_id}")->assertNotFound();
        $this->delete("/workspaces/{$other->workspace_id}/integrations/github/{$mine->id}")->assertNotFound();

        // Its own installation lists only its own repositories.
        $theirs = GitHubInstallation::query()->where('github_installation_id', 702)->sole();
        $this->getJson("/projects/{$other->id}/github/installations")->assertOk()->assertJsonCount(1)->assertJsonPath('0.githubId', 702);
        $this->assertNotSame($mine->id, $theirs->id);
    }

    public function test_a_repository_outside_the_installation_cannot_be_selected_and_leaks_nothing()
    {
        $maintainer = $this->memberOf(WorkspaceRole::Maintainer, $this->project->workspace);
        $installation = GitHubInstallation::query()->sole();

        $response = $this->actingAs($maintainer)->putJson("/projects/{$this->project->id}/github/source", ['installation_id' => $installation->id, 'repository_id' => 123456]);

        $response->assertNotFound()->assertJsonPath('error.code', 'github.repository_not_allowed');
        $this->assertStringNotContainsString('accessible', (string) $response->getContent());

        $this->actingAs($maintainer)->put("/projects/{$this->project->id}/github/source", ['installation_id' => $installation->id, 'repository_id' => 9001])->assertRedirect();
        $this->assertSame(9001, GitHubRepositorySource::query()->sole()->github_repository_id);
        $this->assertTrue(AuditEvent::query()->where('event_type', 'github.repository_selected')->exists());
    }

    public function test_the_picker_lists_installations_repositories_and_refs()
    {
        $installation = GitHubInstallation::query()->sole();
        $this->actingAs($this->developer);

        $this->getJson("/projects/{$this->project->id}/github/installations")->assertOk()->assertJsonPath('0.accountLogin', 'acme');
        $this->getJson("/projects/{$this->project->id}/github/installations/{$installation->id}/repositories")->assertOk()
            ->assertExactJson([['id' => 9001, 'fullName' => 'acme/api', 'visibility' => 'private', 'defaultBranch' => 'main', 'archived' => false]]);
        $this->getJson("/projects/{$this->project->id}/github/refs")->assertOk()
            ->assertJsonPath('branches', ['main', 'feature/x'])->assertJsonPath('tags', ['v1.0.0']);
    }

    public function test_revocation_blocks_new_imports_but_keeps_history_readable()
    {
        $this->import();
        $snapshot = SourceSnapshot::query()->sole();
        $owner = $this->memberOf(WorkspaceRole::Owner, $this->project->workspace);
        $installation = GitHubInstallation::query()->sole();

        $this->actingAs($owner)->delete("/workspaces/{$this->project->workspace_id}/integrations/github/{$installation->id}")->assertRedirect();
        $this->assertTrue(AuditEvent::query()->where('event_type', 'github.installation_disconnected')->exists());

        $this->gh['refs']['main'] = self::SHA_B;
        $this->import()->assertRedirect();
        $this->assertSame(1, GitHubImport::query()->count());
        $this->postJson("/projects/{$this->project->id}/github/imports", ['ref_type' => 'default'])->assertForbidden()->assertJsonPath('error.code', 'github.permission_revoked');

        // Revoked on GitHub's side (installation removed): also refused with a stable code.
        $this->link($this->project->workspace, $installation);
        unset($this->gh['installations'][501]);
        $this->gh['revoked'] = true;
        $this->postJson("/projects/{$this->project->id}/github/imports", ['ref_type' => 'default'])->assertForbidden()->assertJsonPath('error.code', 'github.permission_revoked');

        $this->actingAs($this->developer)->get("/projects/{$this->project->id}/snapshots/{$snapshot->id}")->assertOk();
        $this->assertSame(SnapshotStatus::Ready, $snapshot->refresh()->status);
    }

    public function test_access_revoked_between_request_and_worker_fails_the_run_with_a_stable_code()
    {
        Queue::fake();
        $this->import();
        $import = GitHubImport::query()->sole();
        DB::table('workspace_github_installations')->delete();

        app()->call([new FetchGitHubArchiveJob($import->processing_run_id), 'handle']);

        $this->assertSame('github.permission_revoked', ProcessingRun::query()->findOrFail($import->processing_run_id)->failure_code);
        $this->assertSame('failed', $import->refresh()->status);
        $this->assertSame('github.permission_revoked', $import->failure_code);
        $this->assertTrue(AuditEvent::query()->where('event_type', 'github.import_failed')->exists());
        $this->assertSame([], Storage::disk('quarantine')->allFiles());

        // A retry later reuses the same row and the already-resolved SHA.
        $this->link($this->project->workspace, GitHubInstallation::query()->sole());
        Queue::assertPushed(FetchGitHubArchiveJob::class, 1);
        $this->import();
        $this->assertSame(1, GitHubImport::query()->count());
        $this->assertSame(GitHubImport::QUEUED, $import->refresh()->status);
        $this->assertNotSame($import->getOriginal('processing_run_id'), null);
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function providerFailures(): iterable
    {
        yield 'repository gone (404)' => ['#^/repositories/#', 404, 'github.repository_not_allowed'];
        yield 'commit lookup rate limited (429)' => ['#/commits/#', 429, 'github.rate_limited'];
        yield 'github down (502)' => ['#/commits/#', 502, 'github.provider_unavailable'];
        yield 'forbidden (403)' => ['#/commits/#', 403, 'github.permission_revoked'];
        yield 'token refused (401)' => ['#/commits/#', 401, 'github.permission_revoked'];
    }

    #[DataProvider('providerFailures')]
    public function test_provider_errors_map_to_stable_codes_with_bounded_retries(string $pattern, int $status, string $code)
    {
        $this->gh['fail'] = [$pattern => $status];

        $response = $this->actingAs($this->developer)->postJson("/projects/{$this->project->id}/github/imports", ['ref_type' => 'branch', 'ref' => 'main']);

        $response->assertJsonPath('error.code', $code);
        $this->assertStringNotContainsString(self::INSTALLATION_TOKEN, (string) $response->getContent());
        $this->assertSame(0, GitHubImport::query()->count());
        $this->assertSame(0, ProcessingRun::query()->count());

        $attempts = count(array_filter(Http::recorded()->all(), fn ($pair) => preg_match($pattern, (string) parse_url($pair[0]->url(), PHP_URL_PATH)) === 1));
        $this->assertSame(match ($status) {
            429, 502 => 3, 401 => 2, default => 1
        }, $attempts); // 429/5xx retried twice; 401 once with a fresh token
    }

    public function test_archive_download_failures_fail_the_run_and_leave_no_partial_file()
    {
        $this->gh['fail'] = ['#/zipball/#' => 502];
        $this->import();
        $import = GitHubImport::query()->sole();
        $this->assertSame('github.provider_unavailable', $import->failure_code);
        $this->assertSame('failed', $import->status);
        $this->assertSame(0, SourceSnapshot::query()->where('status', SnapshotStatus::Ready->value)->count());
        $this->assertSame([], Storage::disk('quarantine')->allFiles());

        $this->get("/projects/{$this->project->id}")->assertInertia(fn ($page) => $page
            ->where('github.imports.0.failureCode', 'github.provider_unavailable')
            ->where('sources.latestRun.failureMessage', fn ($m) => str_contains($m, 'GitHub could not be reached')));
    }

    public function test_redirects_to_other_hosts_or_plain_http_are_refused()
    {
        foreach (['https://evil.example.com/x.zip', 'http://codeload.github.com/a/b/legacy.zip/x', 'https://user:pw@codeload.github.com/x'] as $i => $target) {
            $this->gh['redirect_to'] = $target;
            $this->gh['refs']['main'] = str_repeat((string) $i, 40);
            $this->import();
        }

        $this->assertSame(['github.archive_download_failed'], GitHubImport::query()->pluck('failure_code')->unique()->values()->all());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.example.com') || str_starts_with($r->url(), 'http://'));
    }

    public function test_an_archive_over_the_limit_is_stopped_while_streaming()
    {
        config(['sources.zip.max_upload_bytes' => 200]);
        $this->gh['archives'][self::SHA_A] = ['big.php' => str_repeat(bin2hex(random_bytes(64)), 20)];

        $this->import();

        $this->assertSame('github.archive_too_large', GitHubImport::query()->sole()->failure_code);
        $this->assertSame([], Storage::disk('quarantine')->allFiles());
    }

    public function test_no_credential_reaches_jobs_logs_props_audit_or_database()
    {
        Queue::fake();
        $page = $this->import();
        Queue::assertPushed(FetchGitHubArchiveJob::class, function (FetchGitHubArchiveJob $job) {
            $payload = serialize($job);
            $this->assertStringNotContainsString(self::INSTALLATION_TOKEN, $payload);
            $this->assertStringNotContainsString('acme/api', $payload); // ids only

            return true;
        });

        $import = GitHubImport::query()->sole();
        app()->call([new FetchGitHubArchiveJob($import->processing_run_id), 'handle']);
        app(SnapshotImporter::class)->process($import->processing_run_id);

        $props = (string) $this->actingAs($this->developer)->get("/projects/{$this->project->id}")->getContent();
        $everything = $page->getContent().$props.implode("\n", $this->logs).json_encode(array_map(
            fn (string $table) => DB::table($table)->get()->all(),
            ['github_imports', 'github_repository_sources', 'github_installations', 'workspace_github_installations', 'processing_runs', 'audit_events', 'jobs', 'source_snapshots'],
        ));

        foreach ([self::INSTALLATION_TOKEN, self::CODELOAD_TOKEN, 'codeload.github.com', 'CLIENT-SECRET-NEVER-SHOW', 'BEGIN PRIVATE KEY', 'BEGIN RSA PRIVATE KEY'] as $secret) {
            $this->assertStringNotContainsString($secret, $everything, $secret);
        }
        $this->assertSame(RunStatus::Completed, ProcessingRun::query()->sole()->status);
    }

    public function test_the_feature_flag_off_hides_every_github_route_and_zip_still_works()
    {
        config(['features.git_import' => false]);
        $installation = GitHubInstallation::query()->sole();
        $owner = $this->memberOf(WorkspaceRole::Owner, $this->project->workspace);
        $owner->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($owner);

        $this->post("/projects/{$this->project->id}/github/imports", ['ref_type' => 'default'])->assertNotFound();
        $this->getJson("/projects/{$this->project->id}/github/installations")->assertNotFound();
        $this->getJson("/projects/{$this->project->id}/github/installations/{$installation->id}/repositories")->assertNotFound();
        $this->getJson("/projects/{$this->project->id}/github/refs")->assertNotFound();
        $this->get("/workspaces/{$this->project->workspace_id}/integrations/github")->assertNotFound();
        $this->post("/workspaces/{$this->project->workspace_id}/integrations/github/connect")->assertNotFound();
        $this->get('/integrations/github/callback?state=x')->assertNotFound();
        $this->get('/settings/github-app')->assertNotFound();
        $this->get("/projects/{$this->project->id}")->assertInertia(fn ($page) => $page->where('github', null)->where('sources.canUpload', true));

        $this->postJson("/projects/{$this->project->id}/uploads", ['total_bytes' => 100, 'file_name' => 'a.zip'])->assertCreated();
    }

    public function test_without_github_app_settings_imports_fail_with_not_configured_and_zip_still_works()
    {
        DB::table('github_app_settings')->delete();

        $this->actingAs($this->developer)->postJson("/projects/{$this->project->id}/github/imports", ['ref_type' => 'default'])
            ->assertStatus(503)->assertJsonPath('error.code', 'github.not_configured');
        $this->get("/projects/{$this->project->id}")->assertInertia(fn ($page) => $page->where('github.configured', false)->where('sources.canUpload', true));
        $this->postJson("/projects/{$this->project->id}/uploads", ['total_bytes' => 100, 'file_name' => 'a.zip'])->assertCreated();
        Http::assertNothingSent();
    }

    public function test_deleting_the_project_removes_github_rows_and_is_refused_while_a_download_runs()
    {
        Queue::fake();
        $this->import();
        $import = GitHubImport::query()->sole();
        app(SnapshotImporter::class)->claim($import->processing_run_id); // a worker is downloading right now

        $owner = $this->memberOf(WorkspaceRole::Owner, $this->project->workspace);
        try {
            app(ProjectDeleter::class)->delete($owner, $this->project);
            $this->fail('deletion should be refused while a download runs');
        } catch (ProjectError $e) {
            $this->assertSame('project.busy', $e->errorCode());
        }

        app(SnapshotImporter::class)->release($import->processing_run_id);
        app(ProjectDeleter::class)->delete($owner, $this->project);

        $this->assertSame(0, GitHubImport::query()->count());
        $this->assertSame(0, GitHubRepositorySource::query()->count());
        $this->assertSame(0, ProcessingRun::query()->count());
        $this->assertSame(1, GitHubInstallation::query()->count()); // provider identity belongs to the workspace link, not the project
    }

    public function test_the_project_page_shows_the_github_tab_without_credentials()
    {
        $this->import();

        $this->actingAs($this->developer)->get("/projects/{$this->project->id}")->assertInertia(fn ($page) => $page
            ->where('github.configured', true)
            ->where('github.connected', true)
            ->where('github.source.fullName', 'acme/api')
            ->where('github.imports.0.commitSha', self::SHA_A)
            ->where('github.imports.0.requestedRef', 'main')
            ->where('github.imports.0.status', 'completed')
            ->where('github.canImport', true)
            ->where('github.canConnect', false));
    }

    public function test_the_oauth_callback_url_is_the_named_route()
    {
        $this->assertSame(url('/integrations/github/callback'), GitHubConnectionService::callbackUrl());
    }
}
