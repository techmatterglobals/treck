<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ __('Download Reports') }}
            </h2>
            <a href="{{ route('downloads.index') }}" class="text-sm text-indigo-600 hover:underline">&larr; Back to downloads</a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <form method="GET" class="bg-white dark:bg-gray-800 shadow rounded-lg p-4 flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Group by</label>
                    <select name="dimension" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        @foreach (['employee' => 'Employee', 'manager' => 'Manager', 'computer' => 'Computer', 'extension' => 'File type', 'application' => 'Application', 'day' => 'Date'] as $key => $label)
                            <option value="{{ $key }}" @selected($dimension === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">From</label>
                    <input type="date" name="from" value="{{ $filter->from->toDateString() }}" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">To</label>
                    <input type="date" name="to" value="{{ $filter->to->toDateString() }}" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                </div>
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Apply</button>
                <a href="{{ route('downloads.export', ['from' => $filter->from->toDateString(), 'to' => $filter->to->toDateString()]) }}"
                    class="text-sm text-indigo-600 hover:underline">Export list (Excel)</a>
            </form>

            <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-2">{{ ucfirst($dimension) }}</th>
                            <th class="px-4 py-2">Downloads</th>
                            <th class="px-4 py-2">Total bytes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($rows as $row)
                            <tr class="text-gray-900 dark:text-gray-100">
                                <td class="px-4 py-2">{{ $row->group ?? '—' }}</td>
                                <td class="px-4 py-2">{{ number_format($row->downloads) }}</td>
                                <td class="px-4 py-2">{{ number_format((int) $row->bytes) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-6 text-center text-gray-400">No downloads in this range.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-gray-400">Employee/manager/computer groups show raw ids; use the list export for full names.</p>
        </div>
    </div>
</x-app-layout>
