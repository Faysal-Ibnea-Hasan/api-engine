<?php

namespace App\Contracts\Sources;

use App\Models\Project;
use App\Models\User;

/**
 * SHARED_CONTRACTS §4. ZIP (P04) and Git (P09) implement this. A provider stages
 * files into quarantine and hands off to SnapshotImporter; consumers only ever see
 * the resulting snapshot ID, never a branch, a path or credentials.
 */
interface SourceProvider
{
    /** @param  array<string, mixed>  $configuration */
    public function import(User $actor, Project $project, array $configuration): SourceImportResult;
}
