<x-layouts::app :title="__('Dashboard')">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        {{-- Quick Actions --}}
        <div class="grid auto-rows-min gap-4 md:grid-cols-2 lg:grid-cols-3">
            {{-- Documents Card --}}
            <a href="{{ route('documents') }}" class="group relative overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-6 transition-colors hover:border-blue-500 dark:hover:border-blue-500">
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

            {{-- RAG Chat Card --}}
            <a href="{{ route('rag.chat') }}" class="group relative overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-6 transition-colors hover:border-purple-500 dark:hover:border-purple-500">
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

            {{-- Placeholder Card --}}
            <div class="relative aspect-video overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700 md:aspect-auto">
                <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
            </div>
        </div>

        {{-- Main Content Area --}}
        <div class="relative h-full flex-1 overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
        </div>
    </div>
</x-layouts::app>
