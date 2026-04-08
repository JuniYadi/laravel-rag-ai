<div class="flex flex-col gap-6">
    {{-- Upload Section --}}
    <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-6">
        <flux:heading size="lg">Upload Documents</flux:heading>
        <flux:subheading class="mt-1">Upload PDF, TXT, or Markdown files for RAG processing</flux:subheading>

        <div class="mt-6">
            <flux:field>
                <flux:label>Files</flux:label>
                <flux:input
                    type="file"
                    wire:model="uploads"
                    accept=".pdf,.txt,.md,.markdown"
                    :disabled="$isProcessing"
                    multiple
                />
                <flux:error name="uploads" />
                <flux:error name="uploads.*" />
            </flux:field>

            @if($isProcessing)
                <div class="mt-4 flex items-center gap-3">
                    <flux:icon icon="arrow-path" class="animate-spin size-5 text-blue-500" />
                    <span class="text-sm text-neutral-600 dark:text-neutral-400">{{ $statusMessage }}</span>
                </div>
            @endif

            @if($statusMessage && !$isProcessing)
                <div class="mt-4 text-sm text-green-600 dark:text-green-400">
                    {{ $statusMessage }}
                </div>
            @endif
        </div>
    </div>

    {{-- Documents List --}}
    <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-6">
        <flux:heading size="lg">Uploaded Documents</flux:heading>
        <flux:subheading class="mt-1">{{ count($uploadedDocuments) }} documents indexed</flux:subheading>

        <div class="mt-6 space-y-3">
            @forelse($uploadedDocuments as $doc)
                <div class="flex items-center justify-between rounded-lg border border-neutral-200 dark:border-neutral-700 p-4">
                    <div class="flex items-center gap-4">
                        <div class="flex size-10 items-center justify-center rounded-lg bg-neutral-100 dark:bg-neutral-700">
                            <flux:icon icon="document-text" class="size-5 text-neutral-600 dark:text-neutral-400" />
                        </div>
                        <div>
                            <p class="font-medium text-neutral-900 dark:text-neutral-100">{{ $doc['title'] }}</p>
                            <p class="text-sm text-neutral-500 dark:text-neutral-400">
                                {{ strtoupper($doc['file_type']) }} • {{ $doc['content_length'] }} chars • {{ $doc['created_at'] }}
                            </p>
                            <div class="mt-1 text-xs">
                                @if($doc['status'] === 'completed')
                                    <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-green-700 dark:bg-green-900/30 dark:text-green-300">Completed</span>
                                @elseif($doc['status'] === 'processing')
                                    <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">Processing</span>
                                @elseif($doc['status'] === 'pending')
                                    <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">Pending</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-red-700 dark:bg-red-900/30 dark:text-red-300">Failed</span>
                                @endif
                                @if(!empty($doc['error_message']))
                                    <p class="mt-1 text-red-600 dark:text-red-400">{{ $doc['error_message'] }}</p>
                                @endif
                            </div>
                        </div>
                    </div>
                    <flux:button
                        wire:click="confirmDelete({{ $doc['id'] }})"
                        variant="danger"
                        size="sm"
                        icon="trash"
                    >
                        Delete
                    </flux:button>
                </div>
            @empty
                <div class="py-12 text-center text-neutral-500 dark:text-neutral-400">
                    <flux:icon icon="folder-open" class="mx-auto size-12 text-neutral-400 dark:text-neutral-600" />
                    <p class="mt-4">No documents uploaded yet</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- Delete Confirmation Modal --}}
    <flux:modal wire:model="showDeleteModal">
        <flux:heading>Delete Document</flux:heading>
        <p class="mt-4 text-neutral-600 dark:text-neutral-400">
            Are you sure you want to delete "{{ $documentToDelete?->title }}"? This action cannot be undone.
        </p>
        <div class="mt-6 flex justify-end gap-3">
            <flux:button wire:click="cancelDelete" variant="outline">
                Cancel
            </flux:button>
            <flux:button wire:click="deleteDocument" variant="danger">
                Delete
            </flux:button>
        </div>
    </flux:modal>
</div>
