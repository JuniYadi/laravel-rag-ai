<?php

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\EmbeddingService;
use App\Services\RagService;
use App\Services\VectorSearchService;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class);

function makeRagServiceForHelperTests(EmbeddingService $embeddingService, VectorSearchService $vectorSearchService): RagService
{
    config()->set('services.llm.provider', 'openai');
    config()->set('services.llm.model', 'gpt-4o-mini');
    config()->set('services.llm.base_url', 'https://api.openai.com/v1');
    config()->set('services.llm.api_key', 'test-key');
    config()->set('services.rag.max_chunks', 2);
    config()->set('services.rag.max_context_chars', 80);
    config()->set('services.rag.max_context_tokens', 120);
    config()->set('services.rag.min_similarity', 0.5);
    config()->set('services.rag.low_confidence_similarity', 0.7);

    return new class($vectorSearchService, $embeddingService) extends RagService
    {
        protected Collection $queryDocuments;

        public function __construct(VectorSearchService $vectorSearchService, EmbeddingService $embeddingService)
        {
            parent::__construct($vectorSearchService, $embeddingService);
            $this->queryDocuments = collect();
        }

        public function buildUserPromptPublic(string $question, string $context, bool $isLowConfidence = false): string
        {
            return $this->buildUserPrompt($question, $context, $isLowConfidence);
        }

        public function fitChunkToBudgetPublic(mixed $chunk, int $remainingChars, int $remainingTokens): mixed
        {
            return $this->fitChunkToBudget($chunk, $remainingChars, $remainingTokens);
        }

        public function retrieveDocuments(string $query, int $limit = 5, ?int $userId = null, ?string $requestId = null): Collection
        {
            return $this->queryDocuments;
        }
    };
}

function makeChunkForRagService(
    int $id,
    int $documentId,
    string $title,
    string $content,
    int $chunkIndex,
    float $ragSimilarity
): DocumentChunk {
    $document = new Document([
        'id' => $documentId,
        'title' => $title,
        'file_type' => 'md',
    ]);

    $chunk = new DocumentChunk([
        'id' => $id,
        'document_id' => $documentId,
        'chunk_index' => $chunkIndex,
        'content' => $content,
        'excerpt' => mb_substr($content, 0, 80),
        'embedding' => [0.1, 0.2, 0.3],
        'char_count' => mb_strlen($content),
        'token_count' => max(1, (int) ceil(mb_strlen($content) / 4)),
        'metadata' => [],
    ]);

    $chunk->setRelation('document', $document);
    $chunk->setAttribute('rag_similarity', $ragSimilarity);

    return $chunk;
}

function setRagServiceDocuments(RagService $service, Collection $documents): void
{
    $reflection = new ReflectionClass($service);
    $property = $reflection->getProperty('queryDocuments');

    $property->setAccessible(true);
    $property->setValue($service, $documents);
}

test('buildUserPrompt adds uncertainty instructions for low-confidence retrieval', function () {
    $embeddingMock = Mockery::mock(EmbeddingService::class);
    $vectorSearchMock = Mockery::mock(VectorSearchService::class);
    $service = makeRagServiceForHelperTests($embeddingMock, $vectorSearchMock);

    $lowConfidencePrompt = $service->buildUserPromptPublic('How do we deploy?', '[$S1] guide chunk', true);
    $normalPrompt = $service->buildUserPromptPublic('How do we deploy?', '[$S1] guide chunk', false);

    expect($lowConfidencePrompt)->toContain('low confidence')
        ->and($normalPrompt)->not->toContain('low confidence');
});

test('fitChunkToBudget truncates long chunks and marks them as truncated', function () {
    $embeddingMock = Mockery::mock(EmbeddingService::class);
    $vectorSearchMock = Mockery::mock(VectorSearchService::class);
    $service = makeRagServiceForHelperTests($embeddingMock, $vectorSearchMock);

    $chunk = new DocumentChunk([
        'content' => 'alpha beta gamma delta epsilon',
        'excerpt' => 'alpha',
    ]);

    $truncated = $service->fitChunkToBudgetPublic($chunk, 5, 1);

    expect($truncated)->not->toBeNull()
        ->and($truncated->content)->toBe('alp…')
        ->and($truncated->rag_truncated)->toBeTrue()
        ->and($truncated->excerpt)->toBe('alpha');

    $skipped = $service->fitChunkToBudgetPublic(new DocumentChunk(['content' => '   ']), 5, 5);

    expect($skipped)->toBeNull();
});

test('queryWithStream marks low-confidence retrieval when similarity is below threshold', function () {
    $embeddingMock = Mockery::mock(EmbeddingService::class);
    $vectorSearchMock = Mockery::mock(VectorSearchService::class);
    $service = makeRagServiceForHelperTests($embeddingMock, $vectorSearchMock);

    setRagServiceDocuments($service, collect([
        makeChunkForRagService(1, 11, 'Runbook', 'Deployment checklist exists.', 0, 0.42),
    ]));

    $response = $service->queryWithStream('What is our runbook?', 5);

    expect($response['retrieval']['is_low_confidence'])->toBeTrue()
        ->and($response['documents']->first()->rag_similarity)->toBe(0.42)
        ->and($response['context'])->toContain('[S1]')
        ->and($response['user_prompt'])->toContain('low confidence');
});

test('evaluateQuery returns quality tiers and context metrics', function () {
    $embeddingMock = Mockery::mock(EmbeddingService::class);
    $vectorSearchMock = Mockery::mock(VectorSearchService::class);
    $service = makeRagServiceForHelperTests($embeddingMock, $vectorSearchMock);

    setRagServiceDocuments($service, collect([
        makeChunkForRagService(1, 12, 'Runbook', 'One', 0, 0.9),
        makeChunkForRagService(2, 13, 'Runbook', 'Two', 1, 0.82),
    ]));

    $good = $service->evaluateQuery('Deployment questions');

    expect($good['question_length'])->toBe(20)
        ->and($good['documents_found'])->toBe(2)
        ->and($good['has_sufficient_context'])->toBeTrue()
        ->and($good['estimated_answer_quality'])->toBe('excellent')
        ->and($good['top_similarity'])->toBe(0.9);

    setRagServiceDocuments($service, collect());

    $poor = $service->evaluateQuery('No docs yet');

    expect($poor['documents_found'])->toBe(0)
        ->and($poor['has_sufficient_context'])->toBeFalse()
        ->and($poor['estimated_answer_quality'])->toBe('poor');
});

afterEach(function (): void {
    Mockery::close();
});
