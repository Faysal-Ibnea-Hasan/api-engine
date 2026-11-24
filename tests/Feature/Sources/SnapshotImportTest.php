<?php

namespace Tests\Feature\Sources;

use App\Domain\Projects\ProjectError;
use App\Domain\Projects\ProjectStatus;
use App\Domain\Sources\FixtureSourceProvider;
use App\Domain\Sources\RunStatus;
use App\Domain\Sources\SnapshotImporter;
use App\Domain\Sources\SnapshotStatus;
use App\Domain\Sources\SourceType;
use App\Domain\Workspaces\WorkspaceRole;
use App\Jobs\ImportSnapshotJob;
use App\Models\ProcessingRun;
use App\Models\Project;
use App\Models\SourceFile;
use App\Models\SourceSnapshot;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

class SnapshotImportTest extends TestCase
{
    use RefreshDatabase, WorksWithWorkspaces;

    private Project $project;

    private User $developer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('quarantine', ['throw' => true]);
        Storage::fake('snapshots', ['throw' => true]);

        $this->project = Project::factory()->create();
        $this->developer = $this->memberOf(WorkspaceRole::Developer, $this->project->workspace);
    }

    protected function tearDown(): void
    {
        @chmod(Storage::disk('snapshots')->path(''), 0755);
        parent::tearDown();
    }

    private function importFixture(): ProcessingRun
    {
        $result = app(FixtureSourceProvider::class)->import($this->developer, $this->project, ['fixture' => 'laravel-mini']);

        return ProcessingRun::query()->findOrFail($result->runId);
    }

    /** @param  array<string, string>  $files */
    private function importFiles(array $files, string $fingerprint): ProcessingRun
    {
        [$run] = app(SnapshotImporter::class)->begin($this->developer, $this->project, SourceType::Zip, $fingerprint, function (Filesystem $q, string $dir) use ($files) {
            foreach ($files as $path => $content) {
                $q->put("{$dir}/{$path}", $content);
            }
        });

        return $run->refresh();
    }

    private function allStoredBytes(): string
    {
        $disk = Storage::disk('snapshots');

        return implode("\n", array_map(fn ($f) => $disk->get($f), $disk->allFiles()));
    }

    public function test_a_fixture_becomes_a_ready_immutable_snapshot_and_activates_the_project()
    {
        $run = $this->importFixture();
        $snapshot = $run->snapshot;

        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertNull($run->active_key);
        $this->assertSame(SnapshotStatus::Ready, $snapshot->status);
        $this->assertSame(64, strlen((string) $snapshot->sha256));
        $this->assertSame(ProjectStatus::Active, $this->project->fresh()->status);

        $files = SourceFile::query()->where('source_snapshot_id', $snapshot->id)->pluck('excluded_reason', 'path')->all();
        $this->assertEqualsCanonicalizing(
            ['README.md', 'app/Http/Controllers/UserController.php', 'artisan', 'composer.json', 'routes/api.php'],
            array_keys(array_filter($files, fn ($r) => $r === null)),
        );
        $this->assertSame('secret', $files['auth.json']);
        $this->assertSame('dependency_or_vcs', $files['vendor/laravel/framework/Foo.php']);
        $this->assertSame('dependency_or_vcs', $files['node_modules/left-pad/index.js']);
        $this->assertSame('log', $files['storage/logs/laravel.log']);
        $this->assertSame(5, $snapshot->file_count);
        $this->assertSame(4, $snapshot->excluded_count);

        $this->assertStringNotContainsString('FIXTURE-SECRET-DO-NOT-STORE', $this->allStoredBytes());
        $this->assertArrayNotHasKey('storage_key', $snapshot->toArray());
        $this->assertSame([], Storage::disk('quarantine')->allFiles(), 'quarantine is cleaned');
        $this->assertDatabaseHas('audit_events', ['event_type' => 'source.snapshot_ready']);
    }

    public function test_a_ready_snapshot_cannot_be_changed_or_deleted()
    {
        $snapshot = $this->importFixture()->snapshot;

        try {
            $snapshot->forceFill(['file_count' => 999])->save();
            $this->fail('update allowed');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $snapshot->delete();
    }

    public function test_reimporting_identical_content_reuses_the_snapshot()
    {
        $first = $this->importFixture();
        $second = $this->importFixture();

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($first->source_snapshot_id, $second->source_snapshot_id);
        $this->assertTrue($second->deduplicated);
        $this->assertSame(1, SourceSnapshot::query()->count(), 'the placeholder was dropped');
    }

    public function test_the_hash_ignores_file_order_timestamps_and_excluded_files()
    {
        $a = $this->importFiles(['composer.json' => '{}', 'routes/api.php' => '<?php // a', '.env' => 'APP_KEY=one'], 'a');
        sleep(1); // different mtimes
        $b = $this->importFiles(['.env' => 'APP_KEY=two', 'routes/api.php' => '<?php // a', 'composer.json' => '{}'], 'b');

        $this->assertSame($a->source_snapshot_id, $b->source_snapshot_id);
        $this->assertTrue($b->deduplicated);
        $this->assertStringNotContainsString('APP_KEY', $this->allStoredBytes());
        $this->assertSame('secret', SourceFile::query()->where('path', '.env')->value('excluded_reason'));
    }

    public function test_the_same_fingerprint_while_running_returns_the_running_import()
    {
        Queue::fake();

        $first = $this->importFiles(['a.php' => '<?php'], 'same');
        $second = $this->importFiles(['a.php' => '<?php'], 'same');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ProcessingRun::query()->count());
        Queue::assertPushed(ImportSnapshotJob::class, 1);
        Queue::assertPushedOn('imports', ImportSnapshotJob::class);
    }

    public function test_a_symlink_or_too_many_files_rejects_the_snapshot()
    {
        Queue::fake();
        $run = $this->importFiles(['a.php' => '<?php'], 'link');
        $root = Storage::disk('quarantine')->path("runs/{$run->id}/files");
        symlink('/etc/hosts', "{$root}/hosts");

        app(SnapshotImporter::class)->process($run->id);

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('symlink', $run->failure_code);
        $this->assertSame(SnapshotStatus::Rejected, $run->snapshot?->status);

        config(['sources.max_files' => 1]);
        $many = $this->importFiles(['a.php' => '1', 'b.php' => '2'], 'many');
        app(SnapshotImporter::class)->process($many->id);
        $this->assertSame('too_many_files', $many->refresh()->failure_code);
        $this->assertSame(0, SourceSnapshot::query()->where('status', SnapshotStatus::Ready->value)->count());
    }

    public function test_a_storage_failure_never_marks_ready_and_a_retry_recovers_cleanly()
    {
        Queue::fake();
        $run = $this->importFiles(['a.php' => '<?php // a', 'b.php' => '<?php // b'], 'retry');
        $snapshotsRoot = Storage::disk('snapshots')->path('');
        chmod($snapshotsRoot, 0555);

        try {
            app(SnapshotImporter::class)->process($run->id);
            $this->fail('expected a storage error');
        } catch (RuntimeException) {
        }

        $run->refresh();
        $this->assertSame(RunStatus::Running, $run->status, 'still retryable');
        $this->assertNull($run->claimed_at, 'claim released for the retry');
        $this->assertNotSame(SnapshotStatus::Ready, $run->snapshot?->status);

        chmod($snapshotsRoot, 0755);
        app(SnapshotImporter::class)->process($run->id);

        $run->refresh();
        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame(2, $run->attempts);
        $this->assertSame(1, SourceSnapshot::query()->where('status', SnapshotStatus::Ready->value)->count());
        $this->assertSame(2, SourceFile::query()->count(), 'no duplicate manifest rows');
    }

    public function test_when_retries_run_out_the_run_and_snapshot_fail_and_storage_is_cleaned()
    {
        Queue::fake();
        $run = $this->importFiles(['a.php' => '<?php'], 'giveup');

        (new ImportSnapshotJob($run->id))->failed(new RuntimeException('disk full at /secret/path'));

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('internal', $run->failure_code);
        $this->assertNull($run->active_key, 'the fingerprint can be imported again');
        $this->assertSame(SnapshotStatus::Failed, $run->snapshot?->status);
        $this->assertSame([], Storage::disk('quarantine')->allFiles());
        $this->assertStringNotContainsString('/secret/path', (string) json_encode($run->toArray()));
    }

    public function test_a_run_claimed_by_another_worker_is_left_alone()
    {
        Queue::fake();
        $run = $this->importFiles(['a.php' => '<?php'], 'claimed');
        ProcessingRun::query()->whereKey($run->id)->update(['status' => 'running', 'claimed_at' => now()]);

        app(SnapshotImporter::class)->process($run->id);

        $this->assertSame(SnapshotStatus::Pending, $run->snapshot?->fresh()?->status);
    }

    public function test_cancelling_a_queued_import_stops_it()
    {
        Queue::fake();
        $run = $this->importFiles(['a.php' => '<?php'], 'cancel');

        app(SnapshotImporter::class)->cancel($run);
        app(SnapshotImporter::class)->process($run->id);

        $run->refresh();
        $this->assertSame(RunStatus::Cancelled, $run->status);
        $this->assertSame('cancelled', $run->failure_code);
        $this->assertSame(SnapshotStatus::Failed, $run->snapshot?->status);
    }

    public function test_archived_projects_and_unknown_fixtures_are_refused()
    {
        try {
            app(FixtureSourceProvider::class)->import($this->developer, $this->project, ['fixture' => '../../etc']);
            $this->fail('unknown fixture accepted');
        } catch (InvalidArgumentException) {
        }

        $this->project->forceFill(['status' => ProjectStatus::Archived, 'status_before_archive' => ProjectStatus::Draft])->save();
        $this->expectException(ProjectError::class);
        $this->importFixture();
    }

    public function test_a_successful_retry_keeps_only_its_own_storage_folder()
    {
        Queue::fake();
        $run = $this->importFiles(['a.php' => '<?php // a'], 'folders');
        $snapshot = $run->snapshot;
        $dir = app(SnapshotImporter::class)->snapshotDir($snapshot);
        Storage::disk('snapshots')->put("{$dir}/earlier-attempt/blobs/x", 'partial');

        app(SnapshotImporter::class)->process($run->id);

        $snapshot->refresh();
        $this->assertSame([$snapshot->storage_key], Storage::disk('snapshots')->directories($dir));
    }

    public function test_decomposed_unicode_file_names_are_stored_under_their_nfc_path()
    {
        $nfd = "cafe\u{0301}.php";
        $run = $this->importFiles([$nfd => '<?php // accent'], 'nfd');

        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame("caf\u{00E9}.php", SourceFile::query()->value('path'));
    }

    public function test_a_final_failure_after_the_snapshot_became_ready_completes_the_run()
    {
        Queue::fake();
        $run = $this->importFiles(['a.php' => '<?php'], 'late-error');
        app(SnapshotImporter::class)->process($run->id);
        ProcessingRun::query()->whereKey($run->id)->update(['status' => 'running', 'active_key' => 'x', 'finished_at' => null]);

        (new ImportSnapshotJob($run->id))->failed(new RuntimeException('boom'));

        $this->assertSame(RunStatus::Completed, $run->refresh()->status);
        $this->assertSame(SnapshotStatus::Ready, $run->snapshot?->status);
    }

    public function test_queue_timeouts_are_ordered_so_a_slow_job_is_never_run_twice()
    {
        $job = SnapshotImporter::JOB_TIMEOUT;
        $horizon = (int) config('horizon.defaults.supervisor-1.timeout');

        $this->assertGreaterThan($job, $horizon);
        foreach (['database', 'redis'] as $connection) {
            $this->assertGreaterThan($horizon, (int) config("queue.connections.{$connection}.retry_after"), $connection);
        }
    }
}
