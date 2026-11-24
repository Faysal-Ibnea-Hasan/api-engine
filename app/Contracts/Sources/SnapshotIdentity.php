<?php

namespace App\Contracts\Sources;

use App\Models\SourceSnapshot;

/** The source identity contract (development-plan/P03 §5). storage_key never leaves the server. */
final readonly class SnapshotIdentity
{
    public function __construct(
        public string $snapshotId,
        public string $projectId,
        public string $sourceType,
        public ?string $sha256,
        public string $status,
        public string $manifestVersion,
    ) {}

    public static function of(SourceSnapshot $snapshot): self
    {
        return new self(
            $snapshot->id,
            $snapshot->project_id,
            $snapshot->source->type->value,
            $snapshot->sha256,
            $snapshot->status->value,
            $snapshot->manifest_version,
        );
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'snapshot_id' => $this->snapshotId,
            'project_id' => $this->projectId,
            'source_type' => $this->sourceType,
            'sha256' => $this->sha256,
            'status' => $this->status,
            'manifest_version' => $this->manifestVersion,
        ];
    }
}
