<?php

namespace App\Livewire;

use App\Jobs\ProcessDocumentIngestion;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentParserService;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithFileUploads;

class DocumentUploader extends Component
{
    use WithFileUploads;

    public $upload = null;

    public array $uploads = [];

    public bool $isProcessing = false;

    public string $statusMessage = '';

    public array $uploadedDocuments = [];

    public bool $showDeleteModal = false;

    public ?Document $documentToDelete = null;

    protected DocumentParserService $parserService;

    public function boot(DocumentParserService $parserService)
    {
        $this->parserService = $parserService;
    }

    public function mount()
    {
        $this->loadDocuments();
    }

    public function render()
    {
        return view('livewire.document-uploader');
    }

    public function updatedUpload(): void
    {
        if ($this->upload) {
            $this->uploads = [$this->upload];
            $this->processUpload(isMultiFile: false);
        }
    }

    public function updatedUploads()
    {
        $this->processUpload(isMultiFile: true);
    }

    public function processUpload(bool $isMultiFile = true): void
    {
        $this->validate([
            'uploads' => 'required|array|min:1',
            'uploads.*' => 'required|file|max:10240|mimes:pdf,txt,md,markdown',
        ]);

        $this->isProcessing = true;

        $queued = 0;
        $failed = 0;

        try {
            foreach ($this->uploads as $upload) {
                try {
                    $parsed = $this->parserService->parse($upload);

                    $document = Document::create([
                        'user_id' => $this->currentUserId(),
                        'title' => $parsed['title'],
                        'file_path' => $parsed['file_path'],
                        'file_type' => $parsed['file_type'],
                        'content' => $parsed['content'],
                        'excerpt' => $parsed['excerpt'],
                        'status' => 'pending',
                        'error_message' => null,
                    ]);

                    ProcessDocumentIngestion::dispatch($document->id);
                    $queued++;
                } catch (\Throwable) {
                    $failed++;
                }
            }

            $this->statusMessage = $isMultiFile
                ? "Upload finished. Queued: {$queued}, failed: {$failed}."
                : 'Upload accepted. Document is queued for processing.';

            $this->upload = null;
            $this->uploads = [];
            $this->loadDocuments();
        } finally {
            $this->isProcessing = false;
        }
    }

    public function loadDocuments(): void
    {
        $this->uploadedDocuments = Document::query()
            ->where('user_id', $this->currentUserId())
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
                'status' => $doc->status,
                'error_message' => $doc->error_message,
            ])
            ->toArray();
    }

    public function confirmDelete(Document $document): void
    {
        $this->authorizeDocumentOwnership($document);

        $this->documentToDelete = $document;
        $this->showDeleteModal = true;
    }

    public function deleteDocument(): void
    {
        if ($this->documentToDelete) {
            $this->authorizeDocumentOwnership($this->documentToDelete);

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

    protected function currentUserId(): int
    {
        return $this->currentUser()->id;
    }

    protected function currentUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    protected function authorizeDocumentOwnership(Document $document): void
    {
        Gate::authorize('delete', $document);
    }
}
