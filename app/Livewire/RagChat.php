<?php

namespace App\Livewire;

use App\Services\RagService;
use Livewire\Component;

class RagChat extends Component
{
    public string $query = '';

    public bool $isProcessing = false;

    public array $messages = [];

    public array $sources = [];

    public bool $showSources = false;

    public int $documentCount = 0;

    protected RagService $ragService;

    public function boot(RagService $ragService)
    {
        $this->ragService = $ragService;
    }

    public function render()
    {
        return view('livewire.rag-chat');
    }

    public function sendQuery(): void
    {
        if (empty(trim($this->query)) || $this->isProcessing) {
            return;
        }

        $userQuery = trim($this->query);
        $this->query = '';
        $this->isProcessing = true;

        // Add user message
        $this->messages[] = [
            'role' => 'user',
            'content' => $userQuery,
        ];

        try {
            $result = $this->ragService->query($userQuery);

            // Add assistant message
            $this->messages[] = [
                'role' => 'assistant',
                'content' => $result['answer'],
            ];

            // Store sources
            $this->sources = $result['sources']->toArray();
            $this->documentCount = $result['document_count'];
            $this->showSources = false;

        } catch (\Exception $e) {
            $this->messages[] = [
                'role' => 'assistant',
                'content' => 'Sorry, I encountered an error: '.$e->getMessage(),
            ];
        } finally {
            $this->isProcessing = false;
        }
    }

    public function toggleSources(): void
    {
        $this->showSources = ! $this->showSources;
    }

    public function clearChat(): void
    {
        $this->messages = [];
        $this->sources = [];
        $this->showSources = false;
        $this->documentCount = 0;
    }
}
