<?php

namespace App\Domain\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

final class AiAdapters
{
    public static function for(string $provider): AiProviderAdapter
    {
        return app(match ($provider) {
            'anthropic' => AnthropicAdapter::class,
            'openai' => OpenAiAdapter::class,
            'gemini' => GeminiAdapter::class,
            default => throw new InvalidArgumentException('Unknown AI provider.'),
        });
    }

    /**
     * The one HTTP path every adapter uses: config timeouts, no redirects (a redirect would carry
     * the key header to another host), no retries, failures mapped to AiError codes only.
     *
     * @param  'timeout'|'test_timeout'  $timeout  key under services.ai
     * @param  array<string, mixed>  $data  query (GET) or JSON body
     * @param  callable(Response): ?string  $providerCode  provider-specific error code, or null for the shared HTTP-status mapping
     * @return array<mixed>
     */
    public static function send(#[\SensitiveParameter] PendingRequest $request, string $method, string $url, array $data, string $timeout, callable $providerCode): array
    {
        try {
            $response = $request
                ->connectTimeout((int) config('services.ai.connect_timeout'))
                ->timeout((int) config('services.ai.'.$timeout))
                ->withoutRedirecting()
                ->acceptJson()
                ->send($method, $url, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
        } catch (ConnectionException) {
            $error = AiError::of('ai.provider_unavailable'); // its message names the URL: never report it
            $error->noResponse = true;

            throw $error;
        }

        if ($response->failed()) {
            $code = $providerCode($response);

            throw $code === null ? AiError::forHttpStatus($response->status()) : AiError::of($code);
        }

        $json = $response->json();

        if (! $response->successful() || ! is_array($json)) {
            throw AiError::of('ai.provider_unavailable');
        }

        return $json;
    }
}
