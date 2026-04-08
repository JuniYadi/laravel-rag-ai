<x-layouts::app :title="__('Dashboard')">
    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
        {{-- Quick Actions --}}
        <div class="grid auto-rows-min gap-4 md:grid-cols-2 lg:grid-cols-3">
            <a href="{{ route('documents') }}" class="group relative overflow-hidden rounded-xl border border-neutral-200 bg-white p-6 transition-colors hover:border-blue-500 dark:border-neutral-700 dark:bg-neutral-800 dark:hover:border-blue-500">
                <div class="flex items-center gap-4">
                    <div class="flex size-12 items-center justify-center rounded-xl bg-blue-100 dark:bg-blue-900/30">
                        <flux:icon icon="folder-open" class="size-6 text-blue-600 dark:text-blue-400" />
                    </div>
                    <div>
                        <flux:heading size="md">Documents</flux:heading>
                        <flux:subheading>Upload & manage files</flux:subheading>
                    </div>
                </div>
                <flux:icon icon="arrow-right" class="absolute bottom-4 right-4 size-5 text-neutral-400 transition-transform group-hover:translate-x-1" />
            </a>

            <a href="{{ route('rag.chat') }}" class="group relative overflow-hidden rounded-xl border border-neutral-200 bg-white p-6 transition-colors hover:border-purple-500 dark:border-neutral-700 dark:bg-neutral-800 dark:hover:border-purple-500">
                <div class="flex items-center gap-4">
                    <div class="flex size-12 items-center justify-center rounded-xl bg-purple-100 dark:bg-purple-900/30">
                        <flux:icon icon="chat-bubble-left-right" class="size-6 text-purple-600 dark:text-purple-400" />
                    </div>
                    <div>
                        <flux:heading size="md">RAG Chat</flux:heading>
                        <flux:subheading>Ask questions</flux:subheading>
                    </div>
                </div>
                <flux:icon icon="arrow-right" class="absolute bottom-4 right-4 size-5 text-neutral-400 transition-transform group-hover:translate-x-1" />
            </a>

            <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-800">
                <flux:heading size="md">Realtime Summary</flux:heading>
                <flux:subheading class="mt-1">Latest ingestion + chat metrics</flux:subheading>
                <div class="mt-3 text-sm text-neutral-600 dark:text-neutral-400">
                    {{ $metrics['total_documents'] }} documents • {{ $metrics['chat_messages'] }} chat messages
                </div>
            </div>
        </div>

        {{-- Metrics --}}
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-800">
                <flux:subheading>Total Documents</flux:subheading>
                <flux:heading class="mt-2" size="lg">{{ $metrics['total_documents'] }}</flux:heading>
            </div>
            <div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-800">
                <flux:subheading>Processed</flux:subheading>
                <flux:heading class="mt-2" size="lg">{{ $metrics['documents_completed'] }}</flux:heading>
            </div>
            <div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-800">
                <flux:subheading>Pending / Processing</flux:subheading>
                <flux:heading class="mt-2" size="lg">{{ $metrics['documents_pending'] }}</flux:heading>
            </div>
            <div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-800">
                <flux:subheading>Failed</flux:subheading>
                <flux:heading class="mt-2" size="lg">{{ $metrics['documents_failed'] }}</flux:heading>
            </div>
            <div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-800">
                <flux:subheading>Chat Messages</flux:subheading>
                <flux:heading class="mt-2" size="lg">{{ $metrics['chat_messages'] }}</flux:heading>
            </div>
        </div>

        {{-- Recent Activity --}}
        <div class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-800">
                <flux:heading size="md">Recent Uploads</flux:heading>
                <div class="mt-4 space-y-2">
                    @forelse($recentUploads as $upload)
                        <div class="rounded-lg border border-neutral-200 p-3 text-sm dark:border-neutral-700">
                            <div class="font-medium text-neutral-900 dark:text-neutral-100">{{ $upload->title }}</div>
                            <div class="text-neutral-500 dark:text-neutral-400">{{ ucfirst($upload->status) }} • {{ $upload->created_at->diffForHumans() }}</div>
                        </div>
                    @empty
                        <div class="text-sm text-neutral-500 dark:text-neutral-400">No uploads yet.</div>
                    @endforelse
                </div>
            </div>

            <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-800">
                <flux:heading size="md">Recent Chats</flux:heading>
                <div class="mt-4 space-y-2">
                    @forelse($recentChats as $chat)
                        <div class="rounded-lg border border-neutral-200 p-3 text-sm dark:border-neutral-700">
                            <div class="font-medium text-neutral-900 dark:text-neutral-100">{{ ucfirst($chat->role) }}</div>
                            <div class="text-neutral-500 dark:text-neutral-400">{{ \Illuminate\Support\Str::limit($chat->content, 100) }}</div>
                            <div class="mt-1 text-xs text-neutral-400">{{ $chat->created_at->diffForHumans() }}</div>
                        </div>
                    @empty
                        <div class="text-sm text-neutral-500 dark:text-neutral-400">No chats yet.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-layouts::app>
