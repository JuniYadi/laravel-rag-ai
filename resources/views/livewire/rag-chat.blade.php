<div class="flex h-full flex-col">
    {{-- Chat Header --}}
    <div class="flex items-center justify-between border-b border-neutral-200 dark:border-neutral-700 px-6 py-4">
        <div>
            <flux:heading size="lg">RAG Chat</flux:heading>
            <flux:subheading class="mt-1">Ask questions about your documents</flux:subheading>
        </div>
        @if(count($messages) > 0)
            <flux:button wire:click="clearChat" variant="outline" size="sm" icon="trash">
                Clear Chat
            </flux:button>
        @endif
    </div>

    {{-- Messages --}}
    <div class="flex-1 overflow-y-auto p-6 space-y-4">
        @forelse($messages as $message)
            <div class="flex {{ $message['role'] === 'user' ? 'justify-end' : 'justify-start' }}">
                <div class="max-w-[80%] rounded-xl px-4 py-3 {{ $message['role'] === 'user'
                    ? 'bg-blue-600 text-white'
                    : 'bg-neutral-100 dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100'
                }}">
                    <p class="whitespace-pre-wrap">{{ $message['content'] }}</p>
                </div>
            </div>
        @empty
            <div class="flex h-full items-center justify-center">
                <div class="text-center text-neutral-500 dark:text-neutral-400">
                    <flux:icon icon="chat-bubble-left-right" class="mx-auto size-16 text-neutral-400 dark:text-neutral-600" />
                    <p class="mt-4 text-lg">Start a conversation</p>
                    <p class="mt-1">Ask questions about your uploaded documents</p>
                </div>
            </div>
        @endforelse

        @if($isProcessing)
            <div class="flex justify-start">
                <div class="rounded-xl bg-neutral-100 dark:bg-neutral-800 px-4 py-3">
                    <div class="flex items-center gap-2">
                        <flux:icon icon="arrow-path" class="size-4 animate-spin text-blue-500" />
                        <span class="text-sm text-neutral-500 dark:text-neutral-400">Thinking...</span>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- Sources Panel --}}
    @if(count($sources) > 0)
        <div class="border-t border-neutral-200 dark:border-neutral-700">
            <button
                wire:click="toggleSources"
                class="flex w-full items-center justify-between px-6 py-3 text-left hover:bg-neutral-50 dark:hover:bg-neutral-800"
            >
                <div class="flex items-center gap-2">
                    <flux:icon icon="document-text" class="size-4 text-neutral-500" />
                    <span class="text-sm font-medium">
                        {{ count($sources) }} source{{ count($sources) > 1 ? 's' : '' }} used
                    </span>
                </div>
                <flux:icon
                    :name="$showSources ? 'chevron-up' : 'chevron-down'"
                    class="size-4 text-neutral-500"
                />
            </button>

            @if($showSources)
                <div class="px-6 pb-4 space-y-2">
                    @foreach($sources as $source)
                        <div class="rounded-lg bg-neutral-50 dark:bg-neutral-800 p-3 text-sm">
                            <p class="font-medium text-neutral-900 dark:text-neutral-100">{{ $source['title'] }}</p>
                            <p class="mt-1 text-neutral-600 dark:text-neutral-400 line-clamp-2">{{ $source['excerpt'] }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- Input --}}
    <div class="border-t border-neutral-200 dark:border-neutral-700 p-4">
        <form wire:submit.prevent="sendQuery" class="flex gap-3">
            <flux:input
                wire:model.live.debounce.250ms="query"
                placeholder="Ask a question about your documents..."
                :disabled="$isProcessing"
                class="flex-1"
            />
            <flux:button
                type="submit"
                variant="primary"
                :disabled="$isProcessing || blank(trim($query))"
                icon="paper-airplane"
            >
                Send
            </flux:button>
        </form>
    </div>
</div>
