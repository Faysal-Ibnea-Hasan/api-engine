<?php

namespace App\Domain\Sources;

use App\Models\ProcessingRun;
use App\Models\Project;
use App\Models\SourceSnapshot;

/** The snapshot of a project's latest completed import (re-imports of identical content reuse an older snapshot). */
final class LatestSnapshot
{
    public static function of(Project $project): ?SourceSnapshot
    {
        $id = ProcessingRun::query()->where('project_id', $project->id)
            ->where('kind', ProcessingRun::KIND_SNAPSHOT_IMPORT)
            ->where('status', RunStatus::Completed->value)
            ->latest('finished_at')->latest('id')
            ->value('source_snapshot_id');

        return $id === null ? null : SourceSnapshot::query()->whereKey($id)->where('project_id', $project->id)->where('status', SnapshotStatus::Ready->value)->first();
    }
}
