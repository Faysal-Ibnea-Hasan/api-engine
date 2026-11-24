<?php

namespace App\Http\Controllers\Docs;

use App\Domain\Docs\StaticDocumentation;
use App\Http\Controllers\Controller;
use App\Models\ApiEndpoint;
use App\Models\DocumentationVersion;
use App\Models\EndpointAnalysis;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DocumentationController extends Controller
{
    private const MAX_DRAFTS = 50;

    public function store(Request $request, Project $project, EndpointAnalysis $analysis, StaticDocumentation $docs): RedirectResponse
    {
        Gate::authorize('generateDocs', $project);

        $doc = $docs->generate($request->user(), $analysis);
        Inertia::flash('toast', $doc->wasRecentlyCreated
            ? ['type' => 'success', 'message' => __('Documentation ready.')]
            : ['type' => 'info', 'message' => __('Documentation is already up to date with this analysis.')]);

        return to_route('projects.documentation.show', [$project, $doc]);
    }

    /** The endpoint's newest draft (from any scan of the same route), or an empty state. */
    public function index(Request $request, Project $project, ApiEndpoint $endpoint): Response
    {
        Gate::authorize('view', $project);
        $latest = DocumentationVersion::query()->where('project_id', $project->id)->where('route_identity', $endpoint->route_identity)
            ->latest()->orderByDesc('id')->first();

        return $this->page($request, $project, $endpoint->route_identity, $endpoint->id, $latest);
    }

    public function show(Request $request, Project $project, DocumentationVersion $documentation): Response
    {
        Gate::authorize('view', $project);

        return $this->page($request, $project, $documentation->route_identity, $documentation->analysis->api_endpoint_id, $documentation);
    }

    private function page(Request $request, Project $project, string $routeIdentity, ?string $endpointId, ?DocumentationVersion $doc): Response
    {
        $withSource = $request->user()->can('viewSource', $project);

        return Inertia::render('projects/documentation', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'endpointId' => $endpointId,
            'documentation' => $doc === null ? null : DocumentationPresenter::documentation($doc, $withSource),
            'drafts' => DocumentationVersion::query()->where('project_id', $project->id)->where('route_identity', $routeIdentity)
                ->latest()->orderByDesc('id')->limit(self::MAX_DRAFTS)
                ->get(['id', 'created_at', 'analyzer_version', 'builder_version'])
                ->map(fn (DocumentationVersion $d) => DocumentationPresenter::draft($d)),
            'canViewSource' => $withSource,
            'canGenerate' => $request->user()->can('generateDocs', $project) && ! $project->isArchived(),
        ]);
    }
}
