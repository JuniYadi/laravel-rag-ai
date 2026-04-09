<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

class EmbeddingService
{
    protected string $provider;

    protected string $model;

    protected string $apiKey;

    protected string $baseUrl;

    protected int $dimensions;

    public function __construct()
    {
        $this->provider = mb_strtolower((string) config('services.embedding.provider', 'openai'));
        $this->model = (string) config('services.embedding.model', 'text-embedding-3-small');
        $this->apiKey = (string) config('services.embedding.api_key', '');
        $this->baseUrl = rtrim((string) config('services.embedding.base_url', 'https://api.openai.com/v1'), '/');
        $this->dimensions = max(1, (int) config('services.embedding.dimensions', 1536));

        if ($this->model === '') {
            throw new LogicException('Embedding model is not configured. Set services.embedding.model / EMBEDDING_MODEL.');
        }

        if ($this->apiKey === '') {
            throw new LogicException('Missing embedding API key. Set services.embedding.api_key / EMBEDDING_API_KEY.');
        }

        if ($this->baseUrl === '') {
            throw new LogicException('Embedding base URL is not configured. Set services.embedding.base_url / EMBEDDING_BASE_URL.');
        }
    }

    /**
     * Generate embedding for a single text
     */
    public function generateEmbedding(string $text): array
    {
        $embeddings = $this->generateEmbeddings([$text]);

        return $embeddings[0] ?? [];
    }

    /**
     * Generate embeddings for multiple texts
     */
    public function generateEmbeddings(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        return match ($this->provider) {
            'openai', 'openai-compatible' => $this->generateOpenAiCompatibleEmbeddings($texts),
            default => throw new LogicException("Unsupported embedding provider [{$this->provider}]. Supported providers: openai, openai-compatible."),
        };
    }

    protected function generateOpenAiCompatibleEmbeddings(array $texts): array
    {
        $response = Http::timeout(60)
            ->withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post($this->baseUrl.'/embeddings', [
                'model' => $this->model,
                'input' => $texts,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Embedding API request failed with HTTP '.$response->status().'. Verify embedding provider URL, API key, and model availability.');
        }

        $payload = $response->json();
        $rawEmbeddings = $payload['data'] ?? null;

        if (! is_array($rawEmbeddings)) {
            throw new RuntimeException('Embedding API response missing data[] payload.');
        }

        $embeddings = array_map(function ($item): array {
            $embedding = is_array($item) ? ($item['embedding'] ?? null) : null;

            if (! is_array($embedding)) {
                throw new RuntimeException('Embedding API response contains an invalid embedding item.');
            }

            return array_map(fn ($value) => (float) $value, $embedding);
        }, $rawEmbeddings);

        if (count($embeddings) !== count($texts)) {
            throw new RuntimeException(sprintf(
                'Embedding API returned %d vector(s) for %d input(s).',
                count($embeddings),
                count($texts)
            ));
        }

        return $embeddings;
    }

    /**
     * Generate embedding using Laravel AI string helper
     */
    public function generateEmbeddingViaHelper(string $text): array
    {
        return Str::of($text)->toEmbeddings()->toArray();
    }

    /**
     * Average multiple embeddings into a single centroid vector.
     */
    public function averageEmbeddings(array $embeddings): array
    {
        if (empty($embeddings)) {
            return [];
        }

        $dimensions = count($embeddings[0]);
        $totals = array_fill(0, $dimensions, 0.0);

        foreach ($embeddings as $embedding) {
            for ($i = 0; $i < $dimensions; $i++) {
                $totals[$i] += (float) ($embedding[$i] ?? 0.0);
            }
        }

        $count = count($embeddings);

        return array_map(fn (float $value) => $value / $count, $totals);
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
     * Get embedding provider
     */
    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * Get the embedding model name
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Get embedding API base URL
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Get the embedding dimensions
     */
    public function getDimensions(): int
    {
        return $this->dimensions;
    }
}
