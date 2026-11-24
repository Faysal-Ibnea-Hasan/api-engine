<?php

namespace Tests\Feature\Sources;

use App\Domain\Sources\FixtureSourceProvider;
use App\Domain\Sources\RunStatus;
use App\Domain\Sources\SnapshotImporter;
use App\Domain\Sources\SourceType;
use App\Domain\Workspaces\WorkspaceRole;
use App\Models\ProcessingRun;
use App\Models\Project;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

class PruneSourcesTest extends TestCase
{
    use RefreshDatabase, WorksWithWorkspaces;

    public function test_it_fails_abandoned_runs_and_cleans_leftovers_but_never_a_ready_snapshot()
    {
        Storage::fake('quarantine', ['throw' => true]);
        Storage::fake('snapshots', ['throw' => true]);
        $project = Project::factory()->create();
        $dev = $this->memberOf(WorkspaceRole::Developer, $project->workspace);

        $ready = ProcessingRun::query()->findOrFail(app(FixtureSourceProvider::class)->import($dev, $project, ['fixture' => 'laravel-mini'])->runId);
        $readyKey = $ready->snapshot?->storage_key;
        $readyFiles = Storage::disk('snapshots')->allFiles($readyKey);
        $this->assertNotEmpty($readyFiles);

        Queue::fake();
        $stage = fn (Filesystem $q, string $dir) => $q->put("{$dir}/a.php", '<?php');
        [$abandoned] = app(SnapshotImporter::class)->begin($dev, $project, SourceType::Zip, 'lost', $stage);
        [$fresh] = app(SnapshotImporter::class)->begin($dev, $project, SourceType::Zip, 'fresh', $stage);
        ProcessingRun::query()->whereKey($abandoned->id)->update(['status' => 'running', 'updated_at' => now()->subHours(5)]);
        Storage::disk('quarantine')->put('runs/orphan-run/files/x.php', 'left behind');

        $this->artisan('app:prune-sources')->assertSuccessful();

        $abandoned->refresh();
        $this->assertSame(RunStatus::Failed, $abandoned->status);
        $this->assertSame('worker_lost', $abandoned->failure_code);
        $this->assertNull($abandoned->active_key);
        $this->assertFalse(Storage::disk('quarantine')->exists('runs/orphan-run'));
        $this->assertTrue(Storage::disk('quarantine')->exists("runs/{$fresh->id}/files/a.php"), 'an active, fresh import keeps its files');
        $this->assertSame($readyFiles, Storage::disk('snapshots')->allFiles($readyKey), 'ready snapshot untouched');
    }
}
