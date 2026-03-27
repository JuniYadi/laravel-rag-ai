<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Collection;

class VectorSearchService
{
    protected EmbeddingService $embeddingService;

    public function __construct(EmbeddingService $embeddingService)
    {
        $this->embeddingService = $embeddingService;
    }

    /**
     * Search for similar documents using text query
     */
    public function search(string $query, int $limit = 5, float $minSimilarity = 0.7): Collection
    {
        $queryEmbedding = $this->embeddingService->generateEmbedding($query);

        return $this->searchByEmbedding($queryEmbedding, $limit, $minSimilarity);
    }

    /**
     * Search for similar documents using embedding vector
     */
    public function searchByEmbedding(array $embedding, int $limit = 5, float $minSimilarity = 0.7): Collection
    {
        return Document::query()
            ->whereVectorSimilarTo('embedding', $embedding, minSimilarity: $minSimilarity)
            ->limit($limit)
            ->get();
    }

    /**
     * Search with distance calculation
     */
    public function searchWithDistance(string $query, int $limit = 5, float $maxDistance = 0.3): Collection
    {
        $queryEmbedding = $this->embeddingService->generateEmbedding($query);

        return Document::query()
            ->select('*')
            ->selectVectorDistance('embedding', $queryEmbedding, as: 'distance')
            ->whereVectorDistanceLessThan('embedding', $queryEmbedding, maxDistance: $maxDistance)
            ->orderByVectorDistance('embedding', $queryEmbedding)
            ->limit($limit)
            ->get();
    }

    /**
     * Get most similar documents ordered by similarity
     */
    public function getMostSimilar(string $query, int $limit = 10): Collection
    {
        $queryEmbedding = $this->embeddingService->generateEmbedding($query);

        return Document::query()
            ->select('*')
            ->selectVectorDistance('embedding', $queryEmbedding, as: 'distance')
            ->whereNotNull('embedding')
            ->orderByVectorDistance('embedding', $queryEmbedding)
            ->limit($limit)
            ->get();
    }

    /**
     * Find document by ID with similarity to query
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
     * Calculate similarity score between query and stored documents
     */
    public function calculateSimilarities(string $query): Collection
    {
        $queryEmbedding = $this->embeddingService->generateEmbedding($query);

        return Document::query()
            ->whereNotNull('embedding')
            ->get()
            ->map(function ($document) use ($queryEmbedding) {
                $similarity = $this->embeddingService->cosineSimilarity(
                    $document->embedding,
                    $queryEmbedding
                );

                return [
                    'document' => $document,
                    'similarity' => $similarity,
                ];
            })
            ->sortByDesc('similarity');
    }
}
