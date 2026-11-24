<?php

namespace App\Http\Controllers\Docs;

use App\Domain\Docs\DocumentationMarkdown;
use App\Models\DocumentationVersion;

/**
 * The only shape a documentation draft leaves the server in. Without source access (QA, Viewer) the
 * evidence section, every evidence id, source line numbers and hashes are removed, as AnalysisPresenter
 * does for analyses. The stored document itself is never changed.
 */
final class DocumentationPresenter
{
    /** Values the API itself returns or accepts: never rewritten. */
    private const VERBATIM = ['example', 'command'];

    /** @return array<string, mixed> */
    public static function documentation(DocumentationVersion $doc, bool $withSource): array
    {
        // Documentation never shows evidence (drafts from static-docs-v1 still carry it).
        $document = $withSource ? self::withoutEvidence($doc->document) : self::withoutSource($doc->document);

        return [
            'id' => $doc->id,
            'status' => $doc->status,
            'builderVersion' => $doc->builder_version,
            'analyzerVersion' => $doc->analyzer_version,
            'snapshotId' => $doc->source_snapshot_id,
            'analysisId' => $doc->endpoint_analysis_id,
            'generatedAt' => $doc->created_at?->toIso8601String(),
            'checksum' => $withSource ? $doc->contract_checksum : null,
            'snapshotHash' => $withSource ? $doc->snapshot->sha256 : null,
            'document' => $document,
            'markdown' => DocumentationMarkdown::render($document),
        ];
    }

    /** @return array{id: string, generatedAt: string|null, analyzerVersion: string, builderVersion: string} */
    public static function draft(DocumentationVersion $doc): array
    {
        return [
            'id' => $doc->id,
            'generatedAt' => $doc->created_at?->toIso8601String(),
            'analyzerVersion' => $doc->analyzer_version,
            'builderVersion' => $doc->builder_version,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private static function withoutEvidence(array $node): array
    {
        $out = [];
        foreach ($node as $key => $value) {
            if ($key !== 'evidence') {
                $out[$key] = is_array($value) && ! in_array($key, self::VERBATIM, true) ? self::withoutEvidence($value) : $value;
            }
        }

        return array_is_list($node) ? array_values($out) : $out;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private static function withoutSource(array $node): array
    {
        $out = [];
        foreach ($node as $key => $value) {
            if ($key === 'evidence') {
                continue;
            }
            $out[$key] = match (true) {
                in_array($key, self::VERBATIM, true) => $value,
                is_array($value) => self::withoutSource($value),
                // Same patterns as AnalysisPresenter::withoutLines(): "(line 35)", "(path at line 22)", "at line 29".
                is_string($value) => trim((string) preg_replace(['/\s*\((?:path )?at line \d+\)|\s*\(line \d+\)/', '/\s+at line \d+/'], ['', ''], $value)),
                default => $value,
            };
        }

        return array_is_list($node) ? array_values($out) : $out;
    }
}
