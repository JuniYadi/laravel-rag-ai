<?php

use App\Services\EmbeddingService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function embeddingServiceProperty(EmbeddingService $service, string $property): mixed
{
    $reflection = new ReflectionClass($service);
    $prop = $reflection->getProperty($property);
    $prop->setAccessible(true);

    return $prop->getValue($service);
}

test('constructor resolves embedding provider, model, base url and dimensions from services.embedding config', function () {
    config()->set('services.embedding.provider', 'openai-compatible');
    config()->set('services.embedding.model', 'text-embedding-3-large');
    config()->set('services.embedding.api_key', 'embedding-key');
    config()->set('services.embedding.base_url', 'https://embed-proxy.example.com/v1/');
    config()->set('services.embedding.dimensions', 3072);

    $service = new EmbeddingService;

    expect(embeddingServiceProperty($service, 'provider'))->toBe('openai-compatible')
        ->and($service->getModel())->toBe('text-embedding-3-large')
        ->and($service->getBaseUrl())->toBe('https://embed-proxy.example.com/v1')
        ->and($service->getDimensions())->toBe(3072);
});

test('generate embeddings uses dedicated embedding config independent from llm config', function () {
    config()->set('services.llm.provider', 'openai');
    config()->set('services.llm.model', 'gpt-4o-mini');
    config()->set('services.llm.api_key', 'llm-key');
    config()->set('services.llm.base_url', 'https://llm.example.com/v1');

    config()->set('services.embedding.provider', 'openai');
    config()->set('services.embedding.model', 'text-embedding-3-small');
    config()->set('services.embedding.api_key', 'embedding-key');
    config()->set('services.embedding.base_url', 'https://embeddings.example.com/v1');

    Http::fake([
        'https://embeddings.example.com/v1/embeddings' => Http::response([
            'data' => [
                ['embedding' => [0.11, 0.22, 0.33]],
                ['embedding' => [0.44, 0.55, 0.66]],
            ],
        ], 200),
    ]);

    $service = new EmbeddingService;

    $vectors = $service->generateEmbeddings(['alpha', 'beta']);

    expect($vectors)->toBe([
        [0.11, 0.22, 0.33],
        [0.44, 0.55, 0.66],
    ]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://embeddings.example.com/v1/embeddings'
            && $request->hasHeader('Authorization', 'Bearer embedding-key')
            && $request['model'] === 'text-embedding-3-small'
            && $request['input'] === ['alpha', 'beta'];
    });
});

test('constructor throws clear exception when embedding api key is missing', function () {
    config()->set('services.embedding.provider', 'openai');
    config()->set('services.embedding.model', 'text-embedding-3-small');
    config()->set('services.embedding.api_key', '');
    config()->set('services.embedding.base_url', 'https://api.openai.com/v1');

    expect(fn () => new EmbeddingService)
        ->toThrow(LogicException::class, 'Missing embedding API key. Set services.embedding.api_key / EMBEDDING_API_KEY.');
});

test('unsupported embedding provider throws actionable exception', function () {
    config()->set('services.embedding.provider', 'anthropic');
    config()->set('services.embedding.model', 'text-embedding-3-small');
    config()->set('services.embedding.api_key', 'embedding-key');
    config()->set('services.embedding.base_url', 'https://api.example.com/v1');

    $service = new EmbeddingService;

    expect(fn () => $service->generateEmbedding('hello'))
        ->toThrow(LogicException::class, 'Unsupported embedding provider [anthropic]. Supported providers: openai, openai-compatible.');
});
