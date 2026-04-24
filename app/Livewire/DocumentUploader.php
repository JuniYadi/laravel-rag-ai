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

    public ?object $upload = null;

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
            $this->validate([
                'upload' => 'required|file|max:10240|mimes:pdf,txt,md,markdown',
            ]);

            $this->uploads[] = $this->upload;
            $this->upload = null;

            $this->statusMessage = sprintf('Queued %d file(s). Click Upload to process them one by one.', count($this->uploads));
        }
    }

    public function processUpload(): void
    {
        if ($this->isProcessing || empty($this->uploads)) {
            return;
        }

        $this->validate([
            'uploads' => 'required|array|min:1|max:10',
            'uploads.*' => 'required|file|max:10240|mimes:pdf,txt,md,markdown',
        ]);

        $this->isProcessing = true;
        $this->statusMessage = 'Parsing document(s)...';

        $queued = 0;
        $failed = 0;
        $errors = [];

        $totalUploads = count($this->uploads);

        try {
            while (! empty($this->uploads)) {
                $queued++;

                /** @var object $upload */
                $upload = array_shift($this->uploads);

                $this->statusMessage = sprintf(
                    'Parsing file %s (%d of %d)...',
                    $upload->getClientOriginalName(),
                    $queued,
                    $totalUploads
                );

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
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = $upload->getClientOriginalName().': '.$e->getMessage();
                }
            }

            $msg = "Upload finished. Queued: {$totalUploads}, failed: {$failed}.";
            if ($errors !== []) {
                $msg .= ' Errors: '.implode(' | ', $errors);
            }

            $this->statusMessage = $msg;

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
