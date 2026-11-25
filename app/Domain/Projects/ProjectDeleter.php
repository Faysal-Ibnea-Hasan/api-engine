<?php

namespace App\Domain\Projects;

use App\Domain\Analysis\EndpointAnalyses;
use App\Domain\Audit\AuditLog;
use App\Domain\Discovery\RouteScans;
use App\Domain\Sources\RunStatus;
use App\Domain\Sources\SnapshotImporter;
use App\Models\ApiDependency;
use App\Models\ApiEndpoint;
use App\Models\DocumentationVersion;
use App\Models\EndpointAnalysis;
use App\Models\FrameworkDetection;
use App\Models\GitHubImport;
use App\Models\GitHubRepositorySource;
use App\Models\ProcessingRun;
use App\Models\Project;
use App\Models\ProjectSource;
use App\Models\RouteScan;
use App\Models\SourceFile;
use App\Models\SourceSnapshot;
use App\Models\UploadSession;
use App\Models\User;
use App\Models\WorkflowExplanation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Permanently deletes a project (active, draft or archived) and everything imported for it.
 * The only code allowed to remove ready snapshots: it uses query-level deletes on purpose,
 * because the model guard exists to stop *accidental* changes, not this explicit removal.
 *
 * Database rows go first, in one transaction; stored files after it. A file that fails to
 * delete is reported (orphaned bytes), never left referenced by a row.
 */
final class ProjectDeleter
{
    public function delete(User $actor, Project $project): void
    {
        $workspace = $project->workspace;
        $storage = [];
        $quarantine = [];

        DB::transaction(function () use ($project, &$storage, &$quarantine) {
            Project::query()->lockForUpdate()->findOrFail($project->id);

            // Only work a worker is processing right now blocks deletion (a GitHub archive download claims its run the same way). Queued work no worker has
            // started, abandoned claims and unfinished uploads are simply discarded with the project:
            // a job that starts later finds no row to claim and stops.
            $busy = ProcessingRun::query()->where('project_id', $project->id)
                ->where('status', RunStatus::Running->value)
                ->where('claimed_at', '>', now()->subSeconds(SnapshotImporter::JOB_TIMEOUT))->exists()
                || RouteScan::query()->where('project_id', $project->id)
                    ->where('status', RunStatus::Running->value)
                    ->where('claimed_at', '>', now()->subSeconds(RouteScans::JOB_TIMEOUT))->exists()
                || EndpointAnalysis::query()->where('project_id', $project->id)
                    ->where('status', RunStatus::Running->value)
                    ->where('claimed_at', '>', now()->subSeconds(EndpointAnalyses::JOB_TIMEOUT))->exists();

            if ($busy) {
                throw ProjectError::busy();
            }

            // Everything on disk lives under these server-generated keys; collect before the rows go.
            $storage[] = "{$project->workspace_id}/{$project->id}";
            foreach (ProcessingRun::query()->where('project_id', $project->id)->pluck('id') as $runId) {
                $quarantine[] = "runs/{$runId}";
            }
            foreach (UploadSession::query()->where('project_id', $project->id)->pluck('id') as $uploadId) {
                $quarantine[] = "uploads/{$uploadId}";
            }

            DocumentationVersion::query()->where('project_id', $project->id)->delete();  // before analyses: they restrict deletes
            ApiDependency::query()->where('project_id', $project->id)->delete();
            WorkflowExplanation::query()->where('project_id', $project->id)->delete();
            EndpointAnalysis::query()->where('project_id', $project->id)->delete();      // evidence and findings cascade
            $scans = RouteScan::query()->where('project_id', $project->id)->pluck('id');
            ApiEndpoint::query()->whereIn('route_scan_id', $scans)->delete();           // findings cascade with scans
            RouteScan::query()->where('project_id', $project->id)->delete();
            FrameworkDetection::query()->where('project_id', $project->id)->delete();
            UploadSession::query()->where('project_id', $project->id)->delete();
            GitHubImport::query()->where('project_id', $project->id)->delete();           // before runs, snapshots and sources: they restrict deletes
            GitHubRepositorySource::query()->where('project_id', $project->id)->delete();
            ProcessingRun::query()->where('project_id', $project->id)->delete();

            $snapshots = SourceSnapshot::query()->where('project_id', $project->id)->pluck('id');
            SourceFile::query()->whereIn('source_snapshot_id', $snapshots)->delete();
            SourceSnapshot::query()->where('project_id', $project->id)->delete();       // bypasses the ready guard on purpose
            ProjectSource::query()->where('project_id', $project->id)->delete();
            Project::query()->whereKey($project->id)->delete();
        });

        foreach ($storage as $dir) {
            $this->wipe('snapshots', $dir);
        }
        foreach ($quarantine as $dir) {
            $this->wipe('quarantine', $dir);
        }

        AuditLog::record('project.deleted', $workspace, $actor, null, [
            'project_id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
        ]);
    }

    private function wipe(string $disk, string $dir): void
    {
        try {
            Storage::disk($disk)->deleteDirectory($dir);
        } catch (Throwable $e) {
            report($e); // rows are already gone, so nothing points at these bytes any more
        }
    }
}
