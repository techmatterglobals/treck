<div class="space-y-6">
    {{-- Filters --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-6">
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">From</label>
                <input type="date" wire:model.live="from"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">To</label>
                <input type="date" wire:model.live="to"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Employee</label>
                <select wire:model.live="employeeId"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <option value="">All employees</option>
                    @foreach ($employees as $employee)
                        <option value="{{ $employee->id }}">{{ $employee->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Computer</label>
                <select wire:model.live="computerId"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <option value="">All computers</option>
                    @foreach ($computers as $computer)
                        <option value="{{ $computer->id }}">{{ $computer->hostname ?? 'Computer #'.$computer->id }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Department</label>
                <select wire:model.live="departmentId"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <option value="">All departments</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}">{{ $department->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Application</label>
                <input type="search" wire:model.live.debounce.400ms="application" placeholder="Search app / title"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
        </div>
        <div class="mt-4 flex items-center justify-between">
            <span class="text-xs text-gray-400" wire:loading>updating…</span>
            <button type="button" wire:click="resetFilters"
                class="text-sm text-indigo-600 hover:underline">Reset filters</button>
        </div>
    </div>

    {{-- Summary cards --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @php
            $cards = [
                ['label' => 'Total usage time', 'value' => $summary['total_label']],
                ['label' => 'Sessions',         'value' => number_format($summary['sessions'])],
                ['label' => 'Applications',      'value' => number_format($summary['applications'])],
                ['label' => 'Range',             'value' => \Illuminate\Support\Carbon::parse($from)->format('M j').' – '.\Illuminate\Support\Carbon::parse($to)->format('M j')],
            ];
        @endphp
        @foreach ($cards as $card)
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $card['label'] }}</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900 dark:text-gray-100 tabular-nums">
                    {{ $card['value'] }}
                </div>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- Top applications (time per application) --}}
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-3">Top applications</h3>
            @php $topMax = $topApplications->max('seconds') ?: 1; @endphp
            <ul class="space-y-3 text-sm">
                @forelse ($topApplications as $app)
                    <li wire:key="top-{{ $loop->index }}">
                        <div class="flex items-center justify-between">
                            <span class="font-medium truncate">{{ $app['application'] }}</span>
                            <span class="text-gray-500 tabular-nums">{{ $app['label'] }} · {{ $app['sessions'] }}×</span>
                        </div>
                        <div class="mt-1 h-1.5 w-full rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="h-1.5 rounded-full bg-indigo-500" style="width: {{ round($app['seconds'] / $topMax * 100) }}%"></div>
                        </div>
                    </li>
                @empty
                    <li class="py-4 text-center text-gray-400">No usage in this range.</li>
                @endforelse
            </ul>
        </div>

        {{-- Daily timeline --}}
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-3">Daily timeline</h3>
            @php $dayMax = $dailyUsage->max('seconds') ?: 1; @endphp
            <ul class="space-y-3 text-sm">
                @forelse ($dailyUsage as $day)
                    <li wire:key="day-{{ $day['day'] }}">
                        <div class="flex items-center justify-between">
                            <span class="font-medium">{{ \Illuminate\Support\Carbon::parse($day['day'])->format('D, M j') }}</span>
                            <span class="text-gray-500 tabular-nums">{{ $day['label'] }}</span>
                        </div>
                        <div class="mt-1 h-1.5 w-full rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="h-1.5 rounded-full bg-green-500" style="width: {{ round($day['seconds'] / $dayMax * 100) }}%"></div>
                        </div>
                    </li>
                @empty
                    <li class="py-4 text-center text-gray-400">No usage in this range.</li>
                @endforelse
            </ul>
        </div>

        {{-- Usage by employee --}}
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-3">Usage by employee</h3>
            <ul class="divide-y divide-gray-100 dark:divide-gray-800 text-sm">
                @forelse ($perEmployee as $row)
                    <li class="py-2 flex items-center justify-between" wire:key="emp-{{ $loop->index }}">
                        <span class="font-medium truncate">{{ $row['employee'] ?? '—' }}</span>
                        <span class="text-gray-500 tabular-nums">{{ $row['label'] }}</span>
                    </li>
                @empty
                    <li class="py-4 text-center text-gray-400">No usage in this range.</li>
                @endforelse
            </ul>
        </div>

        {{-- Usage by department --}}
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-3">Usage by department</h3>
            <ul class="divide-y divide-gray-100 dark:divide-gray-800 text-sm">
                @forelse ($perDepartment as $row)
                    <li class="py-2 flex items-center justify-between" wire:key="dept-{{ $loop->index }}">
                        <span class="font-medium truncate">{{ $row['department'] }}</span>
                        <span class="text-gray-500 tabular-nums">{{ $row['label'] }}</span>
                    </li>
                @empty
                    <li class="py-4 text-center text-gray-400">No usage in this range.</li>
                @endforelse
            </ul>
        </div>
    </div>

    {{-- Recent sessions --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Recent application sessions</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="px-3 py-2">Application</th>
                        <th class="px-3 py-2">Window title</th>
                        <th class="px-3 py-2">Employee</th>
                        <th class="px-3 py-2">Computer</th>
                        <th class="px-3 py-2">Started</th>
                        <th class="px-3 py-2">Duration</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($sessions as $session)
                        <tr class="text-gray-900 dark:text-gray-100" wire:key="session-{{ $session->id }}">
                            <td class="px-3 py-2 font-medium">{{ $session->application_name }}</td>
                            <td class="px-3 py-2 text-gray-500 max-w-xs truncate">{{ $session->window_title ?: '—' }}</td>
                            <td class="px-3 py-2">{{ $session->employee?->name ?? '—' }}</td>
                            <td class="px-3 py-2">{{ $session->computer?->hostname ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-500">@dt($session->used_at)</td>
                            <td class="px-3 py-2 tabular-nums text-gray-500">
                                {{ sprintf('%dh %02dm', intdiv($session->duration_seconds, 3600), intdiv($session->duration_seconds % 3600, 60)) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-6 text-center text-gray-400">No application sessions in this range.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $sessions->links() }}
        </div>
    </div>
</div>
