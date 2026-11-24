<?php

namespace App\Models\Concerns;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For every workspace-owned model. Query with ->forWorkspace($workspace)
 * so a forged ID from another tenant simply isn't found.
 */
trait BelongsToWorkspace
{
    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForWorkspace(Builder $query, Workspace|string $workspace): Builder
    {
        return $query->where(
            $this->qualifyColumn('workspace_id'),
            $workspace instanceof Workspace ? $workspace->getKey() : $workspace,
        );
    }
}
