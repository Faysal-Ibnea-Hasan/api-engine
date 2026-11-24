<?php

namespace App\Domain\Sources;

final readonly class ManifestEntry
{
    public function __construct(
        public string $path,
        public int $bytes,
        public ?string $sha256,
        public ?string $language,
        public ?string $excludedReason,
    ) {}

    public function isStored(): bool
    {
        return $this->excludedReason === null;
    }
}
