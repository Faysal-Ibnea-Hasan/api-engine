<?php

namespace App\Domain\Ai;

/** One successful provider call: the text and the provider-reported usage. */
final readonly class Generation
{
    public function __construct(
        public string $text,
        public int $inputTokens,
        public int $outputTokens,
        /** The output cap was hit: the text is kept but cut short. */
        public bool $truncated,
    ) {}
}
