<?php

use App\Services\PgVectorPreflightService;
use App\Services\VectorSearchService;

test('pgsql mode throws actionable error when pgvector extension is missing before search', function () {
    $preflight = new class extends PgVectorPreflightService
    {
        protected function currentDriver(): string
        {
            return 'pgsql';
        }

        protected function vectorExtensionExists(): bool
        {
            return false;
        }
    };

    $embeddingService = Mockery::mock(\App\Services\EmbeddingService::class);
    $service = new VectorSearchService($embeddingService, $preflight);

    expect(fn () => $service->searchByEmbedding([0.1, 0.2, 0.3]))
        ->toThrow(RuntimeException::class, 'PostgreSQL pgvector extension is not installed.');
});

afterEach(function (): void {
    Mockery::close();
});
