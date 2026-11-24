<?php

namespace App\Http\Controllers\Sources;

use App\Domain\Framework\FrameworkDetections;
use App\Domain\Sources\SnapshotStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Framework\DetectionPresenter;
use App\Models\Project;
use App\Models\SourceFile;
use App\Models\SourceSnapshot;
use App\Support\Features;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SnapshotController extends Controller
{
    /** Metadata for anyone who can see the project; the file list only with ReadSourceEvidence. */
    public function show(Request $request, Project $project, SourceSnapshot $snapshot, FrameworkDetections $detections): Response
    {
        Gate::authorize('view', $project);
        $canSeeFiles = $request->user()->can('viewSource', $project) && $snapshot->status === SnapshotStatus::Ready;
        $snapshot->load('source');

        return Inertia::render('projects/snapshot', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'snapshot' => SourcePresenter::snapshot($snapshot),
            'detection' => fn () => $this->detection($request, $project, $snapshot, $detections),
            'files' => $canSeeFiles
                ? SourceFile::query()->where('source_snapshot_id', $snapshot->id)->orderBy('path')
                    ->paginate(100, ['path', 'bytes', 'language', 'excluded_reason'])
                    ->through(fn (SourceFile $f) => [
                        'path' => $f->path,
                        'bytes' => $f->bytes,
                        'language' => $f->language,
                        'excludedReason' => $f->excluded_reason,
                    ])
                : null,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function detection(Request $request, Project $project, SourceSnapshot $snapshot, FrameworkDetections $detections): ?array
    {
        if (! Features::enabled('framework_detection') || $snapshot->status !== SnapshotStatus::Ready) {
            return null;
        }

        $current = $detections->current($snapshot);
        $canManage = $request->user()->can('update', $project) && ! $project->isArchived();

        return [
            'result' => $current === null ? null : DetectionPresenter::present($current, $request->user()->can('viewSource', $project)),
            'canDetect' => $canManage,
            'canSelectRoot' => $canManage && $current !== null,
        ];
    }
}
