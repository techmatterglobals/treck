<div wire:poll.30s class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Employee status</h3>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
            <thead>
                <tr class="text-left text-gray-500 dark:text-gray-400">
                    <th class="px-3 py-2">Employee</th>
                    <th class="px-3 py-2">Department</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Active time</th>
                    <th class="px-3 py-2">Idle time</th>
                    <th class="px-3 py-2">Last activity</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($rows as $row)
                    @php
                        $status = $row['status'];
                        $badge = match ($status->color()) {
                            'green' => 'bg-green-100 text-green-800',
                            'amber' => 'bg-amber-100 text-amber-800',
                            'slate' => 'bg-slate-100 text-slate-700',
                            default => 'bg-red-100 text-red-800',
                        };
                    @endphp
                    <tr class="text-gray-900 dark:text-gray-100">
                        <td class="px-3 py-2">{{ $row['name'] }}</td>
                        <td class="px-3 py-2">{{ $row['department'] ?? '—' }}</td>
                        <td class="px-3 py-2">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs {{ $badge }}">
                                {{ $status->label() }}
                            </span>
                        </td>
                        <td class="px-3 py-2 tabular-nums">{{ $row['active_label'] }}</td>
                        <td class="px-3 py-2 tabular-nums text-gray-500">{{ $row['idle_label'] }}</td>
                        <td class="px-3 py-2 text-gray-500">
                            {{ $row['last_activity_at']?->diffForHumans() ?? '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-3 py-6 text-center text-gray-500">No employees yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
