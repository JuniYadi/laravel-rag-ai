<?php

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\EmbeddingService;
use App\Services\RagService;
use App\Services\VectorSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeFeatureChunk(
    int $id,
    int $documentId,
    string $title,
    string $content,
    int $chunkIndex,
    int $userId
): DocumentChunk {
    $document = new Document([
        'id' => $documentId,
        'user_id' => $userId,
        'title' => $title,
        'file_type' => 'md',
    ]);

    $chunk = new DocumentChunk([
        'id' => $id,
        'document_id' => $documentId,
        'user_id' => $userId,
        'chunk_index' => $chunkIndex,
        'content' => $content,
        'excerpt' => mb_substr($content, 0, 120),
        'embedding' => [0.1, 0.2, 0.3],
        'char_count' => mb_strlen($content),
        'token_count' => max(1, (int) ceil(mb_strlen($content) / 4)),
        'metadata' => [],
    ]);

    $chunk->setRelation('document', $document);

    return $chunk;
}

function makeFeatureRagService(EmbeddingService $embeddingService, VectorSearchService $vectorSearchService): RagService
{
    config()->set('services.llm.provider', 'openai');
    config()->set('services.llm.model', 'gpt-4o-mini');
    config()->set('services.llm.base_url', 'https://api.openai.com/v1');
    config()->set('services.llm.api_key', 'test-key');
    config()->set('services.rag.max_chunks', 5);
    config()->set('services.rag.max_context_chars', 12000);
    config()->set('services.rag.max_context_tokens', 3000);
    config()->set('services.rag.min_similarity', 0.7);
    config()->set('services.rag.low_confidence_similarity', 0.55);

    return new class($vectorSearchService, $embeddingService) extends RagService
    {
        protected function callLlm(string $systemPrompt, string $userPrompt, ?string $requestId = null): array
        {
            preg_match_all('/\[S\d+\]/', $userPrompt, $matches);
            $refs = array_values(array_unique($matches[0] ?? []));

            return [
                'answer' => 'Grounded response '.implode(' ', $refs),
                'token_usage' => [
                    'prompt_tokens' => 140,
                    'completion_tokens' => 35,
                    'total_tokens' => 175,
                ],
            ];
        }
    };
}

test('rag query returns grounded answer with source refs for authenticated user', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $chunk = makeFeatureChunk(
        id: 1001,
        documentId: 501,
        title: 'Deployment Runbook',
        content: 'Deploy by running migrations then health checks.',
        chunkIndex: 0,
        userId: $user->id,
    );

    $embeddingMock = Mockery::mock(EmbeddingService::class);
    $embeddingMock->shouldReceive('generateEmbedding')
        ->once()
        ->with('How should we deploy?')
        ->andReturn([1.0, 0.0, 0.0]);
    $embeddingMock->shouldReceive('cosineSimilarity')
        ->once()
        ->andReturn(0.94);

    $vectorSearchMock = Mockery::mock(VectorSearchService::class);
    $vectorSearchMock->shouldReceive('searchByEmbedding')
        ->once()
        ->with([1.0, 0.0, 0.0], 15, 0.7, $user->id)
        ->andReturn(collect([$chunk]));

    $service = makeFeatureRagService($embeddingMock, $vectorSearchMock);

    $result = $service->query('How should we deploy?');

    expect($result['answer'])->toContain('Grounded response')
        ->and($result['answer'])->toContain('[S1]')
        ->and($result['document_count'])->toBe(1)
        ->and($result['sources'])->toHaveCount(1)
        ->and($result['sources'][0]['source_ref'])->toBe('S1')
        ->and($result['sources'][0]['title'])->toBe('Deployment Runbook')
        ->and($result['sources'][0]['chunk_index'])->toBe(0)
        ->and($result['sources'][0]['similarity'])->toBe(0.94);
});

test('rag query returns fallback answer and empty sources when no relevant chunks found', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $embeddingMock = Mockery::mock(EmbeddingService::class);
    $embeddingMock->shouldReceive('generateEmbedding')
        ->once()
        ->with('What are the billing rules?')
        ->andReturn([1.0, 0.0, 0.0]);

    $vectorSearchMock = Mockery::mock(VectorSearchService::class);
    $vectorSearchMock->shouldReceive('searchByEmbedding')
        ->once()
        ->with([1.0, 0.0, 0.0], 15, 0.7, $user->id)
        ->andReturn(collect());

    $service = makeFeatureRagService($embeddingMock, $vectorSearchMock);

    $result = $service->query('What are the billing rules?');

    expect($result['document_count'])->toBe(0)
        ->and($result['sources'])->toHaveCount(0)
        ->and($result['answer'])->toContain("I don't have any relevant document chunks");
});

afterEach(function (): void {
    Mockery::close();
});
