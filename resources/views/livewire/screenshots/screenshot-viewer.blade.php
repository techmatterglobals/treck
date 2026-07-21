<div class="space-y-6" x-data="{ zoom: false }">
    {{-- Toolbar --}}
    <div class="flex items-center justify-between">
        <a href="{{ route('screenshots.index') }}" class="text-sm text-indigo-600 hover:underline">&larr; Back to screenshots</a>
        <div class="flex items-center gap-2">
            @if ($prevId)
                <a href="{{ route('screenshots.show', $prevId) }}"
                    class="rounded-md border border-gray-300 dark:border-gray-600 px-3 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-gray-700">&larr; Previous</a>
            @else
                <span class="rounded-md border border-gray-200 dark:border-gray-700 px-3 py-1.5 text-sm text-gray-300 dark:text-gray-600">&larr; Previous</span>
            @endif
            @if ($nextId)
                <a href="{{ route('screenshots.show', $nextId) }}"
                    class="rounded-md border border-gray-300 dark:border-gray-600 px-3 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-gray-700">Next &rarr;</a>
            @else
                <span class="rounded-md border border-gray-200 dark:border-gray-700 px-3 py-1.5 text-sm text-gray-300 dark:text-gray-600">Next &rarr;</span>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Preview --}}
        <div class="lg:col-span-2 bg-white dark:bg-gray-800 shadow rounded-lg p-3">
            <img src="{{ $imageUrl }}" alt="Screenshot {{ $screenshot->id }}"
                @click="zoom = true"
                class="w-full rounded cursor-zoom-in">

            {{-- Full-screen / zoom overlay --}}
            <div x-show="zoom" x-cloak @click="zoom = false" @keydown.escape.window="zoom = false"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/90 p-4 cursor-zoom-out">
                <img src="{{ $imageUrl }}" alt="Screenshot {{ $screenshot->id }} (full screen)"
                    class="max-h-full max-w-full object-contain">
            </div>
        </div>

        {{-- Metadata --}}
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Capture details</h3>
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Captured</dt>
                    <dd class="font-medium">{{ $screenshot->captured_at?->format('M j, Y H:i:s') }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Employee</dt>
                    <dd class="font-medium">{{ $screenshot->employee?->name ?? 'Unassigned' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Computer</dt>
                    <dd class="font-medium">{{ $screenshot->computer?->hostname ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Active application</dt>
                    <dd class="font-medium">{{ $screenshot->active_process ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Window title</dt>
                    <dd class="font-medium break-words">{{ $screenshot->active_window_title ?: '—' }}</dd>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">Resolution</dt>
                        <dd class="font-medium tabular-nums">{{ $screenshot->resolution }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">Monitor</dt>
                        <dd class="font-medium tabular-nums">#{{ $screenshot->monitor_number }}</dd>
                    </div>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">File size</dt>
                    <dd class="font-medium tabular-nums">{{ number_format($screenshot->file_size / 1024, 1) }} KB</dd>
                </div>
                @if ($screenshot->collection_mode)
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">Collected via</dt>
                        <dd class="font-medium">
                            {{ $screenshot->collection_mode }}
                            <span class="text-gray-500">
                                (session {{ $screenshot->source_session_id ?? '—' }}{{ $screenshot->source_user ? ', '.$screenshot->source_user : '' }})
                            </span>
                        </dd>
                    </div>
                @endif
            </dl>

            <a href="{{ route('screenshots.download', $screenshot) }}"
                class="mt-5 inline-flex w-full items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                Download
            </a>
        </div>
    </div>
</div>
