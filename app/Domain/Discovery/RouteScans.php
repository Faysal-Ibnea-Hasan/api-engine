<?php

namespace App\Domain\Discovery;

use App\Domain\Audit\AuditLog;
use App\Domain\Projects\ProjectError;
use App\Domain\Sources\RunStatus;
use App\Domain\Sources\SnapshotStatus;
use App\Jobs\ScanRoutesJob;
use App\Models\ApiEndpoint;
use App\Models\FrameworkDetection;
use App\Models\RouteScan;
use App\Models\ScanFinding;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Route scan lifecycle. One row per (snapshot, analyzer version, root): asking again returns
 * it; a failed or cancelled one is queued again; a completed (or partial) one is final.
 * Endpoints, findings and the final status are written in one transaction.
 */
final class RouteScans
{
    public const JOB_TIMEOUT = 600;

    public function __construct(private readonly RouteDiscoverer $discoverer) {}

    public function start(?User $actor, FrameworkDetection $detection): RouteScan
    {
        $snapshot = $detection->snapshot;

        if ($detection->project->isArchived()) {
            throw ProjectError::archived();
        }

        if ($snapshot->status !== SnapshotStatus::Ready || $detection->selected_root === null) {
            throw new \InvalidArgumentException('A ready snapshot with a selected application root is required.');
        }

        $key = hash('sha256', implode("\n", [$snapshot->id, RouteDiscoverer::VERSION, $detection->selected_root]));
        $scan = RouteScan::query()->where('scan_key', $key)->first();

        if ($scan !== null) {
            if (in_array($scan->status, [RunStatus::Failed, RunStatus::Cancelled], true)) {
                RouteScan::query()->whereKey($scan->id)->whereIn('status', [RunStatus::Failed->value, RunStatus::Cancelled->value])
                    ->update(['status' => RunStatus::Queued->value, 'failure_code' => null, 'claimed_at' => null, 'finished_at' => null, 'updated_at' => now()]);
                ScanRoutesJob::dispatch($scan->id);
            }

            return $scan->refresh();
        }

        $scan = new RouteScan;
        $scan->forceFill([
            'workspace_id' => $snapshot->workspace_id,
            'project_id' => $snapshot->project_id,
            'source_snapshot_id' => $snapshot->id,
            'framework_detection_id' => $detection->id,
            'application_root' => $detection->selected_root,
            'analyzer_version' => RouteDiscoverer::VERSION,
            'scan_key' => $key,
            'status' => RunStatus::Queued,
            'created_by' => $actor?->id,
            'correlation_id' => Context::get('correlation_id'),
        ]);

        try {
            $scan->save();
        } catch (UniqueConstraintViolationException) {
            return RouteScan::query()->where('scan_key', $key)->firstOrFail();
        }

        AuditLog::record('discovery.scan_started', $detection->project->workspace, $actor, $scan, ['root' => $scan->application_root]);
        ScanRoutesJob::dispatch($scan->id);

        return $scan->refresh();
    }

