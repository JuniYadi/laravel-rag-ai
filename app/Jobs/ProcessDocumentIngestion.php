<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\DocumentChunkingService;
use App\Services\EmbeddingService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessDocumentIngestion implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    /**
     * Retry delays in seconds for transient errors.
     *
     * @var array<int>
     */
    public array $backoff;

    protected string $requestId;

    public function __construct(public int $documentId)
    {
        $this->onQueue(config('services.document.queue', 'documents'));

        $configuredTries = (int) config('services.document.ingestion_retry.tries', 3);
        $this->tries = max(1, $configuredTries);

        $configuredBackoff = config('services.document.ingestion_retry.backoff_seconds', [10, 30, 120]);
        $backoff = is_array($configuredBackoff)
            ? array_values(array_map(fn ($value) => max(0, (int) $value), $configuredBackoff))
            : [10, 30, 120];
        $this->backoff = $backoff === [] ? [10, 30, 120] : $backoff;

        $this->requestId = (string) Str::uuid();
    }

    /**
     * Execute the job.
     */
    public function handle(EmbeddingService $embeddingService, DocumentChunkingService $chunkingService): void
    {
        $document = Document::find($this->documentId);

        if (! $document) {
            return;
        }

        $phaseStartedAt = microtime(true);

        $document->forceFill([
            'status' => 'processing',
            'error_message' => null,
            'processing_started_at' => now(),
        ])->save();

        Log::info('rag.ingestion.started', [
            'request_id' => $this->requestId,
            'document_id' => $document->id,
            'user_id' => $document->user_id,
            'attempt' => $this->attempts(),
            'max_attempts' => $this->tries,
        ]);

        try {
            $chunks = $chunkingService->chunk($document->content);

            if (empty($chunks)) {
                throw new Exception('Unable to generate chunks for document.');
            }

            $chunkEmbeddings = $embeddingService->generateEmbeddings(
                array_map(fn (array $chunk) => $chunk['content'], $chunks)
            );

            DB::transaction(function () use ($document, $chunks, $chunkEmbeddings, $embeddingService): void {
                $document->chunks()->delete();

                $storedChunks = [];

                foreach ($chunks as $index => $chunk) {
                    $vector = $chunkEmbeddings[$index] ?? null;

                    if (! is_array($vector)) {
                        throw new Exception('Missing chunk embedding at index '.$index.'.');
                    }

                    $storedChunks[] = DocumentChunk::create([
                        'document_id' => $document->id,
                        'user_id' => $document->user_id,
                        'chunk_index' => $chunk['chunk_index'],
                        'content' => $chunk['content'],
                        'excerpt' => $chunk['excerpt'],
                        'embedding' => $vector,
                        'char_count' => $chunk['char_count'],
                        'token_count' => $chunk['token_count'],
                        'metadata' => $chunk['metadata'],
                    ]);
                }

                $documentEmbedding = $embeddingService->averageEmbeddings(
                    array_map(fn (DocumentChunk $chunk) => $chunk->embedding, $storedChunks)
                );

                $document->forceFill([
                    'embedding' => $documentEmbedding,
                    'status' => 'completed',
                    'error_message' => null,
                    'completed_at' => now(),
                ])->save();
            });

            Log::info('rag.ingestion.completed', [
                'request_id' => $this->requestId,
                'document_id' => $document->id,
                'user_id' => $document->user_id,
                'chunk_count' => count($chunks),
                'latency_ms' => (int) round((microtime(true) - $phaseStartedAt) * 1000),
            ]);
        } catch (Exception $exception) {
            $isRetriable = $this->attempts() < $this->tries;

            Log::warning('rag.ingestion.retry', [
                'request_id' => $this->requestId,
                'document_id' => $document->id,
                'user_id' => $document->user_id,
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries,
                'retriable' => $isRetriable,
                'error' => $exception->getMessage(),
            ]);

            if ($isRetriable) {
                throw $exception;
            }

            $actionableError = sprintf(
                'Document ingestion failed after %d attempt(s). Last error: %s. Please verify embedding provider/API key and inspect queue worker logs.',
                $this->tries,
                $exception->getMessage()
            );

            $document->forceFill([
                'status' => 'failed',
                'error_message' => $actionableError,
            ])->save();

            Log::error('rag.ingestion.failed', [
                'request_id' => $this->requestId,
                'document_id' => $document->id,
                'user_id' => $document->user_id,
                'alertable' => true,
                'signal' => 'DOCUMENT_INGESTION_FAILED',
                'error' => $exception->getMessage(),
                'hint' => 'Inspect queue workers and embedding provider connectivity. This failure is terminal after max retries.',
            ]);
        }
    }
}
