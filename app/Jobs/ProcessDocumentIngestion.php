<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\DocumentChunkingService;
use App\Services\EmbeddingService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessDocumentIngestion implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * Retry delays in seconds for transient errors.
     *
     * @var array<int>
     */
    public array $backoff = [10, 30, 120];

    public function __construct(public int $documentId)
    {
        $this->onQueue(config('services.document.queue', 'documents'));
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

        $document->forceFill([
            'status' => 'processing',
            'error_message' => null,
            'processing_started_at' => now(),
        ])->save();

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
        } catch (Exception $exception) {
            if ($this->attempts() < $this->tries) {
                throw $exception;
            }

            $document->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ])->save();
        }
    }
}
