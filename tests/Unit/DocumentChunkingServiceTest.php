<?php

use App\Services\DocumentChunkingService;
use Tests\TestCase;

uses(TestCase::class);

test('chunking service returns single chunk for short text', function () {
    config()->set('services.document.chunk_size', 1200);
    config()->set('services.document.chunk_overlap', 200);

    $service = new DocumentChunkingService;
    $chunks = $service->chunk('Short content for testing.');

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]['chunk_index'])->toBe(0)
        ->and($chunks[0]['content'])->toBe('Short content for testing.');
});

test('chunking service splits long text into multiple chunks', function () {
    config()->set('services.document.chunk_size', 500);
    config()->set('services.document.chunk_overlap', 100);

    $service = new DocumentChunkingService;
    $longText = str_repeat('Laravel RAG chunk test sentence. ', 100);

    $chunks = $service->chunk($longText);

    expect(count($chunks))->toBeGreaterThan(1);
    expect($chunks[0]['chunk_index'])->toBe(0)
        ->and($chunks[1]['chunk_index'])->toBe(1)
        ->and($chunks[0]['char_count'])->toBeGreaterThan(100)
        ->and($chunks[1]['token_count'])->toBeGreaterThan(10);
});

test('chunking service returns no chunks for blank text after normalization', function () {
    config()->set('services.document.chunk_size', 1200);
    config()->set('services.document.chunk_overlap', 200);

    $service = new DocumentChunkingService;
    $chunks = $service->chunk("  \n \t \n");

    expect($chunks)->toBe([]);
});

test('chunking service clamps overlap to chunk size minus one', function () {
    config()->set('services.document.chunk_size', 205);
    config()->set('services.document.chunk_overlap', 999);

    $service = new DocumentChunkingService;
    $text = str_repeat('A', 230);
    $chunks = $service->chunk($text);

    expect($chunks)->toHaveCount(27)
        ->and($chunks[0]['metadata']['chunk_overlap'])->toBe(204)
        ->and($chunks[25]['content'])->toBe(str_repeat('A', 205))
        ->and($chunks[26]['metadata']['chunk_size'])->toBe(205)
        ->and($chunks[26]['chunk_index'])->toBe(26);
});

test('chunk metadata includes configured chunk size and overlap', function () {
    config()->set('services.document.chunk_size', 205);
    config()->set('services.document.chunk_overlap', 4);

    $service = new DocumentChunkingService;
    $chunks = $service->chunk(str_repeat('A', 430));

    expect($chunks)->toHaveCount(3)
        ->and($chunks[0]['metadata']['chunk_size'])->toBe(205)
        ->and($chunks[0]['metadata']['chunk_overlap'])->toBe(4)
        ->and($chunks[0]['chunk_index'])->toBe(0)
        ->and($chunks[1]['chunk_index'])->toBe(1);
});
