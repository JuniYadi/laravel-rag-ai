<?php

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\EmbeddingService;
use App\Services\RagService;
use App\Services\VectorSearchService;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class);

function makeChunk(
    int $id,
    int $documentId,
    string $title,
    string $content,
    int $chunkIndex,
    string $fileType = 'md'
): DocumentChunk {
    $document = new Document([
        'id' => $documentId,
        'title' => $title,
        'file_type' => $fileType,
    ]);

    $chunk = new DocumentChunk([
        'id' => $id,
        'document_id' => $documentId,
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

function makeRagServiceForRetrievalTests(
    EmbeddingService $embeddingService,
    VectorSearchService $vectorSearchService
): RagService {
    config()->set('services.llm.provider', 'openai');
    config()->set('services.llm.model', 'gpt-4o-mini');
    config()->set('services.llm.base_url', 'https://api.openai.com/v1');
    config()->set('services.llm.api_key', 'test-key');

    return new class($vectorSearchService, $embeddingService) extends RagService {
        protected function callLlm(string $systemPrompt, string $userPrompt): string
        {
            preg_match_all('/\[S\d+\]/', $userPrompt, $matches);
            $refs = array_values(array_unique($matches[0] ?? []));

            return 'Grounded answer '.implode(' ', $refs);
        }
    };
}

test('retrieveDocuments returns no chunks when all candidates are below similarity threshold', function () {
    config()->set('services.rag.max_chunks', 5);
    config()->set('services.rag.max_context_chars', 12000);
    config()->set('services.rag.max_context_tokens', 3000);
    config()->set('services.rag.min_similarity', 0.85);
    config()->set('services.rag.low_confidence_similarity', 0.55);

    $embeddingMock = Mockery::mock(EmbeddingService::class);
    $embeddingMock->shouldReceive('generateEmbedding')->once()->with('how to deploy?')->andReturn([1.0, 0.0, 0.0]);
    $embeddingMock->shouldReceive('cosineSimilarity')->once()->andReturn(0.2);

    $vectorSearchMock = Mockery::mock(VectorSearchService::class);
    $vectorSearchMock->shouldReceive('searchByEmbedding')
        ->once()
        ->with([1.0, 0.0, 0.0], 15, 0.85, null)
        ->andReturn(collect([
            makeChunk(101, 11, 'Low Similarity Doc', 'irrelevant context', 0, 'txt'),
        ]));

    $service = makeRagServiceForRetrievalTests($embeddingMock, $vectorSearchMock);

    $result = $service->query('how to deploy?');

    expect($result['document_count'])->toBe(0)
        ->and($result['sources'])->toHaveCount(0)
        ->and($result['retrieval']['top_similarity'])->toBe(0.0)
        ->and($result['retrieval']['used_chunks'])->toBe(0);
});

test('retrieveDocuments enforces chunk limit and keeps chunk-level source provenance for multi-source results', function () {
    config()->set('services.rag.max_chunks', 2);
    config()->set('services.rag.max_context_chars', 1000);
    config()->set('services.rag.max_context_tokens', 250);
    config()->set('services.rag.min_similarity', 0.7);
    config()->set('services.rag.low_confidence_similarity', 0.55);

    $chunkA = makeChunk(201, 21, 'Deploy Runbook', str_repeat('Deploy checklist step. ', 12), 0);
    $chunkB = makeChunk(202, 22, 'Rollback Guide', str_repeat('Rollback checklist step. ', 10), 0);
    $chunkC = makeChunk(203, 23, 'Postmortem Notes', str_repeat('Postmortem item. ', 15), 0);

    $embeddingMock = Mockery::mock(EmbeddingService::class);
    $embeddingMock->shouldReceive('generateEmbedding')->once()->with('deployment recovery flow')->andReturn([1.0, 0.0, 0.0]);
    $embeddingMock->shouldReceive('cosineSimilarity')->times(3)->andReturn(0.98, 0.95, 0.90);

    $vectorSearchMock = Mockery::mock(VectorSearchService::class);
    $vectorSearchMock->shouldReceive('searchByEmbedding')
        ->once()
        ->with([1.0, 0.0, 0.0], 6, 0.7, null)
        ->andReturn(new Collection([$chunkA, $chunkB, $chunkC]));

    $service = makeRagServiceForRetrievalTests($embeddingMock, $vectorSearchMock);

    $result = $service->query('deployment recovery flow', 5);

    expect($result['document_count'])->toBe(2)
        ->and($result['sources'])->toHaveCount(2)
        ->and($result['retrieval']['used_chunks'])->toBe(2)
        ->and($result['retrieval']['top_similarity'])->toBe(0.98)
        ->and($result['sources'][0]['source_ref'])->toBe('S1')
        ->and($result['sources'][1]['source_ref'])->toBe('S2')
        ->and($result['sources'][0]['title'])->toBe('Deploy Runbook')
        ->and($result['sources'][1]['title'])->toBe('Rollback Guide')
        ->and($result['sources'][0]['chunk_index'])->toBe(0)
        ->and($result['sources'][1]['chunk_index'])->toBe(0)
        ->and($result['sources'][0]['similarity'])->toBe(0.98)
        ->and($result['sources'][1]['similarity'])->toBe(0.95);

    expect($result['answer'])->toContain('[S1]')
        ->toContain('[S2]');
});

test('query marks retrieval as low confidence when top similarity is below threshold', function () {
    config()->set('services.rag.max_chunks', 3);
    config()->set('services.rag.max_context_chars', 12000);
    config()->set('services.rag.max_context_tokens', 3000);
    config()->set('services.rag.min_similarity', 0.5);
    config()->set('services.rag.low_confidence_similarity', 0.8);

    $embeddingMock = Mockery::mock(EmbeddingService::class);
    $embeddingMock->shouldReceive('generateEmbedding')->once()->with('what is the final SLA?')->andReturn([1.0, 0.0, 0.0]);
    $embeddingMock->shouldReceive('cosineSimilarity')->once()->andReturn(0.62);

    $vectorSearchMock = Mockery::mock(VectorSearchService::class);
    $vectorSearchMock->shouldReceive('searchByEmbedding')
        ->once()
        ->with([1.0, 0.0, 0.0], 9, 0.5, null)
        ->andReturn(collect([
            makeChunk(301, 31, 'Partial Notes', 'Partial context that might help.', 0),
        ]));

    $service = makeRagServiceForRetrievalTests($embeddingMock, $vectorSearchMock);

    $result = $service->query('what is the final SLA?');

    expect($result['document_count'])->toBe(1)
        ->and($result['retrieval']['top_similarity'])->toBe(0.62)
        ->and($result['retrieval']['is_low_confidence'])->toBeTrue()
        ->and($result['sources'][0]['source_ref'])->toBe('S1')
        ->and($result['sources'][0]['similarity'])->toBe(0.62);
});

afterEach(function (): void {
    Mockery::close();
});
