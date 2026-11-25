<?php

// Shapes from https://developers.openai.com/api/reference/resources/responses/methods/create and /api/docs/guides/error-codes as of 2026-11-24; usage fields and models-list shape not re-verified against live docs.

namespace App\Domain\Ai;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** OpenAI Responses API. store=false: the prompt holds project source code. */
final class OpenAiAdapter implements AiProviderAdapter
{
    public function test(#[\SensitiveParameter] string $apiKey): TestResult
    {
        try {
            $json = $this->send($apiKey, 'GET', '/v1/models', [], 'test_timeout');
        } catch (AiError $e) {
            return new TestResult(Str::after($e->errorCode(), 'ai.'), []);
        }

        $models = [];
        foreach ((array) ($json['data'] ?? []) as $model) {
            // Text models only: /v1/models also lists embedding, speech, image and search models.
            if (is_array($model) && is_string($model['id'] ?? null)
                && preg_match('/^(gpt-|o\d|chatgpt-)/', $model['id']) === 1 && preg_match('/audio|realtime|tts|transcribe|image|embedding|search/', $model['id']) !== 1) {
                // shortcut: the models list has no capability data, so reasoning (effort) support is guessed from the id (o-series, gpt-5+); revisit if OpenAI exposes capabilities.
                $models[] = ['id' => $model['id'], 'effort' => preg_match('/^(o\d|gpt-([5-9]|\d{2,}))/', $model['id']) === 1];
            }
        }

        return new TestResult('ready', $models);
    }

    public function generate(#[\SensitiveParameter] string $apiKey, string $model, string $effort, string $system, string $user, int $maxOutputTokens): Generation
    {
        $json = $this->send($apiKey, 'POST', '/v1/responses', [
            'model' => $model,
            'instructions' => $system,
            'input' => $user,
            'max_output_tokens' => $maxOutputTokens,
            'store' => false,
        ] + ($effort === '' ? [] : ['reasoning' => ['effort' => $effort]]), 'timeout');

        // input_tokens already includes cached tokens; output_tokens includes reasoning tokens.
        $input = (int) data_get($json, 'usage.input_tokens', 0);
        $output = (int) data_get($json, 'usage.output_tokens', 0);

        $text = '';
        $refused = data_get($json, 'incomplete_details.reason') === 'content_filter';
        foreach ((array) ($json['output'] ?? []) as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }
            foreach ((array) ($item['content'] ?? []) as $part) {
                $type = is_array($part) ? ($part['type'] ?? null) : null;
                $text .= $type === 'output_text' ? (string) ($part['text'] ?? '') : '';
                $refused = $refused || $type === 'refusal';
            }
        }

        if ($refused && trim($text) === '') {
            throw AiError::of('ai.refused')->withTokens($input, $output);
        }

        if (trim($text) === '') {
            throw AiError::of('ai.empty_output')->withTokens($input, $output);
        }

        return new Generation($text, $input, $output, data_get($json, 'incomplete_details.reason') === 'max_output_tokens');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  'timeout'|'test_timeout'  $timeout
     * @return array<mixed>
     */
    private function send(#[\SensitiveParameter] string $apiKey, string $method, string $path, array $data, string $timeout): array
    {
        return AiAdapters::send(Http::withToken($apiKey), $method, config('services.ai.openai_url').$path, $data, $timeout,
            // insufficient_quota is the broad error.type; older responses put it in error.code.
            fn (Response $r) => $r->status() === 429 && in_array('insufficient_quota', [$r->json('error.type'), $r->json('error.code')], true) ? 'ai.quota_exceeded' : null);
    }
}
