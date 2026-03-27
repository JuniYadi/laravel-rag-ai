<?php

namespace App\Livewire;

use App\Models\Document;
use App\Services\DocumentParserService;
use App\Services\EmbeddingService;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithFileUploads;

class DocumentUploader extends Component
{
    use WithFileUploads;

    public $upload;

    public bool $isProcessing = false;

    public string $statusMessage = '';

    public array $uploadedDocuments = [];

    public bool $showDeleteModal = false;

    public ?Document $documentToDelete = null;

    protected DocumentParserService $parserService;

    protected EmbeddingService $embeddingService;

    public function boot(
        DocumentParserService $parserService,
        EmbeddingService $embeddingService
    ) {
        $this->parserService = $parserService;
        $this->embeddingService = $embeddingService;
    }

    public function mount()
    {
        $this->loadDocuments();
    }

    public function render()
    {
        return view('livewire.document-uploader');
    }

    public function updatedUpload()
    {
        $this->processUpload();
    }

    public function processUpload(): void
    {
        $this->validate([
            'upload' => 'required|file|max:10240|mimes:pdf,txt,md,markdown',
        ]);

        $this->isProcessing = true;
        $this->statusMessage = 'Parsing document...';

        try {
            $parsed = $this->parserService->parse($this->upload);

            $this->statusMessage = 'Generating embedding...';

            $embedding = $this->embeddingService->generateEmbedding($parsed['content']);

            DB::transaction(function () use ($parsed, $embedding) {
                Document::create([
                    'title' => $parsed['title'],
                    'file_path' => $parsed['file_path'],
                    'file_type' => $parsed['file_type'],
                    'content' => $parsed['content'],
                    'excerpt' => $parsed['excerpt'],
                    'embedding' => $embedding,
                ]);
            });

            $this->statusMessage = 'Document uploaded successfully!';
            $this->upload = null;
            $this->loadDocuments();

        } catch (\Exception $e) {
            $this->statusMessage = 'Error: '.$e->getMessage();
        } finally {
            $this->isProcessing = false;
        }
    }

    public function loadDocuments(): void
    {
        $this->uploadedDocuments = Document::query()
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($doc) => [
                'id' => $doc->id,
                'title' => $doc->title,
                'file_type' => $doc->file_type,
                'excerpt' => $doc->excerpt,
                'created_at' => $doc->created_at->diffForHumans(),
                'content_length' => mb_strlen($doc->content),
            ])
            ->toArray();
    }

    public function confirmDelete(Document $document): void
    {
        $this->documentToDelete = $document;
        $this->showDeleteModal = true;
    }

    public function deleteDocument(): void
    {
        if ($this->documentToDelete) {
            $this->documentToDelete->delete();
            $this->documentToDelete = null;
            $this->showDeleteModal = false;
            $this->loadDocuments();
        }
    }

    public function cancelDelete(): void
    {
        $this->documentToDelete = null;
        $this->showDeleteModal = false;
    }
}