    public function process(string $scanId): void
    {
        $claimed = RouteScan::query()->whereKey($scanId)
            ->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value])
            ->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subSeconds(self::JOB_TIMEOUT)))
            ->update([
                'status' => RunStatus::Running->value,
                'claimed_at' => now(),
                'started_at' => DB::raw('COALESCE(started_at, CURRENT_TIMESTAMP)'),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return; // final, or another worker holds it
        }

        $scan = RouteScan::query()->with('snapshot', 'project')->findOrFail($scanId);

        try {
            $result = $this->discoverer->discover($scan->snapshot, $scan->application_root);
            $this->write($scan, $result);
        } catch (Throwable $e) {
            RouteScan::query()->whereKey($scanId)->where('status', RunStatus::Running->value)->update(['claimed_at' => null]);

            throw $e;
        }
    }

    public function fail(string $scanId, string $code): void
    {
        RouteScan::query()->whereKey($scanId)->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value])
            ->update(['status' => RunStatus::Failed->value, 'failure_code' => $code, 'claimed_at' => null, 'finished_at' => now(), 'updated_at' => now()]);
    }

    /** @param  array{routes: list<array<string, mixed>>, findings: list<array<string, mixed>>, files: list<string>}  $result */
    private function write(RouteScan $scan, array $result): void
    {
        $counts = array_count_values(array_column($result['routes'], 'resolution'));
        $hasErrors = in_array('error', array_column($result['findings'], 'severity'), true);

        DB::transaction(function () use ($scan, $result, $counts, $hasErrors) {
            $locked = RouteScan::query()->lockForUpdate()->findOrFail($scan->id);
            if ($locked->status !== RunStatus::Running) {
                return;
            }

            // A crashed earlier attempt can't have written rows (single transaction), but be safe.
            ApiEndpoint::query()->where('route_scan_id', $scan->id)->delete();
            ScanFinding::query()->where('route_scan_id', $scan->id)->delete();

            foreach (array_chunk($result['routes'], 500) as $chunk) {
                ApiEndpoint::query()->insert(array_map(fn (array $r) => [
                    'id' => (string) Str::uuid7(),
                    'route_scan_id' => $scan->id,
                    'workspace_id' => $scan->workspace_id,
                    'project_id' => $scan->project_id,
                    'source_snapshot_id' => $scan->source_snapshot_id,
                    'route_identity' => $r['route_identity'],
                    'methods' => self::json($r['methods']),
                    'methods_label' => mb_substr('|'.implode('|', $r['methods']).'|', 0, 255),
                    'uri' => mb_substr($r['uri'], 0, 2048),
                    'uri_hash' => hash('sha256', $r['uri']),
                    'domain' => self::cap($r['domain'], 255),
                    'name' => self::cap($r['name'], 255),
                    'handler_type' => $r['handler_type'],
                    'controller' => self::cap($r['controller'], 255),
                    'action' => self::cap($r['action'], 255),
                    'middleware' => self::json(array_values($r['middleware'])),
                    'middleware_label' => '|'.implode('|', $r['middleware']).'|',
                    'without_middleware' => self::json($r['without_middleware'] ?? []),
                    'auth' => $r['auth'],
                    'route_group' => mb_substr($r['group'], 0, 80),
                    'module' => self::cap($r['module'], 120),
                    'source_path' => $r['source_path'],
                    'source_line' => (int) $r['source_line'],
                    'expression' => $r['expression'],
                    'resolution' => $r['resolution'],
                    'reasons' => self::json(array_values(array_unique($r['reasons']))),
                ], $chunk));
            }

            foreach (array_chunk($result['findings'], 500) as $chunk) {
                ScanFinding::query()->insert(array_map(fn (array $f) => [
                    'route_scan_id' => $scan->id,
                    'severity' => $f['severity'],
                    'code' => $f['code'],
                    'message' => mb_substr($f['message'], 0, 500),
                    'source_path' => $f['source_path'],
                    'source_line' => $f['source_line'],
                    'expression' => $f['expression'],
                ], $chunk));
            }

            $locked->forceFill([
                'status' => $hasErrors ? RunStatus::Partial : RunStatus::Completed,
                'endpoint_count' => count($result['routes']),
                'resolved_count' => $counts['resolved'] ?? 0,
                'partial_count' => $counts['partial'] ?? 0,
                'unresolved_count' => $counts['unresolved'] ?? 0,
                'finding_count' => count($result['findings']),
                'file_count' => count($result['files']),
                'claimed_at' => null,
                'finished_at' => now(),
            ])->save();
        });

        AuditLog::record('discovery.scan_finished', $scan->project->workspace, null, $scan, ['endpoints' => count($result['routes'])]);
    }

    /** Never false: invalid UTF-8 from odd source files is substituted, not fatal. */
    private static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    }

    private static function cap(mixed $value, int $length): ?string
    {
        return is_string($value) ? mb_substr($value, 0, $length) : null;
    }
}
