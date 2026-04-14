<?php

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\EmbeddingService;
use App\Services\PgVectorPreflightService;
use App\Services\VectorSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class);
uses(TestCase::class);

function makeVectorSearchService(EmbeddingService $embeddingService): VectorSearchService
{
    $preflight = new class extends PgVectorPreflightService
    {
        protected function currentDriver(): string
        {
            return 'sqlite';
        }
    };

    return new VectorSearchService($embeddingService, $preflight);
}

test('findWithSimilarity returns null when document is missing or has no embedding', function () {
    $embeddingService = Mockery::mock(EmbeddingService::class);
    $service = makeVectorSearchService($embeddingService);

    expect($service->findWithSimilarity(99, 'search'))->toBeNull();

    $user = User::factory()->create();

    $document = Document::create([
        'user_id' => $user->id,
        'title' => 'Unembedded',
        'file_path' => 'documents/none.txt',
        'file_type' => 'txt',
        'content' => 'missing vector',
        'excerpt' => 'missing vector',
        'status' => 'completed',
        'embedding' => null,
    ]);

    expect($service->findWithSimilarity($document->id, 'search'))->toBeNull();
});

test('findWithSimilarity returns computed cosine similarity for embedded documents', function () {
    $embeddingService = Mockery::mock(EmbeddingService::class);
    $embeddingService->shouldReceive('generateEmbedding')->once()->with('search')->andReturn([1.0, 0.0, 0.0]);
    $embeddingService->shouldReceive('cosineSimilarity')->once()->with([0.8, 0.6, 0.0], [1.0, 0.0, 0.0])->andReturn(0.8);

    $service = makeVectorSearchService($embeddingService);

    $user = User::factory()->create();
    $document = Document::create([
        'user_id' => $user->id,
        'title' => 'Embedded',
        'file_path' => 'documents/embedded.txt',
        'file_type' => 'txt',
        'content' => 'has vector',
        'excerpt' => 'has vector',
        'status' => 'completed',
        'embedding' => [0.8, 0.6, 0.0],
    ]);

    $result = $service->findWithSimilarity($document->id, 'search');

    expect($result)->toBeArray()
        ->and($result['document']->id)->toBe($document->id)
        ->and($result['similarity'])->toBe(0.8);
});

test('calculateSimilarities returns chunks ordered by cosine similarity', function () {
    $embeddingService = Mockery::mock(EmbeddingService::class);
    $embeddingService->shouldReceive('generateEmbedding')->once()->with('search')->andReturn([1.0, 0.0, 0.0]);
    $embeddingService->shouldReceive('cosineSimilarity')->times(2)->andReturn(0.95, 0.2);

    $service = makeVectorSearchService($embeddingService);

    $user = User::factory()->create();
    $document = Document::create([
        'user_id' => $user->id,
        'title' => 'D1',
        'file_path' => 'documents/d1.txt',
        'file_type' => 'txt',
        'content' => 'chunk one',
        'excerpt' => 'chunk one',
        'status' => 'completed',
        'embedding' => [0.4, 0.9, 0.1],
    ]);

    DocumentChunk::create([
        'document_id' => $document->id,
        'user_id' => $user->id,
        'chunk_index' => 0,
        'content' => 'good chunk',
        'excerpt' => 'good chunk',
        'embedding' => [1.0, 0.0, 0.0],
        'char_count' => 10,
        'token_count' => 3,
        'metadata' => [],
    ]);

    DocumentChunk::create([
        'document_id' => $document->id,
        'user_id' => $user->id,
        'chunk_index' => 1,
        'content' => 'other chunk',
        'excerpt' => 'other chunk',
        'embedding' => [0.0, 1.0, 0.0],
        'char_count' => 11,
        'token_count' => 3,
        'metadata' => [],
    ]);

    $ordered = $service->calculateSimilarities('search');

    expect($ordered->count())->toBe(2)
        ->and($ordered->first()['similarity'])->toBe(0.95)
        ->and($ordered->last()['similarity'])->toBe(0.2);
});

test('fromDocumentFallback builds pseudo chunk metadata', function () {
    $embeddingService = Mockery::mock(EmbeddingService::class);
    $service = makeVectorSearchService($embeddingService);

    $user = User::factory()->create();
    $document = Document::create([
        'user_id' => $user->id,
        'title' => 'Fallback',
        'file_path' => 'documents/fallback.txt',
        'file_type' => 'txt',
        'content' => 'fallback body',
        'excerpt' => 'fallback body',
        'status' => 'completed',
        'embedding' => [0.9, 0.1, 0.0],
    ]);

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('fromDocumentFallback');
    $method->setAccessible(true);

    $chunk = $method->invoke($service, $document, 3);

    expect($chunk->document_id)->toBe($document->id)
        ->and($chunk->chunk_index)->toBe(3)
        ->and($chunk->content)->toBe('fallback body')
        ->and($chunk->metadata)->toBe(['source' => 'document_fallback'])
        ->and($chunk->relationLoaded('document'))->toBeTrue();
});

afterEach(function (): void {
    Mockery::close();
});
