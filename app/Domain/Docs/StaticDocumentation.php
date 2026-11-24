<?php

namespace App\Domain\Docs;

use App\Domain\Analysis\AnalysisResultV1;
use App\Domain\Audit\AuditLog;
use App\Domain\Projects\ProjectError;
use App\Models\DocumentationVersion;
use App\Models\EndpointAnalysis;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Generates the static documentation draft of one finished analysis, synchronously: the builder is
 * fast and deterministic. One draft per (analysis, builder version); asking again returns it. Older
 * drafts are never touched, so a newer analysis adds a draft beside them.
 */
final class StaticDocumentation
{
    public function __construct(private readonly StaticDocumentationBuilder $builder) {}

    public function generate(User $actor, EndpointAnalysis $analysis): DocumentationVersion
    {
        $project = $analysis->project;

        if ($project->isArchived()) {
            throw ProjectError::archived();
        }

        if (! $analysis->isFinal() || $analysis->result === null) {
            throw DocsError::analysisNotReady();
        }

        // Same analysis result and builder → the same document: return that draft (repeat clicks, nothing changed).
        // After a re-analysis the result differs, so regenerating makes a new draft; older drafts stay.
        $checksum = StaticDocumentationBuilder::checksum($analysis->result);
        if (($found = self::draftOf($analysis)->where('contract_checksum', $checksum)->first()) !== null) {
            return $found;
        }
        if (AnalysisResultV1::problems($analysis->result) !== []) {
            throw DocsError::invalidResult();
        }
        $endpoint = $analysis->endpoint ?? throw DocsError::endpointMissing();

        $document = $this->builder->build($analysis->result, $analysis->status->value, [
            'methods' => $endpoint->methods,
            'uri' => $endpoint->uri,
            'domain' => $endpoint->domain,
            'name' => $endpoint->name,
            'handler' => $endpoint->controller !== null ? $endpoint->controller.'@'.$endpoint->action : $endpoint->handler_type,
            'group' => $endpoint->route_group,
            'middleware' => $endpoint->middleware,
        ]);

        try {
            return DB::transaction(function () use ($actor, $analysis, $project, $document, $checksum) {
                $doc = new DocumentationVersion;
                $doc->forceFill([
                    'workspace_id' => $analysis->workspace_id,
                    'project_id' => $analysis->project_id,
                    'source_snapshot_id' => $analysis->source_snapshot_id,
                    'endpoint_analysis_id' => $analysis->id,
                    'route_identity' => $analysis->route_identity,
                    'analyzer_version' => $analysis->analyzer_version,
                    'builder_version' => StaticDocumentationBuilder::VERSION,
                    'status' => 'draft',
                    'contract_checksum' => $checksum,
                    'document' => $document,
                    'created_by' => $actor->id,
                ])->save();

                AuditLog::record('documentation.generated', $project->workspace, $actor, $doc, ['route_identity' => $analysis->route_identity]);

                return $doc;
            });
        } catch (UniqueConstraintViolationException) {
            return self::draftOf($analysis)->where('contract_checksum', $checksum)->firstOrFail(); // a double click raced us
        }
    }

    /** @return Builder<DocumentationVersion> */
    private static function draftOf(EndpointAnalysis $analysis): Builder
    {
        return DocumentationVersion::query()->where('endpoint_analysis_id', $analysis->id)
            ->where('builder_version', StaticDocumentationBuilder::VERSION);
    }
}
