<?php

use App\Livewire\DocumentUploader;
use App\Models\Document;
use App\Models\User;
use App\Services\EmbeddingService;
use App\Services\RagService;
use App\Services\VectorSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('document uploader lists only documents owned by authenticated user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $ownerDoc = Document::create([
        'user_id' => $owner->id,
        'title' => 'Owner Doc',
        'file_path' => 'documents/owner.txt',
        'file_type' => 'txt',
        'content' => 'owner content',
        'excerpt' => 'owner content',
        'status' => 'completed',
    ]);

    Document::create([
        'user_id' => $other->id,
        'title' => 'Other Doc',
        'file_path' => 'documents/other.txt',
        'file_type' => 'txt',
        'content' => 'other content',
        'excerpt' => 'other content',
        'status' => 'completed',
    ]);

    $component = Livewire::actingAs($owner)
        ->test(DocumentUploader::class)
        ->call('loadDocuments');

    $uploaded = $component->get('uploadedDocuments');

    expect($uploaded)->toHaveCount(1)
        ->and($uploaded[0]['id'])->toBe($ownerDoc->id)
        ->and($uploaded[0]['title'])->toBe('Owner Doc');
});

test('document uploader forbids deleting document owned by another user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $ownerDoc = Document::create([
        'user_id' => $owner->id,
        'title' => 'Owner Doc',
        'file_path' => 'documents/owner.txt',
        'file_type' => 'txt',
        'content' => 'owner content',
        'excerpt' => 'owner content',
        'status' => 'completed',
    ]);

    Livewire::actingAs($other)
        ->test(DocumentUploader::class)
        ->call('confirmDelete', $ownerDoc)
        ->assertForbidden();
});

test('rag retrieval forwards authenticated user scope to vector search', function () {
    $user = User::factory()->create();

    config()->set('services.llm.provider', 'openai');
    config()->set('services.llm.model', 'gpt-4o-mini');
    config()->set('services.llm.base_url', 'https://api.openai.com/v1');
    config()->set('services.llm.api_key', 'test-key');
    config()->set('services.rag.max_chunks', 5);
    config()->set('services.rag.max_context_chars', 12000);
    config()->set('services.rag.max_context_tokens', 3000);
    config()->set('services.rag.min_similarity', 0.7);
    config()->set('services.rag.low_confidence_similarity', 0.55);

    $embeddingMock = Mockery::mock(EmbeddingService::class);
    $embeddingMock->shouldReceive('generateEmbedding')
        ->once()
        ->with('owned query')
        ->andReturn([1.0, 0.0, 0.0]);

    $vectorSearchMock = Mockery::mock(VectorSearchService::class);
    $vectorSearchMock->shouldReceive('searchByEmbedding')
        ->once()
        ->with([1.0, 0.0, 0.0], 15, 0.7, $user->id)
        ->andReturn(collect());

    app()->instance(EmbeddingService::class, $embeddingMock);
    app()->instance(VectorSearchService::class, $vectorSearchMock);

    Livewire::actingAs($user)->test(DocumentUploader::class);

    $response = app(RagService::class)->query('owned query');

    expect($response['document_count'])->toBe(0);
});

afterEach(function (): void {
    Mockery::close();
});
