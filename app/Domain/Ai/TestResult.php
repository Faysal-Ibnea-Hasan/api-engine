<?php

namespace App\Domain\Ai;

/** The outcome of a connection test, which uses no tokens. */
final readonly class TestResult
{
    /**
     * @param  string  $status  'ready', or an ai_providers.status such as 'invalid_credentials'
     * @param  list<array{id: string, effort: bool}>  $models  empty unless ready
     */
    public function __construct(
        public string $status,
        public array $models,
    ) {}
}
