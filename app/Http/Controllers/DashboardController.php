<?php

namespace App\Http\Controllers;

use App\Domain\Projects\ProjectStatus;
use App\Models\Project;
use App\Support\Features;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // Project data needs both flags, like the project routes (projects R26).
        $membership = Features::enabled('workspace_management') && Features::enabled('project_wizard')
            ? $request->user()->currentMembership()
            : null;

        return Inertia::render('dashboard', $membership === null ? [] : [
            // Same tenant and visibility scopes as ProjectController@index (projects R5, R11).
            'recentProjects' => Project::query()
                ->forWorkspace($membership->workspace_id)
                ->visibleTo($request->user(), $membership->role)
                ->where('status', '!=', ProjectStatus::Archived)
                ->latest('updated_at')
                ->limit(5)
                ->get()
                ->map(fn (Project $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'status' => $p->status->value,
                    'visibility' => $p->visibility->value,
                    'setupComplete' => $p->wizard_completed_at !== null,
                    'updatedAt' => $p->updated_at?->toIso8601String(),
                ]),
        ]);
    }
}
