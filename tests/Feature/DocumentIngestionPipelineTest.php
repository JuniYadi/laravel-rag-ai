<?php

use App\Jobs\ProcessDocumentIngestion;
use App\Livewire\DocumentUploader;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\DocumentChunkingService;
use App\Services\DocumentParserService;
use App\Services\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('0', 32)));
});

test('upload request dispatches async ingestion job and returns quickly', function () {
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    $uploadedFile = UploadedFile::fake()->createWithContent(
        'notes.txt',
        'This is a test document for async ingestion.'
    );

    $parserMock = Mockery::mock(DocumentParserService::class);
    $parserMock->shouldReceive('parse')
        ->once()
        ->andReturn([
            'title' => 'notes',
            'file_path' => 'documents/notes.txt',
            'file_type' => 'txt',
            'content' => 'This is a test document for async ingestion.',
            'excerpt' => 'This is a test document for async ingestion.',
        ]);

    $this->app->instance(DocumentParserService::class, $parserMock);

    Livewire::test(DocumentUploader::class)
        ->set('uploads', [$uploadedFile])
        ->assertSet('isProcessing', false)
        ->assertSet('statusMessage', 'Upload finished. Queued: 1, failed: 0.');

    $document = Document::first();

    expect($document)->not->toBeNull()
        ->and($document->status)->toBe('pending')
        ->and($document->error_message)->toBeNull();

    Queue::assertPushed(ProcessDocumentIngestion::class, function (ProcessDocumentIngestion $job) use ($document) {
        return $job->documentId === $document->id;
    });
});

test('ingestion job marks document completed when embedding succeeds', function () {
    $user = User::factory()->create();

    $document = Document::create([
        'user_id' => $user->id,
        'title' => 'Manual Doc',
        'file_path' => 'documents/manual.txt',
        'file_type' => 'txt',
        'content' => 'content',
        'excerpt' => 'excerpt',
        'status' => 'pending',
    ]);

    $embeddingMock = Mockery::mock(EmbeddingService::class);
    $embeddingMock->shouldReceive('generateEmbeddings')
        ->once()
        ->with(['content'])
        ->andReturn([[0.11, 0.22, 0.33]]);
    $embeddingMock->shouldReceive('averageEmbeddings')
        ->once()
        ->andReturn([0.11, 0.22, 0.33]);

    $chunkingMock = Mockery::mock(DocumentChunkingService::class);
    $chunkingMock->shouldReceive('chunk')
        ->once()
        ->with('content')
        ->andReturn([
            [
                'chunk_index' => 0,
                'content' => 'content',
                'excerpt' => 'content',
                'char_count' => 7,
                'token_count' => 2,
                'metadata' => ['chunk_size' => 1200, 'chunk_overlap' => 200],
            ],
        ]);

    $job = new ProcessDocumentIngestion($document->id);
    $job->handle($embeddingMock, $chunkingMock);

    $document->refresh();

    expect($document->status)->toBe('completed')
        ->and($document->embedding)->toBe([0.11, 0.22, 0.33])
        ->and($document->completed_at)->not->toBeNull()
        ->and($document->error_message)->toBeNull();

    $storedChunk = DocumentChunk::query()->first();

    expect($storedChunk)->not->toBeNull()
        ->and($storedChunk->document_id)->toBe($document->id)
        ->and($storedChunk->chunk_index)->toBe(0)
        ->and($storedChunk->content)->toBe('content')
        ->and($storedChunk->embedding)->toBe([0.11, 0.22, 0.33]);
});

test('ingestion job marks document failed on final retry failure', function () {
    $user = User::factory()->create();

    $document = Document::create([
        'user_id' => $user->id,
        'title' => 'Broken Doc',
        'file_path' => 'documents/broken.txt',
        'file_type' => 'txt',
        'content' => 'content',
        'excerpt' => 'excerpt',
        'status' => 'pending',
    ]);

    $embeddingMock = Mockery::mock(EmbeddingService::class);
    $embeddingMock->shouldReceive('generateEmbeddings')
        ->once()
        ->andThrow(new RuntimeException('Embedding API timeout'));

    $chunkingMock = Mockery::mock(DocumentChunkingService::class);
    $chunkingMock->shouldReceive('chunk')
        ->once()
        ->andReturn([
            [
                'chunk_index' => 0,
                'content' => 'content',
                'excerpt' => 'content',
                'char_count' => 7,
                'token_count' => 2,
                'metadata' => ['chunk_size' => 1200, 'chunk_overlap' => 200],
            ],
        ]);

    $job = new ProcessDocumentIngestion($document->id);
    $job->tries = 1;
    $job->handle($embeddingMock, $chunkingMock);

    $document->refresh();

    expect($document->status)->toBe('failed')
        ->and($document->error_message)->toContain('Document ingestion failed after 1 attempt(s).')
        ->and($document->error_message)->toContain('Embedding API timeout')
        ->and(DocumentChunk::query()->count())->toBe(0);
});

test('ingestion job returns early when document is missing', function () {
    $embeddingMock = Mockery::mock(EmbeddingService::class);
    $chunkingMock = Mockery::mock(DocumentChunkingService::class);

    $job = new ProcessDocumentIngestion(999999);
    $job->handle($embeddingMock, $chunkingMock);

    expect(Document::query()->count())->toBe(0)
        ->and(DocumentChunk::query()->count())->toBe(0);
});

test('ingestion job retries when attempts remain', function () {
    $user = User::factory()->create();

    $document = Document::create([
        'user_id' => $user->id,
        'title' => 'Retry Doc',
        'file_path' => 'documents/retry.txt',
        'file_type' => 'txt',
        'content' => 'content',
        'excerpt' => 'excerpt',
        'status' => 'pending',
    ]);

    $embeddingMock = Mockery::mock(EmbeddingService::class);
    $embeddingMock->shouldReceive('generateEmbeddings')
        ->once()
        ->andThrow(new RuntimeException('Temporary upstream timeout'));

    $chunkingMock = Mockery::mock(DocumentChunkingService::class);
    $chunkingMock->shouldReceive('chunk')
        ->once()
        ->andReturn([
            [
                'chunk_index' => 0,
                'content' => 'content',
                'excerpt' => 'content',
                'char_count' => 7,
                'token_count' => 2,
                'metadata' => ['chunk_size' => 1200, 'chunk_overlap' => 200],
            ],
        ]);

    $job = new ProcessDocumentIngestion($document->id);
    $job->tries = 2;

    expect(fn () => $job->handle($embeddingMock, $chunkingMock))
        ->toThrow(RuntimeException::class, 'Temporary upstream timeout');

    $document->refresh();

    expect($document->status)->toBe('processing')
        ->and($document->error_message)->toBeNull()
        ->and($document->completed_at)->toBeNull();
});

afterEach(function (): void {
    Mockery::close();
});
