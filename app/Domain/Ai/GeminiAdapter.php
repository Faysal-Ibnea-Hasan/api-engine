<?php

// Shapes from https://aistudio.google.com/api-reference/generate-content, /api-reference/models and /docs/api-errors as of 2026-11-24; the error shapes (RPC vs snake_case code) and models-list pagination not re-verified against live calls.

namespace App\Domain\Ai;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Gemini generateContent. The key goes only in x-goog-api-key, never in ?key=. */
final class GeminiAdapter implements AiProviderAdapter
{
    private const REFUSAL_REASONS = ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII'];

    /**
     * Whether a text model is on Gemini's free tier. The models API doesn't say, so this follows the
     * rate-limits page: Gemini 3+ Flash and Flash-Lite, and Gemma, are free; Pro, 2.x (closed to new
     * users) and speech/image/live variants are not.
     * shortcut: hand-kept rule from ai.google.dev/gemini-api/docs/rate-limits (2026-11-25), update when Google changes the free tier.
     */
    public static function freeTier(string $id): bool
    {
        return preg_match('/tts|image|live|audio|embedding/', $id) !== 1
            && (str_starts_with($id, 'gemma-')
                || (preg_match('/^gemini-(?:(\d+)(?:\.\d+)?-)?flash(?:-lite)?(?:-latest|-preview)?$/', $id, $m) === 1
                    && (($m[1] ?? '') === '' || (int) $m[1] >= 3))); // no version = the -latest alias
    }

    public function test(#[\SensitiveParameter] string $apiKey): TestResult
    {
        try {
            $json = $this->send($apiKey, 'GET', '/v1beta/models', ['pageSize' => 1000], 'test_timeout');
        } catch (AiError $e) {
            return new TestResult(Str::after($e->errorCode(), 'ai.'), []);
        }

        $models = [];
        foreach ((array) ($json['models'] ?? []) as $model) {
            if (is_array($model) && is_string($model['name'] ?? null) && in_array('generateContent', (array) ($model['supportedGenerationMethods'] ?? []), true)) {
                $id = Str::after($model['name'], 'models/');
                // thinkingLevel (our effort field) is Gemini 3+ only; earlier models reject it.
                $models[] = ['id' => $id, 'effort' => ($model['thinking'] ?? false) === true && preg_match('/^gemini-[12]\./', $id) !== 1];
            }
        }

        return new TestResult('ready', $models);
    }

    public function generate(#[\SensitiveParameter] string $apiKey, string $model, string $effort, string $system, string $user, int $maxOutputTokens): Generation
    {
        $json = $this->send($apiKey, 'POST', '/v1beta/models/'.rawurlencode($model).':generateContent', [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
            'generationConfig' => ['maxOutputTokens' => $maxOutputTokens]
                + ($effort === '' ? [] : ['thinkingConfig' => ['thinkingLevel' => strtoupper($effort)]]),
        ], 'timeout');

        // Thinking tokens are billed as output.
        $input = (int) data_get($json, 'usageMetadata.promptTokenCount', 0);
        $output = (int) data_get($json, 'usageMetadata.candidatesTokenCount', 0) + (int) data_get($json, 'usageMetadata.thoughtsTokenCount', 0);
        $finish = data_get($json, 'candidates.0.finishReason');

        if (data_get($json, 'promptFeedback.blockReason') !== null || in_array($finish, self::REFUSAL_REASONS, true)) {
            throw AiError::of('ai.refused')->withTokens($input, $output);
        }

        $text = '';
        foreach ((array) data_get($json, 'candidates.0.content.parts', []) as $part) {
            if (is_array($part) && ($part['thought'] ?? false) !== true) {
                $text .= (string) ($part['text'] ?? '');
            }
        }

        if (trim($text) === '') {
            throw AiError::of('ai.empty_output')->withTokens($input, $output);
        }

        return new Generation($text, $input, $output, $finish === 'MAX_TOKENS');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  'timeout'|'test_timeout'  $timeout
     * @return array<mixed>
     */
    private function send(#[\SensitiveParameter] string $apiKey, string $method, string $path, array $data, string $timeout): array
    {
        return AiAdapters::send(Http::withHeaders(['x-goog-api-key' => $apiKey]), $method, config('services.ai.gemini_url').$path, $data, $timeout,
            fn (Response $r) => match (true) {
                // generateContent answers a bad key with 400 INVALID_ARGUMENT + ErrorInfo reason API_KEY_INVALID.
                $r->status() === 400 && in_array('API_KEY_INVALID', array_column((array) $r->json('error.details'), 'reason'), true) => 'ai.invalid_credentials',
                // A daily or billing quota needs an admin; per-minute limits stay rate_limited. Two shapes: the
                // string code from /docs/api-errors, and google.rpc QuotaFailure violations (AIP-193).
                $r->status() === 429 && ($r->json('error.code') === 'quota_exceeded' || self::quotaExhausted($r)) => 'ai.quota_exceeded',
                default => null,
            });
    }

    /** A QuotaFailure violation for a per-day or billing quota (e.g. quotaId GenerateRequestsPerDayPerProjectPerModel-FreeTier). */
    private static function quotaExhausted(Response $response): bool
    {
        foreach ((array) $response->json('error.details') as $detail) {
            if (is_array($detail) && ($detail['@type'] ?? null) === 'type.googleapis.com/google.rpc.QuotaFailure') {
                foreach ((array) ($detail['violations'] ?? []) as $violation) {
                    $id = is_array($violation) ? ($violation['quotaId'] ?? '').' '.($violation['quotaMetric'] ?? '') : '';
                    if (preg_match('/PerDay|billing|prepay|credit/i', (string) $id) === 1) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
