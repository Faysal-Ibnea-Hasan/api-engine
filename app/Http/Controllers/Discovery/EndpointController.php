<?php

namespace App\Http\Controllers\Discovery;

use App\Domain\Analysis\EndpointAnalyses;
use App\Domain\Analysis\EndpointAnalyzer;
use App\Domain\Discovery\CurrentScan;
use App\Domain\Discovery\RouteScans;
use App\Domain\Docs\StaticDocumentationBuilder;
use App\Domain\Framework\FrameworkDetections;
use App\Domain\Sources\RunStatus;
use App\Http\Controllers\Analysis\AnalysisPresenter;
use App\Http\Controllers\Controller;
use App\Models\ApiEndpoint;
use App\Models\DocumentationVersion;
use App\Models\EndpointAnalysis;
use App\Models\Project;
use App\Models\RouteScan;
use App\Models\ScanFinding;
use App\Models\SourceSnapshot;
use App\Support\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EndpointController extends Controller
{
    private const PER_PAGE = 50;

    private const AUTH_STATUSES = ['required', 'required_unverified', 'guest_or_user', 'not_required', 'unknown'];

    /** API Explorer: the current scan's endpoints, filtered and paginated. */
    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);
        $withSource = $request->user()->can('viewSource', $project);
        ['snapshot' => $snapshot, 'detection' => $detection, 'scan' => $scan] = CurrentScan::for($project);

        $filters = [
            'q' => mb_substr(trim($request->string('q')->value()), 0, 200),
            'method' => strtoupper($request->string('method')->value()),
            'group' => $request->string('group')->value() ?: 'api',
            'auth' => $request->string('auth')->value(),
            'resolution' => $request->string('resolution')->value(),
        ];

        $endpoints = null;
        $groups = [];
        $findings = [];

        $analysisEnabled = Features::enabled('endpoint_analysis');

        if ($scan !== null && $scan->isFinal()) {
            $query = ApiEndpoint::query()->where('route_scan_id', $scan->id);
            // The endpoint's own analysis by the current analyzer: an endpoint row belongs to one scan, so its
            // analysis fingerprint (snapshot, root, discovery version, handler) is that row's.
            $analysisAuth = fn () => EndpointAnalysis::query()->select('auth_status')
                ->whereColumn('api_endpoint_id', 'api_endpoints.id')
                ->where('analyzer_version', EndpointAnalyzer::VERSION)
                ->whereIn('status', [RunStatus::Completed->value, RunStatus::Partial->value])
                ->limit(1);
            $analysisEnabled && $query->select('api_endpoints.*')->addSelect(['analysis_auth' => $analysisAuth()]);
            $groups = (clone $query)->distinct()->orderBy('route_group')->pluck('route_group')->all();

            $filters['group'] !== 'all' && $query->where('route_group', $filters['group']);
            in_array($filters['method'], ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true) && $query->where('methods_label', 'like', '%|'.$filters['method'].'|%');
            if ($analysisEnabled) {
                // Auth from the analysis of each endpoint's middleware and action; never guessed from names.
                $filters['auth'] === 'not_analyzed' && $query->whereNotExists($analysisAuth()->toBase());
                in_array($filters['auth'], self::AUTH_STATUSES, true) && $query->whereExists($analysisAuth()->where('auth_status', $filters['auth'])->toBase());
            } else {
                in_array($filters['auth'], ['required', 'unknown'], true) && $query->where('auth', $filters['auth']);
            }
            in_array($filters['resolution'], ['resolved', 'partial', 'unresolved'], true) && $query->where('resolution', $filters['resolution']);

            if ($filters['q'] !== '') {
                // '!' as the LIKE escape works the same on MySQL and SQLite.
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
                $query->where(fn ($q) => $q->whereRaw("uri LIKE ? ESCAPE '!'", [$term])
                    ->orWhereRaw("name LIKE ? ESCAPE '!'", [$term])
                    ->orWhereRaw("controller LIKE ? ESCAPE '!'", [$term]));
            }

            $endpoints = $query->orderBy('uri')->orderBy('methods_label')
                ->paginate(self::PER_PAGE)->withQueryString()
                ->through(fn (ApiEndpoint $e) => EndpointPresenter::endpoint($e, $withSource));

            $findings = ScanFinding::query()->where('route_scan_id', $scan->id)
                ->orderByRaw("CASE severity WHEN 'error' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")->orderBy('id')
                ->limit(200)->get()->map(fn (ScanFinding $f) => EndpointPresenter::finding($f, $withSource));
        }

        return Inertia::render('projects/endpoints', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'snapshotId' => $snapshot?->id,
            'needsRoot' => $snapshot !== null && $detection !== null && $detection->selected_root === null,
            'scan' => $scan === null ? null : EndpointPresenter::scan($scan),
            'canScan' => $request->user()->can('update', $project) && ! $project->isArchived() && $detection?->selected_root !== null,
            'analysisEnabled' => $analysisEnabled,
            'endpoints' => $endpoints,
            'groups' => $groups,
            'filters' => $filters,
            'findings' => $findings,
        ]);
    }

    public function show(Request $request, Project $project, ApiEndpoint $endpoint): Response
    {
        Gate::authorize('view', $project);
        $scan = $endpoint->scan;
        $withSource = $request->user()->can('viewSource', $project);
        $analysis = Features::enabled('endpoint_analysis')
            ? EndpointAnalysis::query()->where('analysis_key', EndpointAnalyses::key($endpoint))->first()
            : null;

        // Is there a documentation draft for this route, and was it built from the current analysis result?
        $docsState = 'none';
        if (Features::enabled('static_docs') && $analysis !== null && $analysis->isFinal() && $analysis->result !== null) {
            $latest = DocumentationVersion::query()->where('project_id', $project->id)->where('route_identity', $endpoint->route_identity)->latest()->first();
            if ($latest !== null) {
                $docsState = $latest->endpoint_analysis_id === $analysis->id && $latest->builder_version === StaticDocumentationBuilder::VERSION
                    && $latest->contract_checksum === StaticDocumentationBuilder::checksum($analysis->result) ? 'current' : 'outdated';
            }
        }

        return Inertia::render('projects/endpoint', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'endpoint' => EndpointPresenter::endpoint($endpoint, $withSource),
            'docsState' => $docsState,
            'analysisEnabled' => Features::enabled('endpoint_analysis'),
            'analysis' => $analysis === null ? null : AnalysisPresenter::analysis($analysis, $withSource),
            'canAnalyze' => $request->user()->can('analyze', $project) && ! $project->isArchived(),
            'canViewSource' => $withSource,
            'scan' => EndpointPresenter::scan($scan),
            'isCurrent' => CurrentScan::for($project)['scan']?->id === $scan->id,
        ]);
    }

    public function scan(Request $request, Project $project, RouteScan $scan): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json(EndpointPresenter::scan($scan));
    }

    public function start(Request $request, Project $project, SourceSnapshot $snapshot, FrameworkDetections $detections, RouteScans $scans): RedirectResponse
    {
        Gate::authorize('update', $project);
        $detection = $detections->current($snapshot);
        abort_if($detection === null || $detection->selected_root === null, 409, __('Choose the application root first.'));

        $scans->start($request->user(), $detection);
        Inertia::flash('toast', ['type' => 'info', 'message' => __('Route discovery started.')]);

        return back();
    }
}
