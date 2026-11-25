<?php

namespace App\Domain\Analysis;

use App\Domain\Analysis\Shape\Recorder;
use App\Domain\Audit\AuditLog;
use App\Domain\Projects\ProjectError;
use App\Domain\Sources\RunStatus;
use App\Events\EndpointAnalysisFinished;
use App\Jobs\AnalyzeEndpointJob;
use App\Models\AnalysisEvidence;
use App\Models\AnalysisFinding;
use App\Models\ApiDependency;
use App\Models\ApiEndpoint;
use App\Models\EndpointAnalysis;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Endpoint analysis lifecycle. One row per (snapshot, root, route identity, analyzer
 * version): asking again returns it; a failed or cancelled one is queued again; a completed
 * or partial one is final. The result, evidence and findings are written in one transaction.
 */
final class EndpointAnalyses
{
    public const JOB_TIMEOUT = 120;

    /** The contract only. */
    public const BASIC = 'basic';

    /** The contract plus the workflow graph (P10, `advanced_analysis`): a separate row with its own fingerprint. */
    public const ADVANCED = 'advanced';

    public function __construct(private readonly EndpointAnalyzer $analyzer) {}

    /**
     * The idempotency fingerprint. Includes what discovery resolved (its version and handler):
     * a newer route analyzer that re-resolves an endpoint must not reuse a stale final analysis.
     * The basic key is unchanged from before profiles existed; the advanced one adds the graph version.
     */
    public static function key(ApiEndpoint $endpoint, string $profile = self::BASIC): string
    {
        $scan = $endpoint->scan;

        return hash('sha256', implode("\n", [
            $endpoint->source_snapshot_id, $scan->application_root, $scan->analyzer_version,
            $endpoint->route_identity, $endpoint->handler_type, (string) $endpoint->controller, (string) $endpoint->action,
            EndpointAnalyzer::VERSION,
            ...($profile === self::ADVANCED ? [self::ADVANCED, Recorder::VERSION] : []),
        ]));
    }

    /**
     * @param  bool  $reanalyze  run a finished analysis again (its result is replaced when the new one is written;
     *                           documentation drafts keep their own copy)
     */
    public function start(?User $actor, ApiEndpoint $endpoint, bool $reanalyze = false, string $profile = self::BASIC): EndpointAnalysis
    {
        $scan = $endpoint->scan;

        if ($scan->project->isArchived()) {
            throw ProjectError::archived();
        }

        $key = self::key($endpoint, $profile);
        $analysis = EndpointAnalysis::query()->where('analysis_key', $key)->first();

        if ($analysis !== null) {
            if (in_array($analysis->status, [RunStatus::Failed, RunStatus::Cancelled], true) || self::isStale($analysis) || ($reanalyze && $analysis->isFinal())) {
                $reanalyze && $analysis->isFinal() && AuditLog::record('analysis.reanalyzed', $scan->project->workspace, $actor, $analysis, ['route_identity' => $endpoint->route_identity]);
                $requeued = EndpointAnalysis::query()->whereKey($analysis->id)->whereIn('status', [RunStatus::Failed->value, RunStatus::Cancelled->value, RunStatus::Completed->value, RunStatus::Partial->value])
                    ->update(['status' => RunStatus::Queued->value, 'api_endpoint_id' => $endpoint->id, 'failure_code' => null, 'claimed_at' => null, 'finished_at' => null, 'updated_at' => now()]);
                $requeued === 1 && AnalyzeEndpointJob::dispatch($analysis->id);
            } elseif ($analysis->status === RunStatus::Queued && $analysis->updated_at?->lt(now()->subSeconds(self::JOB_TIMEOUT))) {
                // Queued for longer than a job may run: its job was lost. The claim makes a duplicate harmless.
                AnalyzeEndpointJob::dispatch($analysis->id);
            }

            return $analysis->refresh();
        }

        $analysis = new EndpointAnalysis;
        $analysis->forceFill([
            'workspace_id' => $endpoint->workspace_id,
            'project_id' => $endpoint->project_id,
            'source_snapshot_id' => $endpoint->source_snapshot_id,
            'api_endpoint_id' => $endpoint->id,
            'route_identity' => $endpoint->route_identity,
            'application_root' => $scan->application_root,
            'analyzer_version' => EndpointAnalyzer::VERSION,
            'schema_version' => EndpointAnalyzer::SCHEMA_VERSION,
            'profile' => $profile,
            'analysis_key' => $key,
            'status' => RunStatus::Queued,
            'created_by' => $actor?->id,
            'correlation_id' => Context::get('correlation_id'),
        ]);

        try {
            $analysis->save();
        } catch (UniqueConstraintViolationException) {
            return EndpointAnalysis::query()->where('analysis_key', $key)->firstOrFail(); // a double click raced us
        }

        AuditLog::record('analysis.started', $scan->project->workspace, $actor, $analysis, ['route_identity' => $endpoint->route_identity, ...($profile === self::ADVANCED ? ['profile' => $profile] : [])]);
        AnalyzeEndpointJob::dispatch($analysis->id);

        return $analysis->refresh();
    }

    /**
     * A final row whose stored result came from another analyzer version (a worker still running
     * old code wrote it). It must be redone, not served as the current version's result.
     */
    public static function isStale(EndpointAnalysis $analysis): bool
    {
        return $analysis->isFinal() && (($analysis->result['analyzer_version'] ?? null) !== $analysis->analyzer_version || ! self::hasExpectedGraph($analysis, $analysis->result ?? []));
    }

