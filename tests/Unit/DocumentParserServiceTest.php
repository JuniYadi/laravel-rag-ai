<?php

use App\Services\DocumentParserService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

test('parser extracts normalized txt content and stores uploaded file', function () {
    Storage::fake('local');
    config()->set('services.document.disk', 'local');
    config()->set('services.document.storage_path', 'documents-test');

    $file = UploadedFile::fake()->createWithContent(
        'notes.txt',
        "Hello   world\n\n\nLine\tTwo"
    );

    $result = app(DocumentParserService::class)->parse($file);

    expect($result['title'])->toBe('notes')
        ->and($result['file_type'])->toBe('txt')
        ->and($result['content'])->toBe("Hello world\n\nLine Two")
        ->and($result['file_path'])->toStartWith('documents-test/')
        ->and(Storage::disk('local')->exists($result['file_path']))->toBeTrue();
});

test('parser strips markdown formatting into plain text', function () {
    Storage::fake('local');
    config()->set('services.document.disk', 'local');

    $file = UploadedFile::fake()->createWithContent(
        'guide.md',
        "# Title\n\n**Bold** and *italic* with `code`.\n\n[Docs](https://example.com)"
    );

    $result = app(DocumentParserService::class)->parse($file);

    expect($result['file_type'])->toBe('md')
        ->and($result['content'])->toContain('Title')
        ->and($result['content'])->toContain('Bold and italic with code.')
        ->and($result['content'])->toContain('Docs')
        ->and($result['content'])->not->toContain('https://example.com');
});

test('parser throws for unsupported file extension', function () {
    $file = UploadedFile::fake()->createWithContent('dataset.csv', 'a,b,c');

    expect(fn () => app(DocumentParserService::class)->parse($file))
        ->toThrow(InvalidArgumentException::class, 'Unsupported file type: csv');
});
