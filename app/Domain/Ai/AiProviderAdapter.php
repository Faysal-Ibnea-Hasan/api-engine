<?php

namespace App\Domain\Ai;

/** One AI provider's HTTP API. Adapters never touch the database: the caller passes key and model. */
interface AiProviderAdapter
{
    /** Lists models with the key. Never throws for provider failures: they become the status. */
    public function test(#[\SensitiveParameter] string $apiKey): TestResult;

    /**
     * One non-streaming call, no retries (the job decides).
     *
     * @param  string  $effort  low | medium | high, or '' when the model's `effort` flag is false (the field is then left out)
     *
     * @throws AiError
     */
    public function generate(#[\SensitiveParameter] string $apiKey, string $model, string $effort, string $system, string $user, int $maxOutputTokens): Generation;
}
