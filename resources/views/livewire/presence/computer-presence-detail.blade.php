<div class="space-y-6">
    {{-- Current presence --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                    {{ $computer->hostname ?? 'Computer #'.$computer->id }}
                </h3>
                <p class="text-sm text-gray-500">
                    {{ $computer->employee?->name ?? 'Unassigned' }}
                    @if ($computer->employee?->department)
                        · {{ $computer->employee->department->name }}
                    @endif
                </p>
            </div>
            <x-presence-badge :status="$status" class="text-sm" />
        </div>

        <dl class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 text-sm">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Current session</dt>
                <dd class="mt-1 font-medium tabular-nums">
                    {{ $sessionSeconds !== null ? $this->duration($sessionSeconds) : '—' }}
                </dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Idle duration</dt>
                <dd class="mt-1 font-medium tabular-nums">
                    {{ $this->duration((int) ($presence?->idle_seconds ?? 0)) }}
                </dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Last heartbeat</dt>
                <dd class="mt-1 font-medium">{{ $presence?->last_heartbeat_at?->diffForHumans() ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Last sync</dt>
                <dd class="mt-1 font-medium">{{ $presence?->last_synced_at?->diffForHumans() ?? '—' }}</dd>
            </div>
        </dl>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- Recent session events --}}
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-3">Recent session events</h3>
            <ul class="divide-y divide-gray-100 dark:divide-gray-800 text-sm">
                @forelse ($recentSessions as $event)
                    <li class="py-2 flex items-center justify-between">
                        <span class="font-medium">{{ data_get($event->payload, 'Type', data_get($event->payload, 'type', 'session')) }}</span>
                        <span class="text-gray-500">{{ $event->occurred_at?->diffForHumans() }}</span>
                    </li>
                @empty
                    <li class="py-4 text-center text-gray-400">No session events yet.</li>
                @endforelse
            </ul>
        </div>

        {{-- Recent heartbeats --}}
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-3">Recent heartbeats</h3>
            <ul class="divide-y divide-gray-100 dark:divide-gray-800 text-sm">
                @forelse ($recentHeartbeats as $event)
                    @php $idle = data_get($event->payload, 'IsIdle', data_get($event->payload, 'is_idle', false)); @endphp
                    <li class="py-2 flex items-center justify-between">
                        <span class="font-medium {{ $idle ? 'text-yellow-600' : 'text-green-600' }}">
                            {{ $idle ? 'Idle' : 'Active' }}
                        </span>
                        <span class="text-gray-500">{{ $event->occurred_at?->diffForHumans() }}</span>
                    </li>
                @empty
                    <li class="py-4 text-center text-gray-400">No heartbeats yet.</li>
                @endforelse
            </ul>
        </div>
    </div>

    <div>
        <a href="{{ route('presence.index') }}" class="text-sm text-indigo-600 hover:underline">&larr; Back to presence board</a>
    </div>
</div>