    /**
     * An advanced row must carry this release's graph: the analyzer version alone can't tell, because
     * a worker running code from before profiles existed writes the same version without one.
     *
     * @param  array<string, mixed>  $result
     */
    private static function hasExpectedGraph(EndpointAnalysis $analysis, array $result): bool
    {
        return $analysis->profile !== self::ADVANCED || ($result['graph']['version'] ?? null) === Recorder::VERSION;
    }

    public function process(string $analysisId): void
    {
        $claimed = EndpointAnalysis::query()->whereKey($analysisId)
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

        $analysis = EndpointAnalysis::query()->with('snapshot', 'endpoint')->findOrFail($analysisId);

        if ($analysis->endpoint === null) {
            $this->fail($analysisId, 'endpoint_missing');

            return;
        }

        try {
            $result = $this->analyzer->analyze($analysis->snapshot, $analysis->endpoint, $analysis->application_root, $analysis->profile);
        } catch (Throwable $e) {
            EndpointAnalysis::query()->whereKey($analysisId)->where('status', RunStatus::Running->value)->update(['claimed_at' => null]);

            throw $e;
        }

        $problems = AnalysisResultV1::problems($result);
        if ($problems !== []) {
            // Never store a malformed result as if it were complete.
            Log::warning('analysis.invalid_result', ['analysis_id' => $analysisId, 'problems' => array_slice($problems, 0, 10)]);
            $this->fail($analysisId, 'invalid_result');

            return;
        }

        if (($result['analyzer_version'] ?? null) !== $analysis->analyzer_version || ! self::hasExpectedGraph($analysis, $result)) {
            // This worker runs older code than the app that queued the job: never store its result under the new version.
            Log::warning('analysis.analyzer_mismatch', ['analysis_id' => $analysisId, 'expected' => $analysis->analyzer_version, 'got' => $result['analyzer_version'] ?? null]);
            $this->fail($analysisId, 'analyzer_mismatch');

            return;
        }

        $this->write($analysis, $result);
    }

    public function fail(string $analysisId, string $code): void
    {
        $failed = EndpointAnalysis::query()->whereKey($analysisId)->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value])
            ->update(['status' => RunStatus::Failed->value, 'failure_code' => $code, 'claimed_at' => null, 'finished_at' => now(), 'updated_at' => now()]);

        $failed === 1 && EndpointAnalysisFinished::dispatch($analysisId);
    }

    /** @param  array<string, mixed>  $result */
    private function write(EndpointAnalysis $analysis, array $result): void
    {
        $status = $result['status'] === 'completed' ? RunStatus::Completed : RunStatus::Partial;
        unset($result['status']);

        DB::transaction(function () use ($analysis, $result, $status) {
            $locked = EndpointAnalysis::query()->lockForUpdate()->findOrFail($analysis->id);
            if ($locked->status !== RunStatus::Running) {
                return;
            }

            AnalysisEvidence::query()->where('endpoint_analysis_id', $analysis->id)->delete();
            AnalysisFinding::query()->where('endpoint_analysis_id', $analysis->id)->delete();
            ApiDependency::query()->where('endpoint_analysis_id', $analysis->id)->delete();

            foreach (array_chunk($result['evidence'], 500) as $chunk) {
                AnalysisEvidence::query()->insert(array_map(fn (array $ev) => [
                    'endpoint_analysis_id' => $analysis->id,
                    'evidence_key' => $ev['id'],
                    'path' => mb_substr($ev['path'], 0, 1024),
                    'sha256' => $ev['sha256'],
                    'symbol' => $ev['symbol'] === null ? null : mb_substr($ev['symbol'], 0, 512),
                    'start_line' => $ev['lines'][0],
                    'end_line' => $ev['lines'][1],
                    'kind' => mb_substr($ev['kind'], 0, 30),
                ], $chunk));
            }

            foreach (array_chunk($result['unresolved'], 500) as $chunk) {
                AnalysisFinding::query()->insert(array_map(fn (string $message) => [
                    'endpoint_analysis_id' => $analysis->id,
                    'severity' => 'warning',
                    'code' => 'unresolved',
                    'message' => mb_substr($message, 0, 500),
                    'created_at' => now(),
                ], $chunk));
            }

            $types = array_column($result['graph']['nodes'] ?? [], 'type', 'id');
            foreach (array_chunk($result['graph']['edges'] ?? [], 500) as $chunk) {
                ApiDependency::query()->insert(array_map(fn (array $e) => [
                    'endpoint_analysis_id' => $analysis->id,
                    'workspace_id' => $analysis->workspace_id,
                    'project_id' => $analysis->project_id,
                    'edge_hash' => hash('sha256', $e['from']."\n".$e['to']."\n".$e['kind']),
                    'kind' => $e['kind'],
                    'from_node' => mb_substr($e['from'], 0, 512),
                    'from_type' => $types[$e['from']] ?? 'service',
                    'to_node' => mb_substr($e['to'], 0, 512),
                    'to_type' => $types[$e['to']] ?? 'service',
                    'conditional' => $e['conditional'],
                    'resolution' => $e['resolution'],
                    'reason' => $e['reason'],
                    'async' => $e['async'],
                    'note' => $e['note'] === null ? null : mb_substr($e['note'], 0, 500),
                    'expression' => $e['expression'] === null ? null : mb_substr($e['expression'], 0, 255),
                    'evidence' => json_encode($e['evidence'], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ], $chunk));
            }

            EndpointAnalysis::query()->whereKey($analysis->id)->update([
                'status' => $status->value,
                'result' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
                'field_count' => count($result['request']['fields']),
                'response_count' => count($result['responses']),
                'unresolved_count' => count($result['unresolved']),
                'auth_status' => $result['auth']['status'],
                'claimed_at' => null,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            EndpointAnalysisFinished::dispatch($analysis->id); // held until this transaction commits
        });
    }
}
