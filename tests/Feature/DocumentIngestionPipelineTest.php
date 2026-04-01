<?php

use App\Jobs\ProcessDocumentIngestion;
use App\Livewire\DocumentUploader;
use App\Models\Document;
use App\Services\DocumentParserService;
use App\Services\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
uses(RefreshDatabase::class);

test('upload request dispatches async ingestion job and returns quickly', function () {
    Queue::fake();

    $user = \App\Models\User::factory()->create();
    $this->actingAs($user);

    $uploadedFile = UploadedFile::fake()->createWithContent(
        'notes.txt',
        "This is a test document for async ingestion."
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
        ->set('upload', $uploadedFile)
        ->assertSet('isProcessing', false)
        ->assertSet('statusMessage', 'Upload accepted. Document is queued for processing.');

    $document = Document::first();

    expect($document)->not->toBeNull()
        ->and($document->status)->toBe('pending')
        ->and($document->error_message)->toBeNull();

    Queue::assertPushed(ProcessDocumentIngestion::class, function (ProcessDocumentIngestion $job) use ($document) {
        return $job->documentId === $document->id;
    });
});

test('ingestion job marks document completed when embedding succeeds', function () {
    $document = Document::create([
        'title' => 'Manual Doc',
        'file_path' => 'documents/manual.txt',
        'file_type' => 'txt',
        'content' => 'content',
        'excerpt' => 'excerpt',
        'status' => 'pending',
    ]);

    $embeddingMock = Mockery::mock(EmbeddingService::class);
    $embeddingMock->shouldReceive('generateEmbedding')
        ->once()
        ->with('content')
        ->andReturn([0.11, 0.22, 0.33]);

    $job = new ProcessDocumentIngestion($document->id);
    $job->handle($embeddingMock);

    $document->refresh();

    expect($document->status)->toBe('completed')
        ->and($document->embedding)->toBe([0.11, 0.22, 0.33])
        ->and($document->completed_at)->not->toBeNull()
        ->and($document->error_message)->toBeNull();
});

test('ingestion job marks document failed on final retry failure', function () {
    $document = Document::create([
        'title' => 'Broken Doc',
        'file_path' => 'documents/broken.txt',
        'file_type' => 'txt',
        'content' => 'content',
        'excerpt' => 'excerpt',
        'status' => 'pending',
    ]);

    $embeddingMock = Mockery::mock(EmbeddingService::class);
    $embeddingMock->shouldReceive('generateEmbedding')
        ->once()
        ->andThrow(new RuntimeException('Embedding API timeout'));

    $job = new ProcessDocumentIngestion($document->id);
    $job->tries = 1;
    $job->handle($embeddingMock);

    $document->refresh();

    expect($document->status)->toBe('failed')
        ->and($document->error_message)->toContain('Embedding API timeout');
});

afterEach(function (): void {
    Mockery::close();
});
