<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\AiAdapters;
use App\Domain\Ai\AiError;
use App\Domain\Ai\GeminiAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdaptersTest extends TestCase
{
    private const KEY = 'sk-test-SECRET-KEY-123';

    private const HOSTS = [
        'anthropic' => 'api.anthropic.com/*',
        'openai' => 'api.openai.com/*',
        'gemini' => 'generativelanguage.googleapis.com/*',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_anthropic_generate_returns_text_and_usage(): void
    {
        Http::fake([self::HOSTS['anthropic'] => Http::response([
            'content' => [['type' => 'thinking', 'thinking' => 'hmm'], ['type' => 'text', 'text' => 'Hello '], ['type' => 'text', 'text' => 'world']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'cache_creation_input_tokens' => 20, 'cache_read_input_tokens' => 5, 'output_tokens' => 40],
        ])]);

        $generation = AiAdapters::for('anthropic')->generate(self::KEY, 'claude-opus-5-5', 'medium', 'sys', 'user text', 16000);

        $this->assertSame('Hello world', $generation->text);
        $this->assertSame(125, $generation->inputTokens);
        $this->assertSame(40, $generation->outputTokens);
        $this->assertFalse($generation->truncated);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.anthropic.com/v1/messages'
            && $r->header('x-api-key') === [self::KEY]
            && $r->header('anthropic-version') === ['2026-10-21']
            && ! str_contains($r->body(), self::KEY)
            && ! str_contains($r->url(), self::KEY)
            && $r['model'] === 'claude-opus-5-5'
            && $r['max_tokens'] === 16000
            && $r['system'] === 'sys'
            && $r['messages'] === [['role' => 'user', 'content' => 'user text']]
            && $r['output_config'] === ['effort' => 'medium']);
    }

    public function test_anthropic_omits_output_config_when_effort_is_empty(): void
    {
        Http::fake([self::HOSTS['anthropic'] => Http::response(['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]])]);

        AiAdapters::for('anthropic')->generate(self::KEY, 'claude-haiku-5-5', '', 'sys', 'user', 100);

        Http::assertSent(fn (Request $r) => ! array_key_exists('output_config', $r->data()));
    }

    public function test_anthropic_never_sends_sampling_parameters(): void
    {
        Http::fake([self::HOSTS['anthropic'] => Http::response(['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]])]);

        AiAdapters::for('anthropic')->generate(self::KEY, 'claude-opus-5-5', 'high', 'sys', 'user', 100);

        Http::assertSent(fn (Request $r) => array_intersect(['temperature', 'top_p', 'top_k', 'thinking'], array_keys($r->data())) === []);
    }

    public function test_anthropic_refusal_and_truncation(): void
    {
        Http::fakeSequence(self::HOSTS['anthropic'])
            ->push(['content' => [['type' => 'text', 'text' => 'no']], 'stop_reason' => 'refusal', 'usage' => ['input_tokens' => 7, 'output_tokens' => 2]])
            ->push(['content' => [['type' => 'text', 'text' => 'partial']], 'stop_reason' => 'max_tokens', 'usage' => ['input_tokens' => 7, 'output_tokens' => 100]]);
        $adapter = AiAdapters::for('anthropic');

        try {
            $adapter->generate(self::KEY, 'claude-opus-5-5', 'medium', 'sys', 'user', 100);
            $this->fail('Expected a refusal.');
        } catch (AiError $e) {
            $this->assertSame('ai.refused', $e->errorCode());
            $this->assertSame(422, $e->status());
            $this->assertSame(7, $e->inputTokens);
            $this->assertSame(2, $e->outputTokens);
        }

        $generation = $adapter->generate(self::KEY, 'claude-opus-5-5', 'medium', 'sys', 'user', 100);
        $this->assertTrue($generation->truncated);
        $this->assertSame('partial', $generation->text);
    }

    public function test_openai_generate_returns_text_and_usage(): void
    {
        Http::fake([self::HOSTS['openai'] => Http::response([
            'status' => 'completed',
            'output' => [
                ['type' => 'reasoning', 'summary' => []],
                ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Hello '], ['type' => 'output_text', 'text' => 'world']]],
            ],
            'usage' => ['input_tokens' => 90, 'input_tokens_details' => ['cached_tokens' => 30], 'output_tokens' => 33],
        ])]);

        $generation = AiAdapters::for('openai')->generate(self::KEY, 'gpt-5', 'low', 'sys', 'user text', 16000);

        $this->assertSame('Hello world', $generation->text);
        $this->assertSame(90, $generation->inputTokens);
        $this->assertSame(33, $generation->outputTokens);
        $this->assertFalse($generation->truncated);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.openai.com/v1/responses'
            && $r->header('Authorization') === ['Bearer '.self::KEY]
            && ! str_contains($r->body(), self::KEY)
            && ! str_contains($r->url(), self::KEY)
            && $r['model'] === 'gpt-5'
            && $r['instructions'] === 'sys'
            && $r['input'] === 'user text'
            && $r['max_output_tokens'] === 16000
            && $r['reasoning'] === ['effort' => 'low']
            && $r['store'] === false);
    }

    public function test_openai_truncation(): void
    {
        Http::fake([self::HOSTS['openai'] => Http::response([
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'partial']]]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        $this->assertTrue(AiAdapters::for('openai')->generate(self::KEY, 'gpt-5', 'low', 's', 'u', 16)->truncated);
    }

    public function test_gemini_generate_returns_text_and_usage(): void
    {
        Http::fake([self::HOSTS['gemini'] => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'thinking...', 'thought' => true], ['text' => 'Hello '], ['text' => 'world']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 80, 'candidatesTokenCount' => 20, 'thoughtsTokenCount' => 15, 'totalTokenCount' => 115],
        ])]);

        $generation = AiAdapters::for('gemini')->generate(self::KEY, 'gemini-3-pro', 'high', 'sys', 'user text', 16000);

        $this->assertSame('Hello world', $generation->text);
        $this->assertSame(80, $generation->inputTokens);
        $this->assertSame(35, $generation->outputTokens);
        $this->assertFalse($generation->truncated);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro:generateContent'
            && $r->header('x-goog-api-key') === [self::KEY]
            && ! str_contains($r->body(), self::KEY)
            && ! str_contains($r->url(), self::KEY)
            && $r['systemInstruction'] === ['parts' => [['text' => 'sys']]]
            && $r['contents'] === [['role' => 'user', 'parts' => [['text' => 'user text']]]]
            && $r['generationConfig'] === ['maxOutputTokens' => 16000, 'thinkingConfig' => ['thinkingLevel' => 'HIGH']]);
    }

    public function test_gemini_safety_block_is_refused(): void
    {
        Http::fake([self::HOSTS['gemini'] => Http::response(['promptFeedback' => ['blockReason' => 'SAFETY'], 'usageMetadata' => ['promptTokenCount' => 9]])]);

        $this->assertErrorCode('ai.refused', fn () => AiAdapters::for('gemini')->generate(self::KEY, 'gemini-3-pro', '', 's', 'u', 16));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('anthropicErrors')]
    public function test_anthropic_maps_errors(int $status, array $body, string $code): void
    {
        $this->fakeError('anthropic', $status, $body);

        $this->assertErrorCode($code, fn () => AiAdapters::for('anthropic')->generate(self::KEY, 'claude-opus-5-5', 'medium', 's', 'u', 16));
    }

    /** @return array<string, array{int, array<string, mixed>, string}> */
    public static function anthropicErrors(): array
    {
        $error = fn (string $type, array $extra = []) => ['type' => 'error', 'error' => ['type' => $type, 'message' => 'x'] + $extra];

        return [
            '401' => [401, $error('authentication_error'), 'ai.invalid_credentials'],
            '402' => [402, $error('billing_error'), 'ai.quota_exceeded'],
            '403' => [403, $error('permission_error'), 'ai.invalid_credentials'],
            '404 model' => [404, $error('not_found_error'), 'ai.misconfigured'],
            '429' => [429, $error('rate_limit_error'), 'ai.rate_limited'],
            '429 spend cap' => [429, $error('rate_limit_error', ['details' => ['error_code' => 'enforced_spend_limit_reached']]), 'ai.quota_exceeded'],
            '400 invalid request' => [400, $error('invalid_request_error'), 'ai.request_rejected'],
            '413 too large' => [413, $error('request_too_large'), 'ai.request_rejected'],
            '500' => [500, $error('api_error'), 'ai.provider_unavailable'],
            '529' => [529, $error('overloaded_error'), 'ai.provider_unavailable'],
            'connection' => [0, [], 'ai.provider_unavailable'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('openAiErrors')]
    public function test_openai_maps_errors(int $status, array $body, string $code): void
    {
        $this->fakeError('openai', $status, $body);

        $this->assertErrorCode($code, fn () => AiAdapters::for('openai')->generate(self::KEY, 'gpt-5', 'medium', 's', 'u', 16));
    }

    /** @return array<string, array{int, array<string, mixed>, string}> */
    public static function openAiErrors(): array
    {
        $error = fn (string $type, ?string $code = null) => ['error' => ['type' => $type, 'code' => $code, 'message' => 'x', 'param' => null]];

        return [
            '401' => [401, $error('invalid_request_error', 'invalid_api_key'), 'ai.invalid_credentials'],
            '403' => [403, $error('invalid_request_error', 'unsupported_country_region_territory'), 'ai.invalid_credentials'],
            '404 model' => [404, $error('invalid_request_error', 'model_not_found'), 'ai.misconfigured'],
            '429' => [429, $error('requests', 'rate_limit_exceeded'), 'ai.rate_limited'],
            '429 insufficient_quota code' => [429, $error('insufficient_quota', 'insufficient_quota'), 'ai.quota_exceeded'],
            '429 insufficient_quota type' => [429, $error('insufficient_quota', 'credit_balance_exhausted'), 'ai.quota_exceeded'],
            '400 invalid request' => [400, $error('invalid_request_error', 'unsupported_parameter'), 'ai.request_rejected'],
            '500' => [500, $error('server_error'), 'ai.provider_unavailable'],
            'connection' => [0, [], 'ai.provider_unavailable'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('geminiErrors')]
    public function test_gemini_maps_errors(int $status, array $body, string $code): void
    {
        $this->fakeError('gemini', $status, $body);

        $this->assertErrorCode($code, fn () => AiAdapters::for('gemini')->generate(self::KEY, 'gemini-3-pro', 'medium', 's', 'u', 16));
    }

    /** @return array<string, array{int, array<string, mixed>, string}> */
    public static function geminiErrors(): array
    {
        $rpc = fn (int $code, string $status, array $details = []) => ['error' => ['code' => $code, 'message' => 'x', 'status' => $status, 'details' => $details]];
        $quota = fn (string $quotaId, string $metric) => ['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [['quotaMetric' => $metric, 'quotaId' => $quotaId, 'quotaValue' => '50']]];

        return [
            '400 invalid key' => [400, $rpc(400, 'INVALID_ARGUMENT', [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'API_KEY_INVALID']]), 'ai.invalid_credentials'],
            '401' => [401, ['error' => ['code' => 'authentication', 'message' => 'x']], 'ai.invalid_credentials'],
            '402' => [402, ['error' => ['code' => 'payment_required', 'message' => 'x']], 'ai.quota_exceeded'],
            '403' => [403, $rpc(403, 'PERMISSION_DENIED'), 'ai.invalid_credentials'],
            '404 model' => [404, $rpc(404, 'NOT_FOUND'), 'ai.misconfigured'],
            '400 invalid argument' => [400, $rpc(400, 'INVALID_ARGUMENT', [['@type' => 'type.googleapis.com/google.rpc.BadRequest', 'fieldViolations' => []]]), 'ai.request_rejected'],
            '429' => [429, $rpc(429, 'RESOURCE_EXHAUSTED'), 'ai.rate_limited'],
            '429 rpc per-minute' => [429, $rpc(429, 'RESOURCE_EXHAUSTED', [$quota('GenerateRequestsPerMinutePerProjectPerModel', 'generativelanguage.googleapis.com/generate_content_requests')]), 'ai.rate_limited'],
            '429 rpc per-day' => [429, $rpc(429, 'RESOURCE_EXHAUSTED', [$quota('GenerateRequestsPerDayPerProjectPerModel-FreeTier', 'generativelanguage.googleapis.com/generate_content_free_tier_requests'), ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '23s']]), 'ai.quota_exceeded'],
            '429 rpc billing' => [429, $rpc(429, 'RESOURCE_EXHAUSTED', [$quota('PrepayCreditBalance', 'generativelanguage.googleapis.com/billing_prepay_credit')]), 'ai.quota_exceeded'],
            '429 daily quota' => [429, ['error' => ['code' => 'quota_exceeded', 'message' => 'x']], 'ai.quota_exceeded'],
            '500' => [500, $rpc(500, 'INTERNAL'), 'ai.provider_unavailable'],
            'connection' => [0, [], 'ai.provider_unavailable'],
        ];
    }

    public function test_anthropic_test_lists_models(): void
    {
        Http::fake([self::HOSTS['anthropic'] => Http::response(['data' => [
            ['id' => 'claude-opus-5-5', 'display_name' => 'Claude Opus 5.5', 'capabilities' => ['effort' => ['supported' => true]]],
            ['id' => 'claude-haiku-5-5', 'display_name' => 'Claude Haiku 5.5', 'capabilities' => ['effort' => ['supported' => false]]],
            ['id' => 'claude-sonnet-5-5', 'display_name' => 'Claude Sonnet 5.5'],
        ], 'has_more' => false])]);

        $result = AiAdapters::for('anthropic')->test(self::KEY);

        $this->assertSame('ready', $result->status);
        $this->assertSame([
            ['id' => 'claude-opus-5-5', 'effort' => true],
            ['id' => 'claude-haiku-5-5', 'effort' => false],
            ['id' => 'claude-sonnet-5-5', 'effort' => true],
        ], $result->models);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_starts_with($r->url(), 'https://api.anthropic.com/v1/models')
            && $r->header('x-api-key') === [self::KEY] && ! str_contains($r->url(), self::KEY));
    }

    public function test_openai_test_lists_models(): void
    {
        Http::fake([self::HOSTS['openai'] => Http::response(['object' => 'list', 'data' => [
            ['id' => 'gpt-5', 'object' => 'model', 'owned_by' => 'openai'],
            ['id' => 'gpt-4.1', 'object' => 'model', 'owned_by' => 'openai'],
            ['id' => 'o3', 'object' => 'model', 'owned_by' => 'openai'],
            ['id' => 'chatgpt-4o-latest', 'object' => 'model', 'owned_by' => 'openai'],
            // Not text-generation models: never offered.
            ...array_map(fn ($id) => ['id' => $id, 'object' => 'model', 'owned_by' => 'openai'], [
                'text-embedding-3-large', 'tts-1', 'whisper-1', 'dall-e-3', 'gpt-image-1', 'gpt-4o-audio-preview', 'gpt-4o-realtime-preview',
                'gpt-4o-mini-tts', 'gpt-4o-transcribe', 'gpt-4o-search-preview', 'omni-moderation-latest', 'davinci-002', 'babbage-002',
            ]),
        ]])]);

        $result = AiAdapters::for('openai')->test(self::KEY);

        $this->assertSame('ready', $result->status);
        $this->assertSame([['id' => 'gpt-5', 'effort' => true], ['id' => 'gpt-4.1', 'effort' => false], ['id' => 'o3', 'effort' => true], ['id' => 'chatgpt-4o-latest', 'effort' => false]], $result->models);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://api.openai.com/v1/models'
            && $r->header('Authorization') === ['Bearer '.self::KEY]);
    }

    public function test_gemini_test_lists_models(): void
    {
        Http::fake([self::HOSTS['gemini'] => Http::response(['models' => [
            ['name' => 'models/gemini-3-pro', 'supportedGenerationMethods' => ['generateContent', 'countTokens'], 'thinking' => true],
            ['name' => 'models/gemini-2.5-flash', 'supportedGenerationMethods' => ['generateContent'], 'thinking' => true],
            ['name' => 'models/gemini-3-flash-lite', 'supportedGenerationMethods' => ['generateContent'], 'thinking' => false],
            ['name' => 'models/text-embedding-004', 'supportedGenerationMethods' => ['embedContent']],
        ]])]);

        $result = AiAdapters::for('gemini')->test(self::KEY);

        $this->assertSame('ready', $result->status);
        $this->assertSame([
            ['id' => 'gemini-3-pro', 'effort' => true],
            ['id' => 'gemini-2.5-flash', 'effort' => false],
            ['id' => 'gemini-3-flash-lite', 'effort' => false],
        ], $result->models);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_starts_with($r->url(), 'https://generativelanguage.googleapis.com/v1beta/models')
            && $r->header('x-goog-api-key') === [self::KEY] && ! str_contains($r->url(), self::KEY));
    }

    public function test_anthropic_test_maps_401_to_invalid_credentials_status(): void
    {
        Http::fake([self::HOSTS['anthropic'] => Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'x']], 401)]);

        $result = AiAdapters::for('anthropic')->test(self::KEY);

        $this->assertSame('invalid_credentials', $result->status);
        $this->assertSame([], $result->models);
    }

    public function test_openai_test_maps_401_to_invalid_credentials_status(): void
    {
        Http::fake([self::HOSTS['openai'] => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'invalid_api_key', 'message' => 'x']], 401)]);

        $this->assertSame('invalid_credentials', AiAdapters::for('openai')->test(self::KEY)->status);
    }

    public function test_gemini_test_maps_401_to_invalid_credentials_status(): void
    {
        Http::fake([self::HOSTS['gemini'] => Http::response(['error' => ['code' => 'authentication', 'message' => 'x']], 401)]);

        $this->assertSame('invalid_credentials', AiAdapters::for('gemini')->test(self::KEY)->status);
    }

    public function test_test_never_throws_for_transient_failures(): void
    {
        Http::fakeSequence(self::HOSTS['anthropic'])->push(['error' => ['type' => 'rate_limit_error']], 429)->push([], 503);
        Http::fake([self::HOSTS['openai'] => Http::failedConnection()]);

        $this->assertSame('rate_limited', AiAdapters::for('anthropic')->test(self::KEY)->status);
        $this->assertSame('provider_unavailable', AiAdapters::for('anthropic')->test(self::KEY)->status);
        $this->assertSame('provider_unavailable', AiAdapters::for('openai')->test(self::KEY)->status);
    }

    #[DataProvider('providers')]
    public function test_empty_text_throws_empty_output_with_tokens(string $provider, array $body): void
    {
        Http::fake([self::HOSTS[$provider] => Http::response($body)]);

        try {
            AiAdapters::for($provider)->generate(self::KEY, 'm', 'medium', 's', 'u', 16);
            $this->fail('Expected empty output.');
        } catch (AiError $e) {
            $this->assertSame('ai.empty_output', $e->errorCode());
            $this->assertSame(11, $e->inputTokens);
            $this->assertSame(4, $e->outputTokens);
        }
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function providers(): array
    {
        return [
            'anthropic' => ['anthropic', ['content' => [['type' => 'thinking', 'thinking' => 'hmm']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 11, 'output_tokens' => 4]]],
            'openai' => ['openai', ['status' => 'completed', 'output' => [['type' => 'reasoning', 'summary' => []]], 'usage' => ['input_tokens' => 11, 'output_tokens' => 4]]],
            'gemini' => ['gemini', ['candidates' => [['content' => ['parts' => [['text' => 'hmm', 'thought' => true]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 11, 'thoughtsTokenCount' => 4]]],
        ];
    }

    public function test_error_messages_never_contain_provider_body(): void
    {
        foreach (array_keys(self::HOSTS) as $provider) {
            $this->fakeError($provider, 400, ['error' => ['type' => 'invalid_request_error', 'message' => 'SECRET-PROVIDER-TEXT']]);

            try {
                AiAdapters::for($provider)->generate(self::KEY, 'm', 'medium', 's', 'u', 16);
                $this->fail('Expected an AiError.');
            } catch (AiError $e) {
                $this->assertStringNotContainsString('SECRET-PROVIDER-TEXT', $e->getMessage());
                $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            }
        }
    }

    public function test_gemini_free_tier_rule(): void
    {
        foreach (['gemini-3.5-flash', 'gemini-3.8-flash', 'gemini-3.1-flash-lite', 'gemini-3-flash-preview', 'gemini-flash-latest', 'gemini-flash-lite-latest', 'gemma-4-31b-it'] as $id) {
            $this->assertTrue(GeminiAdapter::freeTier($id), $id);
        }
        foreach (['gemini-pro-latest', 'gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-3.8-flash-tts', 'gemini-3.1-flash-image', 'gemini-omni-1.2-flash', 'gemini-2.5-pro'] as $id) {
            $this->assertFalse(GeminiAdapter::freeTier($id), $id);
        }
    }

    public function test_error_codes_carry_fixed_messages_and_retry_rules(): void
    {
        $this->assertTrue(AiError::of('ai.rate_limited')->retryable());
        $this->assertTrue(AiError::of('ai.provider_unavailable')->retryable());
        $this->assertFalse(AiError::of('ai.invalid_credentials')->retryable());
        $this->assertSame('invalid_credentials', AiError::of('ai.invalid_credentials')->providerStatus());
        $this->assertSame('quota_exceeded', AiError::of('ai.quota_exceeded')->providerStatus());
        $this->assertSame('misconfigured', AiError::of('ai.misconfigured')->providerStatus());
        $this->assertNull(AiError::of('ai.rate_limited')->providerStatus());
        $this->assertSame('ai.provider_unavailable', AiError::of('nope')->errorCode());
        $this->assertSame(409, AiError::of('ai.key_changed')->status());
        $rejected = AiError::of('ai.request_rejected');
        $this->assertSame('The AI provider rejected the request (for example the effort setting or its size). A platform admin can pick another model or a lower effort in Settings → AI.', $rejected->getMessage());
        $this->assertSame(422, $rejected->status());
        $this->assertFalse($rejected->retryable());
        $this->assertNull($rejected->providerStatus());

        $gate = AiError::withMessage('ai.limit_reached', 'Anthropic\'s monthly AI limit is used up.');
        $this->assertSame('ai.limit_reached', $gate->errorCode());
        $this->assertSame('Anthropic\'s monthly AI limit is used up.', $gate->getMessage());
        $this->assertSame(429, $gate->status());
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function fakeError(string $provider, int $status, array $body): void
    {
        Http::fake([self::HOSTS[$provider] => $status === 0 ? Http::failedConnection() : Http::response($body, $status)]);
    }

    private function assertErrorCode(string $code, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected {$code}.");
        } catch (AiError $e) {
            $this->assertSame($code, $e->errorCode());
        }
    }
}
