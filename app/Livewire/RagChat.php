<?php

namespace App\Livewire;

use App\Models\ChatMessage;
use App\Models\User;
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

    public function mount(): void
    {
        $this->loadMessages();
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

        $userMessage = ChatMessage::query()->create([
            'user_id' => $this->currentUser()->id,
            'role' => 'user',
            'content' => $userQuery,
            'sources' => null,
            'document_count' => 0,
        ]);

        $this->messages[] = [
            'id' => $userMessage->id,
            'role' => 'user',
            'content' => $userQuery,
        ];

        try {
            $result = $this->ragService->query($userQuery);

            $assistantMessage = ChatMessage::query()->create([
                'user_id' => $this->currentUser()->id,
                'role' => 'assistant',
                'content' => $result['answer'],
                'sources' => $result['sources']->toArray(),
                'document_count' => (int) $result['document_count'],
            ]);

            $this->messages[] = [
                'id' => $assistantMessage->id,
                'role' => 'assistant',
                'content' => $result['answer'],
            ];

            $this->sources = $result['sources']->toArray();
            $this->documentCount = (int) $result['document_count'];
            $this->showSources = false;
        } catch (\Exception $e) {
            $errorMessage = 'Sorry, I encountered an error while answering your query. Please retry in a moment. Operator hint: '.$e->getMessage();

            $assistantMessage = ChatMessage::query()->create([
                'user_id' => $this->currentUser()->id,
                'role' => 'assistant',
                'content' => $errorMessage,
                'sources' => null,
                'document_count' => 0,
            ]);

            $this->messages[] = [
                'id' => $assistantMessage->id,
                'role' => 'assistant',
                'content' => $errorMessage,
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
        ChatMessage::query()
            ->where('user_id', $this->currentUser()->id)
            ->delete();

        $this->messages = [];
        $this->sources = [];
        $this->showSources = false;
        $this->documentCount = 0;
    }

    protected function loadMessages(): void
    {
        $storedMessages = ChatMessage::query()
            ->where('user_id', $this->currentUser()->id)
            ->orderBy('created_at')
            ->get();

        $this->messages = $storedMessages
            ->map(fn (ChatMessage $message) => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->toArray();

        $latestAssistantMessage = $storedMessages
            ->where('role', 'assistant')
            ->last();

        $this->sources = $latestAssistantMessage?->sources ?? [];
        $this->documentCount = (int) ($latestAssistantMessage?->document_count ?? 0);
        $this->showSources = false;
    }

    protected function currentUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
