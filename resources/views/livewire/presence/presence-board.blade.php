<div class="space-y-6">
    {{-- Summary cards --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-7">
        @php
            $cards = [
                ['label' => 'Total',       'value' => $summary['total'],       'dot' => 'bg-gray-400'],
                ['label' => 'Online',      'value' => $summary['online'],      'dot' => 'bg-green-500'],
                ['label' => 'Offline',     'value' => $summary['offline'],     'dot' => 'bg-gray-500'],
                ['label' => 'Active',      'value' => $summary['active'],      'dot' => 'bg-green-500'],
                ['label' => 'Idle',        'value' => $summary['idle'],        'dot' => 'bg-yellow-500'],
                ['label' => 'Locked',      'value' => $summary['locked'],      'dot' => 'bg-blue-500'],
                ['label' => 'Logged Out',  'value' => $summary['logged_out'],  'dot' => 'bg-red-500'],
            ];
        @endphp
        @foreach ($cards as $card)
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
                <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                    <span class="inline-block h-2.5 w-2.5 rounded-full {{ $card['dot'] }}"></span>
                    {{ $card['label'] }}
                </div>
                <div class="mt-2 text-2xl font-semibold text-gray-900 dark:text-gray-100 tabular-nums">
                    {{ number_format($card['value']) }}
                </div>
            </div>
        @endforeach
    </div>

    {{-- Per-computer table --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Computers</h3>
            <span class="text-xs text-gray-400" wire:loading>updating…</span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="px-3 py-2">Computer</th>
                        <th class="px-3 py-2">Employee</th>
                        <th class="px-3 py-2">Department</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2">Last heartbeat</th>
                        <th class="px-3 py-2">Idle time</th>
                        <th class="px-3 py-2">Last activity</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($rows as $row)
                        <tr class="text-gray-900 dark:text-gray-100" wire:key="presence-{{ $row['computer_id'] }}">
                            <td class="px-3 py-2 font-medium">
                                <a href="{{ route('presence.show', $row['computer_id']) }}" class="text-indigo-600 hover:underline">
                                    {{ $row['computer_name'] ?? 'Computer #'.$row['computer_id'] }}
                                </a>
                            </td>
                            <td class="px-3 py-2">{{ $row['employee'] ?? '—' }}</td>
                            <td class="px-3 py-2">{{ $row['department'] ?? '—' }}</td>
                            <td class="px-3 py-2">
                                <x-presence-badge :status="$row['status']" />
                            </td>
                            <td class="px-3 py-2 text-gray-500">
                                {{ $row['last_heartbeat_at']?->diffForHumans() ?? '—' }}
                            </td>
                            <td class="px-3 py-2 tabular-nums text-gray-500">{{ $row['idle_label'] }}</td>
                            <td class="px-3 py-2 text-gray-500">
                                {{ $row['last_activity_at']?->diffForHumans() ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-6 text-center text-gray-400">No computers registered yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
