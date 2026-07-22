<div class="space-y-6">
    {{-- Stats --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @php
            $cards = [
                ['label' => 'Total', 'value' => number_format($stats['total'])],
                ['label' => 'Unread', 'value' => number_format($stats['unread'])],
                ['label' => 'Critical', 'value' => number_format($stats['critical'])],
                ['label' => 'Range', 'value' => \Illuminate\Support\Carbon::parse($from)->format('M j').' – '.\Illuminate\Support\Carbon::parse($to)->format('M j')],
            ];
        @endphp
        @foreach ($cards as $card)
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $card['label'] }}</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900 dark:text-gray-100 tabular-nums">{{ $card['value'] }}</div>
            </div>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Severity</label>
                <select wire:model.live="severity" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <option value="">All severities</option>
                    @foreach ($severities as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Status</label>
                <select wire:model.live="status" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <option value="">All</option>
                    <option value="unread">Unread</option>
                    <option value="read">Read</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">From</label>
                <input type="date" wire:model.live="from" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">To</label>
                <input type="date" wire:model.live="to" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Search</label>
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="Title / message"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
        </div>
        <div class="mt-4 flex items-center justify-between">
            <span class="text-xs text-gray-400" wire:loading>updating…</span>
            <button type="button" wire:click="markAllRead" class="text-sm text-indigo-600 hover:underline">Mark all read</button>
        </div>
    </div>

    {{-- List --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <ul class="divide-y divide-gray-100 dark:divide-gray-800 text-sm">
            @forelse ($notifications as $n)
                <li class="py-3 flex items-start justify-between gap-4 {{ $n->read_at ? '' : 'bg-indigo-50/40 dark:bg-indigo-900/10 -mx-5 px-5' }}" wire:key="n-{{ $n->id }}">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <x-severity-badge :severity="$n->severity_enum" />
                            <span class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $n->title }}</span>
                        </div>
                        <p class="mt-1 text-gray-600 dark:text-gray-300">{{ $n->message }}</p>
                        <p class="mt-1 text-xs text-gray-400">
                            {{ $n->created_at?->format('M j, H:i') }}
                            @if ($n->computer) · {{ $n->computer->hostname }} @endif
                        </p>
                    </div>
                    @unless ($n->read_at)
                        <button type="button" wire:click="markRead({{ $n->id }})" class="shrink-0 text-xs text-indigo-600 hover:underline">Mark read</button>
                    @endunless
                </li>
            @empty
                <li class="py-10 text-center text-gray-400">No notifications match these filters.</li>
            @endforelse
        </ul>

        <div class="mt-4">{{ $notifications->links() }}</div>
    </div>
</div>
