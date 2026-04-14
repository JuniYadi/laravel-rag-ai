<?php

use App\Services\EmbeddingService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function configureEmbeddingServiceForTests(string $provider = 'openai'): void
{
    config()->set('services.embedding.provider', $provider);
    config()->set('services.embedding.model', 'text-embedding-3-small');
    config()->set('services.embedding.api_key', 'embedding-api-key');
    config()->set('services.embedding.base_url', 'https://embed.example.com/v1');
}

test('generateEmbeddings returns empty array for empty input without sending HTTP', function () {
    configureEmbeddingServiceForTests();
    Http::fake();

    $service = new EmbeddingService;

    expect($service->generateEmbeddings([]))->toBe([]);

    Http::assertNothingSent();
});

test('generateEmbeddings rejects payloads missing embedding data', function () {
    configureEmbeddingServiceForTests();

    Http::fake([
        'https://embed.example.com/v1/embeddings' => Http::response(['oops' => 'missing data'], 200),
    ]);

    $service = new EmbeddingService;

    expect(fn () => $service->generateEmbeddings(['hello']))
        ->toThrow(RuntimeException::class, 'Embedding API response missing data[] payload.');
});

test('generateEmbeddings rejects non-array embedding items', function () {
    configureEmbeddingServiceForTests();

    Http::fake([
        'https://embed.example.com/v1/embeddings' => Http::response([
            'data' => [
                ['embedding' => [0.1, 0.2, 0.3]],
                ['embedding' => 'invalid'],
            ],
        ], 200),
    ]);

    $service = new EmbeddingService;

    expect(fn () => $service->generateEmbeddings(['alpha', 'beta']))
        ->toThrow(RuntimeException::class, 'Embedding API response contains an invalid embedding item.');
});

test('generateEmbeddings validates vector count matches input count', function () {
    configureEmbeddingServiceForTests();

    Http::fake([
        'https://embed.example.com/v1/embeddings' => Http::response([
            'data' => [
                ['embedding' => [0.1, 0.2, 0.3]],
            ],
        ], 200),
    ]);

    $service = new EmbeddingService;

    expect(fn () => $service->generateEmbeddings(['alpha', 'beta']))
        ->toThrow(RuntimeException::class, 'Embedding API returned 1 vector(s) for 2 input(s).');
});

test('generateEmbedding returns first embedding from API response', function () {
    configureEmbeddingServiceForTests();

    Http::fake([
        'https://embed.example.com/v1/embeddings' => Http::response([
            'data' => [
                ['embedding' => [1, 2, 3]],
            ],
        ], 200),
    ]);

    $service = new EmbeddingService;

    expect($service->generateEmbedding('simple text'))->toBe([1.0, 2.0, 3.0]);
});

test('averageEmbeddings normalizes uneven vectors and empty vector lists', function () {
    configureEmbeddingServiceForTests();

    $service = new EmbeddingService;

    expect($service->averageEmbeddings([[1, 2], [3]]))->toBe([2.0, 1.0])
        ->and($service->averageEmbeddings([]))->toBe([]);
});

test('cosineSimilarity handles zero vectors as zero similarity', function () {
    configureEmbeddingServiceForTests();

    $service = new EmbeddingService;

    expect($service->cosineSimilarity([0, 0, 0], [0.2, 0.4, 0.6]))->toBe(0.0)
        ->and($service->cosineSimilarity([1, 0, 0], [0, 1, 0]))->toBe(0.0);
});
