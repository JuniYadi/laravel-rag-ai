<?php

use App\Livewire\DocumentUploader;
use App\Livewire\RagChat;
use App\Models\ChatMessage;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentParserService;
use App\Services\RagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('0', 32)));

    if (! is_dir(public_path('build'))) {
        mkdir(public_path('build'), 0755, true);
    }

    if (! file_exists(public_path('build/manifest.json'))) {
        file_put_contents(public_path('build/manifest.json'), json_encode([
            'resources/css/app.css' => [
                'file' => 'assets/app.css',
                'src' => 'resources/css/app.css',
                'isEntry' => true,
            ],
            'resources/js/app.js' => [
                'file' => 'assets/app.js',
                'src' => 'resources/js/app.js',
                'isEntry' => true,
            ],
        ], JSON_THROW_ON_ERROR));
    }
});

uses(RefreshDatabase::class);

test('sidebar contains direct links for dashboard documents and rag chat', function () {
    $response = $this->get('/dashboard');

    $response->assertRedirect(route('login'));

    $user = User::factory()->create();
    $this->actingAs($user);

    $authenticatedResponse = $this->get('/dashboard');

    $authenticatedResponse
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertSee('Documents')
        ->assertSee('RAG Chat');
});

test('dashboard displays real document and chat metrics', function () {
    $user = User::factory()->create();

    Document::query()->create([
        'user_id' => $user->id,
        'title' => 'Runbook',
        'file_path' => 'documents/runbook.md',
        'file_type' => 'md',
        'content' => 'Deployment runbook',
        'excerpt' => 'Deployment runbook',
        'status' => 'completed',
    ]);

    Document::query()->create([
        'user_id' => $user->id,
        'title' => 'Incident Notes',
        'file_path' => 'documents/incident.md',
        'file_type' => 'md',
        'content' => 'Incident notes',
        'excerpt' => 'Incident notes',
        'status' => 'pending',
    ]);

    ChatMessage::query()->create([
        'user_id' => $user->id,
        'role' => 'user',
        'content' => 'How do we deploy?',
        'sources' => null,
        'document_count' => 0,
    ]);

    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response
        ->assertOk()
        ->assertSee('Total Documents')
        ->assertSee('Processed')
        ->assertSee('Pending / Processing')
        ->assertSee('Chat Messages')
        ->assertSee('2')
        ->assertSee('1');
});

test('document uploader accepts multiple files and queues ingestion per file', function () {
    Queue::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    $firstUpload = UploadedFile::fake()->createWithContent('alpha.txt', 'Alpha file content');
    $secondUpload = UploadedFile::fake()->createWithContent('beta.txt', 'Beta file content');

    $parserMock = Mockery::mock(DocumentParserService::class);
    $parserMock->shouldReceive('parse')
        ->twice()
        ->andReturn(
            [
                'title' => 'alpha',
                'file_path' => 'documents/alpha.txt',
                'file_type' => 'txt',
                'content' => 'Alpha file content',
                'excerpt' => 'Alpha file content',
            ],
            [
                'title' => 'beta',
                'file_path' => 'documents/beta.txt',
                'file_type' => 'txt',
                'content' => 'Beta file content',
                'excerpt' => 'Beta file content',
            ]
        );

    $this->app->instance(DocumentParserService::class, $parserMock);

    $component = Livewire::test(DocumentUploader::class);

    $component
        ->set('upload', $firstUpload)
        ->assertSet('statusMessage', 'Queued 1 file(s). Click Upload to process them one by one.');

    $component
        ->set('upload', $secondUpload)
        ->assertSet('statusMessage', 'Queued 2 file(s). Click Upload to process them one by one.');

    $component
        ->call('processUpload')
        ->assertSet('isProcessing', false)
        ->assertSet('statusMessage', 'Upload finished. Queued: 2, failed: 0.');

    expect(Document::query()->where('user_id', $user->id)->count())->toBe(2);
});

test('rag chat history persists across refresh and is visible in ui', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $ragMock = Mockery::mock(RagService::class);
    $ragMock->shouldReceive('query')
        ->once()
        ->with('What is in my docs?')
        ->andReturn([
            'answer' => 'Your docs discuss deployment runbooks.',
            'sources' => collect([
                [
                    'title' => 'Runbook',
                    'excerpt' => 'Deployment instructions',
                ],
            ]),
            'document_count' => 1,
        ]);

    $this->app->instance(RagService::class, $ragMock);

    Livewire::test(RagChat::class)
        ->set('query', 'What is in my docs?')
        ->call('sendQuery')
        ->assertSee('What is in my docs?')
        ->assertSee('Your docs discuss deployment runbooks.');

    expect(ChatMessage::query()->where('user_id', $user->id)->count())->toBe(2);

    Livewire::test(RagChat::class)
        ->assertSee('What is in my docs?')
        ->assertSee('Your docs discuss deployment runbooks.');
});

afterEach(function (): void {
    Mockery::close();
});
