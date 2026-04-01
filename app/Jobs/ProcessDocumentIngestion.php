<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\EmbeddingService;
use Exception;
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
    public function handle(EmbeddingService $embeddingService): void
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
            $embedding = $embeddingService->generateEmbedding($document->content);

            $document->forceFill([
                'embedding' => $embedding,
                'status' => 'completed',
                'error_message' => null,
                'completed_at' => now(),
            ])->save();
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
