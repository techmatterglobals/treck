<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Reports') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- Filters --}}
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-5">
                <form method="GET" action="{{ route('reports.index') }}"
                      class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-6 items-end">
                    <div>
                        <x-input-label for="period" :value="__('Report')" />
                        <select id="period" name="period"
                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                            @foreach ($periods as $p)
                                <option value="{{ $p->value }}" @selected($filter->period === $p)>{{ $p->label() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <x-input-label for="employee_id" :value="__('Employee')" />
                        <select id="employee_id" name="employee_id"
                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                            <option value="">All employees</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}" @selected($filter->employeeId === $employee->id)>
                                    {{ $employee->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <x-input-label for="department_id" :value="__('Department')" />
                        <select id="department_id" name="department_id"
                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                            <option value="">All departments</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" @selected($filter->departmentId === $department->id)>
                                    {{ $department->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <x-input-label for="from" :value="__('From')" />
                        <x-text-input id="from" name="from" type="date" class="mt-1 block w-full"
                                      :value="$filter->from->toDateString()" />
                    </div>

                    <div>
                        <x-input-label for="to" :value="__('To')" />
                        <x-text-input id="to" name="to" type="date" class="mt-1 block w-full"
                                      :value="$filter->to->toDateString()" />
                    </div>

                    <div class="flex items-center gap-2">
                        <x-primary-button>{{ __('Apply') }}</x-primary-button>
                    </div>
                </form>

                {{-- Export buttons carry the current filters as query params --}}
                <div class="mt-4 flex items-center gap-3">
                    <a href="{{ route('reports.export.excel', request()->query()) }}"
                       class="inline-flex items-center px-3 py-1.5 bg-green-600 text-white text-sm rounded-md hover:bg-green-700">
                        {{ __('Export Excel') }}
                    </a>
                    <a href="{{ route('reports.export.pdf', request()->query()) }}"
                       class="inline-flex items-center px-3 py-1.5 bg-red-600 text-white text-sm rounded-md hover:bg-red-700">
                        {{ __('Export PDF') }}
                    </a>
                </div>
            </div>

            {{-- Summary --}}
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
                    <div class="text-xs text-gray-500">Rows</div>
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $totals['rows'] }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
                    <div class="text-xs text-gray-500">Active (h)</div>
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $totals['active_hours'] }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
                    <div class="text-xs text-gray-500">Idle (h)</div>
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $totals['idle_hours'] }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
                    <div class="text-xs text-gray-500">Active %</div>
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $totals['active_ratio'] }}%</div>
                </div>
            </div>

            {{-- Table --}}
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-5 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="px-3 py-2">Employee</th>
                            <th class="px-3 py-2">Department</th>
                            <th class="px-3 py-2">{{ $filter->period->label() }} period</th>
                            <th class="px-3 py-2 text-right">Active (h)</th>
                            <th class="px-3 py-2 text-right">Idle (h)</th>
                            <th class="px-3 py-2 text-right">Active %</th>
                            <th class="px-3 py-2 text-right">Days</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($rows as $row)
                            <tr class="text-gray-900 dark:text-gray-100">
                                <td class="px-3 py-2">{{ $row['employee_name'] }}</td>
                                <td class="px-3 py-2">{{ $row['department'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $row['period_label'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $row['active_hours'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-500">{{ $row['idle_hours'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $row['active_ratio'] }}%</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $row['days_present'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-3 py-6 text-center text-gray-500">
                                    No activity for the selected filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($rows->hasPages())
                <div class="mt-4">
                    {{ $rows->onEachSide(1)->links() }}
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
