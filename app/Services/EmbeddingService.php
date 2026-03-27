<?php

namespace App\Services;

use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;

class EmbeddingService
{
    protected string $model;

    protected int $dimensions;

    public function __construct()
    {
        $this->model = config('services.embedding.model', 'text-embedding-3-small');
        $this->dimensions = config('services.embedding.dimensions', 1536);
    }

    /**
     * Generate embedding for a single text
     */
    public function generateEmbedding(string $text): array
    {
        $response = OpenAI::embeddings()->create([
            'model' => $this->model,
            'input' => $text,
        ]);

        return $response->embeddings[0]->embedding;
    }

    /**
     * Generate embeddings for multiple texts
     */
    public function generateEmbeddings(array $texts): array
    {
        $response = OpenAI::embeddings()->create([
            'model' => $this->model,
            'input' => $texts,
        ]);

        return array_map(
            fn ($embedding) => $embedding->embedding,
            $response->embeddings
        );
    }

    /**
     * Generate embedding using Laravel AI string helper
     */
    public function generateEmbeddingViaHelper(string $text): array
    {
        return Str::of($text)->toEmbeddings()->toArray();
    }

    /**
     * Calculate cosine similarity between two vectors
     */
    public function cosineSimilarity(array $vectorA, array $vectorB): float
    {
        $dotProduct = $this->dotProduct($vectorA, $vectorB);
        $magnitudeA = $this->magnitude($vectorA);
        $magnitudeB = $this->magnitude($vectorB);

        if ($magnitudeA === 0.0 || $magnitudeB === 0.0) {
            return 0.0;
        }

        return $dotProduct / ($magnitudeA * $magnitudeB);
    }

    /**
     * Calculate dot product of two vectors
     */
    protected function dotProduct(array $vectorA, array $vectorB): float
    {
        $sum = 0.0;
        $count = min(count($vectorA), count($vectorB));

        for ($i = 0; $i < $count; $i++) {
            $sum += $vectorA[$i] * $vectorB[$i];
        }

        return $sum;
    }

    /**
     * Calculate magnitude (L2 norm) of a vector
     */
    protected function magnitude(array $vector): float
    {
        $sum = 0.0;

        foreach ($vector as $component) {
            $sum += $component * $component;
        }

        return sqrt($sum);
    }

    /**
     * Get the embedding model name
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Get the embedding dimensions
     */
    public function getDimensions(): int
    {
        return $this->dimensions;
    }
}
