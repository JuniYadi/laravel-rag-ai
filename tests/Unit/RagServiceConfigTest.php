<?php

use App\Services\EmbeddingService;
use App\Services\RagService;
use App\Services\VectorSearchService;
use Tests\TestCase;

uses(TestCase::class);

function makeRagServiceForConfigTests(): RagService
{
    return new class(Mockery::mock(VectorSearchService::class), Mockery::mock(EmbeddingService::class)) extends RagService
    {
        public function callLlmPublic(string $systemPrompt, string $userPrompt): array
        {
            return $this->callLlm($systemPrompt, $userPrompt);
        }
    };
}

function ragServiceProperty(RagService $service, string $property): mixed
{
    $reflection = new ReflectionClass($service);
    $prop = $reflection->getProperty($property);
    $prop->setAccessible(true);

    return $prop->getValue($service);
}

afterEach(function (): void {
    Mockery::close();
});

test('constructor resolves llm provider, model, base url and api key from services.llm config', function () {
    config()->set('services.llm.provider', 'openai-compatible');
    config()->set('services.llm.model', 'gpt-4o-mini');
    config()->set('services.llm.base_url', 'https://proxy.example.com/v1/');
    config()->set('services.llm.api_key', 'llm-config-key');

    $service = makeRagServiceForConfigTests();

    expect(ragServiceProperty($service, 'llmProvider'))->toBe('openai-compatible')
        ->and(ragServiceProperty($service, 'llmModel'))->toBe('gpt-4o-mini')
        ->and(ragServiceProperty($service, 'llmBaseUrl'))->toBe('https://proxy.example.com/v1')
        ->and(ragServiceProperty($service, 'openaiApiKey'))->toBe('llm-config-key');
});

test('constructor falls back to services.openai.api_key when services.llm.api_key is missing', function () {
    config()->set('services.llm.provider', 'openai');
    config()->set('services.llm.model', 'gpt-4o-mini');
    config()->set('services.llm.base_url', 'https://api.openai.com/v1');
    config()->set('services.llm.api_key', null);
    config()->set('services.openai.api_key', 'openai-service-key');

    $service = makeRagServiceForConfigTests();

    expect(ragServiceProperty($service, 'openaiApiKey'))->toBe('openai-service-key');
});

test('constructor throws clear exception when llm api key is missing', function () {
    config()->set('services.llm.provider', 'openai');
    config()->set('services.llm.model', 'gpt-4o-mini');
    config()->set('services.llm.base_url', 'https://api.openai.com/v1');
    config()->set('services.llm.api_key', '');
    config()->set('services.openai.api_key', '');

    expect(fn () => makeRagServiceForConfigTests())
        ->toThrow(RuntimeException::class, 'Missing LLM API key. Set services.llm.api_key or OPENAI_API_KEY.');
});

test('unsupported provider throws actionable exception', function () {
    config()->set('services.llm.provider', 'anthropic');
    config()->set('services.llm.model', 'claude-3-5-sonnet');
    config()->set('services.llm.base_url', 'https://api.anthropic.com');
    config()->set('services.llm.api_key', 'test-key');

    $service = makeRagServiceForConfigTests();

    expect(fn () => $service->callLlmPublic('system', 'user'))
        ->toThrow(RuntimeException::class, 'Unsupported LLM provider [anthropic]. Supported providers: openai, openai-compatible.');
});

test('constructor resolves retry configuration from services.rag.retry config', function () {
    config()->set('services.llm.provider', 'openai');
    config()->set('services.llm.model', 'gpt-4o-mini');
    config()->set('services.llm.base_url', 'https://api.openai.com/v1');
    config()->set('services.llm.api_key', 'test-key');
    config()->set('services.rag.retry.attempts', 4);
    config()->set('services.rag.retry.backoff_ms', [100, 250, 500, 1000]);

    $service = makeRagServiceForConfigTests();

    expect(ragServiceProperty($service, 'retryAttempts'))->toBe(4)
        ->and(ragServiceProperty($service, 'retryBackoffMs'))->toBe([100, 250, 500, 1000]);
});
