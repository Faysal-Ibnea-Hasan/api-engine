<?php

namespace App\Http\Controllers\Projects;

use App\Domain\Framework\FrameworkDetections;
use App\Domain\Projects\ProjectDeleter;
use App\Domain\Projects\ProjectOptions;
use App\Domain\Projects\ProjectService;
use App\Domain\Projects\ProjectStatus;
use App\Domain\Sources\LatestSnapshot;
use App\Domain\Workspaces\Capability;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Discovery\EndpointPresenter;
use App\Http\Controllers\Framework\DetectionPresenter;
use App\Http\Controllers\Sources\SourcePresenter;
use App\Http\Requests\Projects\DeleteProjectRequest;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectDetailsRequest;
use App\Models\Project;
use App\Support\Features;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /** Projects of the user's current workspace that they may see. */
    public function index(Request $request): Response|RedirectResponse
    {
        $membership = $request->user()->currentMembership();

        if ($membership === null) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('Create or join a workspace first.')]);

            return to_route('workspaces.index');
        }

        $search = trim($request->string('q')->value());
        $archived = $request->boolean('archived');

        $projects = Project::query()
            ->forWorkspace($membership->workspace_id)
            ->visibleTo($request->user(), $membership->role)
            ->when($archived, fn ($q) => $q->where('status', ProjectStatus::Archived), fn ($q) => $q->where('status', '!=', ProjectStatus::Archived))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('slug', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->latest('updated_at')
            ->limit(200) // shortcut: no pagination yet; add it once a workspace nears 200 projects
            ->get();

        return Inertia::render('projects/index', [
            'projects' => $projects->map(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'status' => $p->status->value,
                'visibility' => $p->visibility->value,
                'setupComplete' => $p->wizard_completed_at !== null,
                'updatedAt' => $p->updated_at?->toIso8601String(),
            ]),
            'filters' => ['q' => $search, 'archived' => $archived],
            'canCreate' => $membership->role->can(Capability::ManageProjects),
        ]);
    }

    public function create(Request $request): Response
    {
        $membership = $request->user()->currentMembership();
        abort_if($membership === null, 404);
        Gate::authorize('perform', [$membership->workspace, Capability::ManageProjects]);

        return ProjectWizardController::page(null, 'details');
    }

    public function store(StoreProjectRequest $request, ProjectService $projects): RedirectResponse
    {
        /** @var array{name: string, slug?: string|null, description?: string|null, visibility: string} $details */
        $details = $request->validated();
        $project = $projects->create($request->user(), $request->workspace(), $details);

        return to_route('projects.setup.edit', [$project, $project->wizard_step->value]);
    }

    public function show(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/show', [
            'project' => self::present($project),
            'options' => ProjectOptions::all(),
            'can' => ['update' => $request->user()->can('update', $project), 'delete' => $request->user()->can('delete', $project)],
            'framework' => fn () => $this->frameworkSummary($request, $project),
            'endpoints' => fn () => Features::enabled('route_discovery') ? EndpointPresenter::summary($project, $request->user()->can('update', $project)) : null,
            // Polled while an import runs (partial reload of just this prop).
            'sources' => fn () => Features::enabled('source_snapshots')
                ? SourcePresenter::panel(
                    $project,
                    canImportFixture: app()->environment(['local', 'testing']) && $request->user()->can('update', $project),
                    canUpload: Features::enabled('zip_import') && $request->user()->can('update', $project) && ! $project->isArchived(),
                )
                : null,
        ]);
    }

    public function update(UpdateProjectDetailsRequest $request, Project $project, ProjectService $projects): RedirectResponse
    {
        /** @var array{name: string, slug: string, description?: string|null, visibility: string} $details */
        $details = $request->safe()->except('lock_version');
        $projects->updateDetails($request->user(), $project, $details, $request->integer('lock_version'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project updated.')]);

        return to_route('projects.show', $project);
    }

    public function destroy(DeleteProjectRequest $request, Project $project, ProjectDeleter $deleter): RedirectResponse
    {
        $deleter->delete($request->user(), $project);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('":name" was deleted permanently.', ['name' => $project->name])]);

        return to_route('projects.index');
    }

    public function archive(Request $request, Project $project, ProjectService $projects): RedirectResponse
    {
        Gate::authorize('update', $project);
        $projects->archive($request->user(), $project);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project archived. You can restore it any time.')]);

        return to_route('projects.show', $project);
    }

    public function restore(Request $request, Project $project, ProjectService $projects): RedirectResponse
    {
        Gate::authorize('update', $project);
        $projects->restore($request->user(), $project);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project restored.')]);

        return to_route('projects.show', $project);
    }

    /** @return array<string, mixed>|null detection of the newest ready snapshot */
    private function frameworkSummary(Request $request, Project $project): ?array
    {
        if (! Features::enabled('framework_detection')) {
            return null;
        }

        $snapshot = LatestSnapshot::of($project);
        $detection = $snapshot === null ? null : app(FrameworkDetections::class)->current($snapshot);

        return $snapshot === null ? null : [
            'snapshotId' => $snapshot->id,
            'detection' => $detection === null ? null : DetectionPresenter::present($detection, withEvidence: false),
        ];
    }

    /** @return array<string, mixed> Never includes credentials: the project holds none. */
    public static function present(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'description' => $project->description,
            'visibility' => $project->visibility->value,
            'status' => $project->status->value,
            'archivedAt' => $project->archived_at?->toIso8601String(),
            'wizardStep' => $project->wizard_step->value,
            'setupComplete' => $project->wizard_completed_at !== null,
            'sourcePreference' => $project->source_preference,
            'framework' => $project->framework,
            'rootPath' => $project->root_path,
            'analysisMode' => $project->analysis_mode,
            'documentationMode' => $project->documentation_mode,
            'lockVersion' => $project->lock_version,
            'creator' => $project->creator?->name,
            'updatedAt' => $project->updated_at?->toIso8601String(),
        ];
    }
}
