<?php

namespace App\Domain\Ai;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Anthropic Messages API (spec § 6.3, API version 2026-10-21). Never send temperature/top_p/top_k: they 400 on this version. */
final class AnthropicAdapter implements AiProviderAdapter
{
    public const API_VERSION = '2026-10-21';

    public function test(#[\SensitiveParameter] string $apiKey): TestResult
    {
        try {
            $json = $this->send($apiKey, 'GET', '/v1/models', ['limit' => 1000], 'test_timeout');
        } catch (AiError $e) {
            return new TestResult(Str::after($e->errorCode(), 'ai.'), []);
        }

        $models = [];
        foreach ((array) ($json['data'] ?? []) as $model) {
            if (is_array($model) && is_string($model['id'] ?? null)) {
                // Effort is on unless the capabilities clearly say otherwise.
                $unsupported = data_get($model, 'capabilities.effort') === false || data_get($model, 'capabilities.effort.supported') === false;
                $models[] = ['id' => $model['id'], 'effort' => ! $unsupported];
            }
        }

        return new TestResult('ready', $models);
    }

    public function generate(#[\SensitiveParameter] string $apiKey, string $model, string $effort, string $system, string $user, int $maxOutputTokens): Generation
    {
        $json = $this->send($apiKey, 'POST', '/v1/messages', [
            'model' => $model,
            'max_tokens' => $maxOutputTokens,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
        ] + ($effort === '' ? [] : ['output_config' => ['effort' => $effort]]), 'timeout');

        $usage = (array) ($json['usage'] ?? []);
        $input = (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['cache_creation_input_tokens'] ?? 0) + (int) ($usage['cache_read_input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);

        $text = '';
        foreach ((array) ($json['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        if (($json['stop_reason'] ?? null) === 'refusal') {
            throw AiError::of('ai.refused')->withTokens($input, $output);
        }

        if (trim($text) === '') {
            throw AiError::of('ai.empty_output')->withTokens($input, $output);
        }

        return new Generation($text, $input, $output, ($json['stop_reason'] ?? null) === 'max_tokens');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  'timeout'|'test_timeout'  $timeout
     * @return array<mixed>
     */
    private function send(#[\SensitiveParameter] string $apiKey, string $method, string $path, array $data, string $timeout): array
    {
        $request = Http::withHeaders(['x-api-key' => $apiKey, 'anthropic-version' => self::API_VERSION]);

        return AiAdapters::send($request, $method, config('services.ai.anthropic_url').$path, $data, $timeout,
            fn (Response $r) => $r->status() === 429 && $r->json('error.details.error_code') === 'enforced_spend_limit_reached' ? 'ai.quota_exceeded' : null);
    }
}
