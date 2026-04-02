<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Collection;

class VectorSearchService
{
    protected EmbeddingService $embeddingService;

    public function __construct(EmbeddingService $embeddingService)
    {
        $this->embeddingService = $embeddingService;
    }

    /**
     * Search for similar content chunks using text query.
     */
    public function search(string $query, int $limit = 5, float $minSimilarity = 0.7, ?int $userId = null): Collection
    {
        $queryEmbedding = $this->embeddingService->generateEmbedding($query);

        return $this->searchByEmbedding($queryEmbedding, $limit, $minSimilarity, $userId);
    }

    /**
     * Search for similar chunks using embedding vector.
     * Falls back to document-level vectors when chunks are not present.
     */
    public function searchByEmbedding(array $embedding, int $limit = 5, float $minSimilarity = 0.7, ?int $userId = null): Collection
    {
        $chunks = DocumentChunk::query()
            ->with('document')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->whereVectorSimilarTo('embedding', $embedding, minSimilarity: $minSimilarity)
            ->limit($limit)
            ->get();

        if ($chunks->count() >= $limit) {
            return $chunks;
        }

        $remaining = $limit - $chunks->count();
        $alreadyIncludedDocumentIds = $chunks->pluck('document_id')->filter()->all();

        $fallbackDocuments = Document::query()
            ->whereNotNull('embedding')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->when(! empty($alreadyIncludedDocumentIds), fn ($q) => $q->whereNotIn('id', $alreadyIncludedDocumentIds))
            ->whereVectorSimilarTo('embedding', $embedding, minSimilarity: $minSimilarity)
            ->limit($remaining)
            ->get()
            ->map(fn (Document $document, int $index) => $this->fromDocumentFallback($document, $index));

        return $chunks->concat($fallbackDocuments)->values();
    }

    /**
     * Search with distance calculation.
     */
    public function searchWithDistance(string $query, int $limit = 5, float $maxDistance = 0.3, ?int $userId = null): Collection
    {
        $queryEmbedding = $this->embeddingService->generateEmbedding($query);

        return DocumentChunk::query()
            ->with('document')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->select('*')
            ->selectVectorDistance('embedding', $queryEmbedding, as: 'distance')
            ->whereVectorDistanceLessThan('embedding', $queryEmbedding, maxDistance: $maxDistance)
            ->orderByVectorDistance('embedding', $queryEmbedding)
            ->limit($limit)
            ->get();
    }

    /**
     * Get most similar chunks ordered by similarity.
     */
    public function getMostSimilar(string $query, int $limit = 10, ?int $userId = null): Collection
    {
        $queryEmbedding = $this->embeddingService->generateEmbedding($query);

        return DocumentChunk::query()
            ->with('document')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->select('*')
            ->selectVectorDistance('embedding', $queryEmbedding, as: 'distance')
            ->whereNotNull('embedding')
            ->orderByVectorDistance('embedding', $queryEmbedding)
            ->limit($limit)
            ->get();
    }

    /**
     * Find document by ID with similarity to query.
     */
    public function findWithSimilarity(int $documentId, string $query): ?array
    {
        $document = Document::find($documentId);

        if (! $document || ! $document->embedding) {
            return null;
        }

        $queryEmbedding = $this->embeddingService->generateEmbedding($query);
        $similarity = $this->embeddingService->cosineSimilarity(
            $document->embedding,
            $queryEmbedding
        );

        return [
            'document' => $document,
            'similarity' => $similarity,
        ];
    }

    /**
     * Calculate similarity score between query and stored chunks.
     */
    public function calculateSimilarities(string $query, ?int $userId = null): Collection
    {
        $queryEmbedding = $this->embeddingService->generateEmbedding($query);

        return DocumentChunk::query()
            ->with('document')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->whereNotNull('embedding')
            ->get()
            ->map(function ($chunk) use ($queryEmbedding) {
                $similarity = $this->embeddingService->cosineSimilarity(
                    $chunk->embedding,
                    $queryEmbedding
                );

                return [
                    'document' => $chunk,
                    'similarity' => $similarity,
                ];
            })
            ->sortByDesc('similarity');
    }

    protected function fromDocumentFallback(Document $document, int $index): DocumentChunk
    {
        $chunk = new DocumentChunk([
            'document_id' => $document->id,
            'chunk_index' => $index,
            'content' => $document->content,
            'excerpt' => $document->excerpt,
            'embedding' => $document->embedding,
            'char_count' => mb_strlen($document->content),
            'token_count' => max(1, (int) ceil(mb_strlen($document->content) / 4)),
            'metadata' => ['source' => 'document_fallback'],
        ]);

        $chunk->setRelation('document', $document);

        return $chunk;
    }
}
