<?php

namespace App\Contracts\Analysis;

/**
 * SHARED_CONTRACTS §5. Facts only: what the files say, each with the path it came from.
 * A declared constraint (e.g. "^11.0") is never presented as the installed version.
 */
final readonly class FrameworkDetectionResult
{
    public const SCHEMA_VERSION = '1.0';

    /**
     * @param  'detected'|'partial'|'unsupported'|'not_found'  $status
     * @param  list<array<string, mixed>>  $roots  one entry per Laravel application root
     * @param  list<string>  $warnings
     * @param  list<string>  $evidence  snapshot paths that support the result
     */
    public function __construct(
        public string $status,
        public ?string $framework,
        public array $roots,
        public ?string $recommendedRoot,
        public array $warnings,
        public array $evidence,
        public string $analyzerVersion,
        public string $snapshotId,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'analyzer_version' => $this->analyzerVersion,
            'snapshot_id' => $this->snapshotId,
            'status' => $this->status,
            'framework' => $this->framework,
            'roots' => $this->roots,
            'recommended_root' => $this->recommendedRoot,
            'warnings' => $this->warnings,
            'evidence' => $this->evidence,
        ];
    }
}
