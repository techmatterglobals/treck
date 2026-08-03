<div class="space-y-6" wire:poll.30s>
    {{-- Polls every 30s so it stays current without Reverb; the echo-private
         listener adds instant updates when Reverb is configured. --}}
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

    {{-- Application usage (Phase 7) --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Application usage</h3>
        </div>

        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3 text-sm">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Current application</dt>
                <dd class="mt-1 font-medium">{{ $currentApp?->application_name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Current window title</dt>
                <dd class="mt-1 font-medium truncate">{{ $currentApp?->window_title ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Current app duration</dt>
                <dd class="mt-1 font-medium tabular-nums">
                    {{ $currentApp ? $this->duration((int) $currentApp->duration_seconds) : '—' }}
                </dd>
            </div>
        </dl>

        <div class="mt-5 grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- Recent app history --}}
            <div>
                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Recent applications</h4>
                <ul class="divide-y divide-gray-100 dark:divide-gray-800 text-sm">
                    @forelse ($recentApps as $app)
                        <li class="py-2 flex items-center justify-between" wire:key="cd-recent-{{ $app->id }}">
                            <span class="font-medium truncate">{{ $app->application_name }}</span>
                            <span class="text-gray-500 tabular-nums">
                                {{ $app->used_at?->format('H:i') }} · {{ $this->duration((int) $app->duration_seconds) }}
                            </span>
                        </li>
                    @empty
                        <li class="py-4 text-center text-gray-400">No application usage yet.</li>
                    @endforelse
                </ul>
            </div>

            {{-- Daily app summary --}}
            <div>
                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Today's top applications</h4>
                <ul class="divide-y divide-gray-100 dark:divide-gray-800 text-sm">
                    @forelse ($dailyApps as $app)
                        <li class="py-2 flex items-center justify-between" wire:key="cd-daily-{{ $loop->index }}">
                            <span class="font-medium truncate">{{ $app['application'] }}</span>
                            <span class="text-gray-500 tabular-nums">{{ $app['label'] }}</span>
                        </li>
                    @empty
                        <li class="py-4 text-center text-gray-400">No application usage today.</li>
                    @endforelse
                </ul>
            </div>
        </div>
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
