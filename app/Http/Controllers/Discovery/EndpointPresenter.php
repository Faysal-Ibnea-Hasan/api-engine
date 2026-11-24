<?php

namespace App\Http\Controllers\Discovery;

use App\Domain\Discovery\CurrentScan;
use App\Models\ApiEndpoint;
use App\Models\Project;
use App\Models\RouteScan;
use App\Models\ScanFinding;

/** The only shapes endpoints leave the server in. Source location and expression need source access. */
final class EndpointPresenter
{
    /** @return array<string, mixed> */
    public static function endpoint(ApiEndpoint $e, bool $withSource): array
    {
        return [
            'id' => $e->id,
            'methods' => array_values(array_filter($e->methods, fn ($m) => $m !== 'HEAD')) ?: $e->methods,
            'uri' => $e->uri,
            'domain' => $e->domain,
            'name' => $e->name,
            'handlerType' => $e->handler_type,
            'controller' => $e->controller,
            'action' => $e->action,
            'middleware' => $e->middleware,
            'auth' => $e->auth,
            // From the endpoint's analysis (null: not analyzed yet, or analysis is off). Not source: safe for every role.
            'analysisAuth' => $e->getAttribute('analysis_auth'),
            'group' => $e->route_group,
            'module' => $e->module,
            'resolution' => $e->resolution,
            'reasons' => $e->reasons,
            'routeIdentity' => $e->route_identity,
            'source' => $withSource ? ['path' => $e->source_path, 'line' => $e->source_line, 'expression' => $e->expression] : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function scan(RouteScan $scan): array
    {
        return [
            'id' => $scan->id,
            'status' => $scan->status->value,
            'active' => $scan->status->isActive(),
            'applicationRoot' => $scan->application_root,
            'analyzerVersion' => $scan->analyzer_version,
            'endpointCount' => $scan->endpoint_count,
            'resolvedCount' => $scan->resolved_count,
            'partialCount' => $scan->partial_count,
            'unresolvedCount' => $scan->unresolved_count,
            'findingCount' => $scan->finding_count,
            'fileCount' => $scan->file_count,
            'coverage' => $scan->endpoint_count === 0 ? null : round($scan->resolved_count / $scan->endpoint_count * 100),
            'failureCode' => $scan->failure_code,
            'finishedAt' => $scan->finished_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function finding(ScanFinding $f, bool $withSource): array
    {
        return [
            'severity' => $f->severity,
            'code' => $f->code,
            'message' => $f->message,
            'source' => $withSource && $f->source_path !== null ? ['path' => $f->source_path, 'line' => $f->source_line, 'expression' => $f->expression] : null,
        ];
    }

    /** @return array<string, mixed> the project page card */
    public static function summary(Project $project, bool $canScan): array
    {
        ['snapshot' => $snapshot, 'detection' => $detection, 'scan' => $scan] = CurrentScan::for($project);

        return [
            'scan' => $scan === null ? null : self::scan($scan),
            'snapshotId' => $snapshot?->id,
            'needsRoot' => $snapshot !== null && $detection !== null && $detection->selected_root === null,
            'canScan' => $canScan && $snapshot !== null && $detection?->selected_root !== null && ($scan === null || ! $scan->isFinal()) && ! $scan?->status->isActive(),
        ];
    }
}
