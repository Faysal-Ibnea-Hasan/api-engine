<?php

namespace Tests\Feature\Sources;

use App\Domain\Projects\ProjectStatus;
use App\Domain\Sources\RunStatus;
use App\Domain\Sources\SnapshotStatus;
use App\Domain\Workspaces\WorkspaceRole;
use App\Jobs\ImportSnapshotJob;
use App\Models\ProcessingRun;
use App\Models\Project;
use App\Models\SourceFile;
use App\Models\SourceSnapshot;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsZips;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

class ZipUploadTest extends TestCase
{
    use BuildsZips, RefreshDatabase, WorksWithWorkspaces;

    private const CHUNK = 65536;

    private Project $project;

    private User $developer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        config([
            'features.project_wizard' => true, 'features.source_snapshots' => true, 'features.zip_import' => true,
            'app.debug' => false, 'sources.zip.chunk_bytes' => self::CHUNK,
        ]);
        $this->withoutVite();
        Storage::fake('quarantine', ['throw' => true]);
        Storage::fake('snapshots', ['throw' => true]);
        @unlink($this->sentinelPath());

        $this->project = Project::factory()->create();
        $this->developer = $this->memberOf(WorkspaceRole::Developer, $this->project->workspace);
    }

    private function url(string $suffix = ''): string
    {
        return "/projects/{$this->project->id}/uploads{$suffix}";
    }

    private function start(int $bytes, string $name = 'project.zip'): TestResponse
    {
        return $this->postJson($this->url(), ['total_bytes' => $bytes, 'file_name' => $name]);
    }

    private function sendChunk(string $id, int $offset, string $bytes): TestResponse
    {
        return $this->call('PUT', $this->url("/{$id}/chunk?offset={$offset}"), [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream',
            'HTTP_ACCEPT' => 'application/json',
        ], $bytes);
    }

    /** Uploads a whole file the way the browser does: start, pieces in order, complete. */
    private function uploadFile(string $zipPath): TestResponse
    {
        $bytes = (string) file_get_contents($zipPath);
        $started = $this->start(strlen($bytes))->assertCreated();
        // The browser sends its first piece at exactly this offset; null would mean "no offset".
        $this->assertSame(0, $started->json('receivedBytes'));
        $id = $started->json('id');

        foreach (str_split($bytes, self::CHUNK) as $i => $piece) {
            $this->sendChunk($id, $i * self::CHUNK, $piece)->assertOk();
        }

        return $this->postJson($this->url("/{$id}/complete"));
    }

    private function latestRun(): ProcessingRun
    {
        return ProcessingRun::query()->where('project_id', $this->project->id)->latest('created_at')->firstOrFail();
    }

    public function test_a_nested_laravel_zip_uploaded_in_pieces_becomes_a_ready_snapshot_without_running_anything()
    {
        $zip = $this->laravelZip(vendorFiles: 2000);
        $this->assertGreaterThan(self::CHUNK * 2, filesize($zip), 'the test really spans several pieces');

        $this->actingAs($this->developer)->uploadFile($zip)->assertOk()->assertJsonPath('alreadyRunning', false);

        $run = $this->latestRun();
        $this->assertSame(RunStatus::Completed, $run->status);
        $snapshot = $run->snapshot;
        $this->assertSame(SnapshotStatus::Ready, $snapshot?->status);
        $this->assertSame(ProjectStatus::Active, $this->project->fresh()->status);
        $this->assertSame(4, $snapshot->file_count);
        $this->assertSame(3, $snapshot->excluded_count, 'vendor/ as one entry, .env, nested archive');
        $this->assertSame('dependency_or_vcs', SourceFile::query()->where('path', 'myapp/vendor/')->value('excluded_reason'));

        $this->assertFileDoesNotExist($this->sentinelPath(), 'uploaded PHP was executed');
        $stored = implode('', array_map(fn ($f) => Storage::disk('snapshots')->get($f), Storage::disk('snapshots')->allFiles()));
        $this->assertStringNotContainsString('ZIP-SECRET-DO-NOT-STORE', $stored);
        $this->assertStringNotContainsString('ZIP-NESTED-CONTENT', $stored);
        $this->assertSame([], Storage::disk('quarantine')->allFiles(), 'pieces, archive and extracted files are deleted');

        $this->get("/projects/{$this->project->id}")->assertInertia(fn ($page) => $page
            ->where('sources.rootCandidates', ['myapp'])
            ->where('sources.canUpload', true)
            ->where('sources.maxUploadBytes', 10 * 1024 * 1024 * 1024)
            ->where('sources.maxSourceBytes', 1024 * 1024 * 1024));
    }

    public function test_an_interrupted_upload_resumes_from_what_the_server_received()
    {
        $bytes = (string) file_get_contents($this->laravelZip(vendorFiles: 2000));
        $pieces = str_split($bytes, self::CHUNK);
        $this->actingAs($this->developer);
        $id = $this->start(strlen($bytes))->json('id');

        $this->sendChunk($id, 0, $pieces[0])->assertOk();
        // Connection drops; the page reloads and asks where to continue.
        $this->getJson($this->url("/{$id}"))->assertOk()->assertJsonPath('receivedBytes', self::CHUNK);

        // A stale retry of piece 0, and a skipped-ahead piece, are both refused without writing.
        $this->sendChunk($id, 0, $pieces[0])->assertStatus(409)->assertJsonPath('error.code', 'upload.offset_mismatch');
        $this->sendChunk($id, self::CHUNK * 2, $pieces[2])->assertStatus(409);
        $this->postJson($this->url("/{$id}/complete"))->assertStatus(409)->assertJsonPath('error.code', 'upload.incomplete');

        foreach (array_slice($pieces, 1, null, true) as $i => $piece) {
            $this->sendChunk($id, $i * self::CHUNK, $piece)->assertOk();
        }
        $this->postJson($this->url("/{$id}/complete"))->assertOk();

        $this->assertSame(SnapshotStatus::Ready, $this->latestRun()->snapshot?->status, 'resumed bytes are intact');
    }

    public function test_pieces_that_are_too_big_or_past_the_declared_size_are_refused()
    {
        $this->actingAs($this->developer);
        $id = $this->start(100)->json('id');

        $this->sendChunk($id, 0, str_repeat('x', self::CHUNK + 1))->assertStatus(413);
        $this->sendChunk($id, 0, str_repeat('x', 101))->assertStatus(422)->assertJsonPath('error.code', 'upload.too_much_data');
        $this->sendChunk($id, 0, '')->assertStatus(422);
        $this->getJson($this->url("/{$id}"))->assertJsonPath('receivedBytes', 0);
        $this->assertSame(0, Storage::disk('quarantine')->size("uploads/{$id}/archive.part"), 'rejected bytes were rolled back');
    }

    public function test_hostile_archives_fail_with_a_specific_safe_message_and_no_snapshot()
    {
        $cases = [
            'unsafe_path' => $this->zip(['ok.php' => '<?php', '../evil.php' => 'x']),
            'compression_ratio' => $this->zip(['zeros.txt' => str_repeat('0', 4 * 1048576)]),
            'unsupported_format' => tap(tempnam(sys_get_temp_dir(), 'txt'), fn ($p) => file_put_contents($p, 'not a zip')),
        ];

        foreach ($cases as $code => $zip) {
            $this->actingAs($this->developer)->uploadFile($zip)->assertOk();
            $run = $this->latestRun();

            $this->assertSame(RunStatus::Failed, $run->status, $code);
            $this->assertSame($code, $run->failure_code);
            $this->getJson("/processing-runs/{$run->id}")->assertJsonPath('failureCode', $code)
                ->assertJsonPath('failureMessage', fn ($m) => is_string($m) && ! str_contains($m, 'evil'));
        }

        $this->assertSame(0, SourceSnapshot::query()->where('status', 'ready')->count());
        $this->assertSame([], Storage::disk('quarantine')->allFiles());
        $this->assertDatabaseHas('audit_events', ['event_type' => 'source.import_failed']);
    }

    public function test_finishing_twice_returns_the_same_import()
    {
        Queue::fake();
        $this->actingAs($this->developer);
        $bytes = (string) file_get_contents($this->zip(['a.php' => '<?php']));
        $id = $this->start(strlen($bytes))->json('id');
        $this->sendChunk($id, 0, $bytes)->assertOk();

        $first = $this->postJson($this->url("/{$id}/complete"))->assertOk()->json('runId');
        $second = $this->postJson($this->url("/{$id}/complete"))->assertOk();

        $this->assertSame($first, $second->json('runId'));
        $this->assertTrue($second->json('alreadyRunning'));
        $this->assertSame(1, ProcessingRun::query()->count());
        Queue::assertPushed(ImportSnapshotJob::class, 1);
    }

    public function test_the_declared_size_and_name_are_checked_before_anything_is_stored()
    {
        $this->actingAs($this->developer);

        $this->start(10 * 1024 * 1024 * 1024 + 1)->assertJsonValidationErrors('total_bytes');
        $this->start(1000, 'notes.txt')->assertJsonValidationErrors('file_name');
        $this->start(0)->assertJsonValidationErrors('total_bytes');
        $this->start(10 * 1024 * 1024 * 1024)->assertCreated(); // exactly 10 GB is allowed

        $this->assertSame(1, UploadSession::query()->count());
    }

    public function test_only_the_uploader_with_permission_can_use_an_upload()
    {
        $this->actingAs($this->developer);
        $id = $this->start(100)->json('id');
        $missing = (string) Str::uuid();

        foreach ([WorkspaceRole::QA, WorkspaceRole::Viewer] as $role) {
            $this->actingAs($this->memberOf($role, $this->project->workspace))->start(100)->assertForbidden();
        }

        // A colleague who may change the project still can't touch someone else's upload.
        $this->actingAs($this->memberOf(WorkspaceRole::Developer, $this->project->workspace));
        $this->sendChunk($id, 0, 'x')->assertNotFound();
        $this->postJson($this->url("/{$id}/complete"))->assertNotFound();

        // Another tenant: identical to a missing project or upload, even with an invalid body.
        $this->actingAs($this->memberOf(WorkspaceRole::Owner));
        $this->assertSame(
            $this->postJson("/projects/{$missing}/uploads", [])->assertNotFound()->json(),
            $this->postJson($this->url(), [])->assertNotFound()->json(),
        );
        $this->getJson($this->url("/{$id}"))->assertNotFound();

        $this->project->forceFill(['status' => ProjectStatus::Archived, 'status_before_archive' => ProjectStatus::Draft])->save();
        $this->actingAs($this->developer)->start(100)->assertStatus(409)->assertJsonPath('error.code', 'project.archived');
    }

    public function test_unfinished_uploads_expire_and_are_deleted()
    {
        $this->actingAs($this->developer);
        $id = $this->start(100)->json('id');
        $this->sendChunk($id, 0, 'partial')->assertOk();
        UploadSession::query()->whereKey($id)->update(['expires_at' => now()->subMinute()]);

        $this->sendChunk($id, 7, 'more')->assertStatus(409)->assertJsonPath('error.code', 'upload.not_active');
        $this->artisan('app:prune-sources')->assertSuccessful();

        $this->assertSame(UploadSession::EXPIRED, UploadSession::query()->findOrFail($id)->status);
        $this->assertSame([], Storage::disk('quarantine')->allFiles());
    }

    public function test_the_flag_hides_uploads()
    {
        config(['features.zip_import' => false]);

        $this->actingAs($this->developer)->start(100)->assertNotFound();
        $this->get("/projects/{$this->project->id}")->assertInertia(fn ($page) => $page->where('sources.canUpload', false));
    }

    public function test_a_laravel_zip_with_a_storage_link_still_imports()
    {
        $this->actingAs($this->developer)->uploadFile($this->zip([
            'artisan' => '<?php',
            'composer.json' => '{}',
            'public/storage' => ['../storage/app/public', 0120777],
        ]))->assertOk();

        $this->assertSame(SnapshotStatus::Ready, $this->latestRun()->snapshot?->status);
        $this->assertSame('ignored_directory', SourceFile::query()->where('path', 'public/')->value('excluded_reason'));
    }

    public function test_sending_many_pieces_does_not_use_up_the_limit_for_starting_or_finishing()
    {
        $this->actingAs($this->developer);
        $id = $this->start(100)->json('id');

        for ($i = 0; $i < 40; $i++) { // more than the start (10) and complete (30) limits
            $this->sendChunk($id, $i, 'x')->assertOk();
        }

        $this->start(100)->assertCreated();
        $this->getJson($this->url("/{$id}"))->assertOk();
        $this->postJson($this->url("/{$id}/complete"))->assertStatus(409)->assertJsonPath('error.code', 'upload.incomplete'); // not 429
    }

    public function test_starting_uploads_is_limited_on_its_own_counter()
    {
        $this->actingAs($this->developer);

        for ($i = 0; $i < 10; $i++) {
            $this->start(100)->assertCreated();
        }

        $this->start(100)->assertStatus(429);
    }
}
