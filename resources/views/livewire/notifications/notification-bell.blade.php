<div x-data="{ open: false }" class="relative">
    <button type="button" @click="open = !open"
        class="relative inline-flex items-center text-gray-500 hover:text-gray-800 dark:hover:text-gray-200">
        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
        </svg>
        @if ($unreadCount > 0)
            <span class="absolute -top-1 -right-1 inline-flex items-center justify-center rounded-full bg-red-600 px-1.5 py-0.5 text-[10px] font-semibold text-white tabular-nums">
                {{ $unreadCount > 99 ? '99+' : $unreadCount }}
            </span>
        @endif
    </button>

    <div x-show="open" x-cloak @click.outside="open = false"
        class="absolute right-0 mt-2 w-80 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-lg z-50">
        <div class="flex items-center justify-between px-4 py-2 border-b border-gray-100 dark:border-gray-700">
            <span class="text-sm font-medium text-gray-900 dark:text-gray-100">Notifications</span>
            <button type="button" wire:click="markAllRead" class="text-xs text-indigo-600 hover:underline">Mark all read</button>
        </div>
        <ul class="max-h-96 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800 text-sm">
            @forelse ($recent as $n)
                <li class="px-4 py-2 {{ $n->read_at ? '' : 'bg-indigo-50/50 dark:bg-indigo-900/10' }}" wire:key="bell-{{ $n->id }}">
                    <div class="flex items-center gap-2">
                        <x-severity-badge :severity="$n->severity_enum" />
                        <span class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $n->title }}</span>
                    </div>
                    <p class="mt-0.5 text-gray-500 truncate">{{ $n->message }}</p>
                    <p class="mt-0.5 text-[11px] text-gray-400">{{ $n->created_at?->diffForHumans() }}</p>
                </li>
            @empty
                <li class="px-4 py-6 text-center text-gray-400">No notifications.</li>
            @endforelse
        </ul>
        <a href="{{ route('notifications.index') }}" class="block px-4 py-2 text-center text-xs text-indigo-600 hover:underline border-t border-gray-100 dark:border-gray-700">
            View all
        </a>
    </div>
</div>
