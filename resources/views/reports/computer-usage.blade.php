<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ __('Computer Usage History') }}
            </h2>
            <a href="{{ route('reports.index') }}" class="text-sm text-indigo-600 hover:underline">&larr; Back to reports</a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <form method="GET" class="bg-white dark:bg-gray-800 shadow rounded-lg p-4 flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">From</label>
                    <input type="date" name="from" value="{{ $filter->from->toDateString() }}"
                        class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">To</label>
                    <input type="date" name="to" value="{{ $filter->to->toDateString() }}"
                        class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                </div>
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    Apply
                </button>
            </form>

            <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-2">Computer</th>
                            <th class="px-4 py-2">Employee</th>
                            <th class="px-4 py-2">Login</th>
                            <th class="px-4 py-2">Logout</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($sessions as $s)
                            <tr class="text-gray-900 dark:text-gray-100">
                                <td class="px-4 py-2 font-medium">{{ $s->computer }}</td>
                                <td class="px-4 py-2">{{ $s->employee }} <span class="text-gray-400">({{ $s->employee_code }})</span></td>
                                <td class="px-4 py-2">{{ \Illuminate\Support\Carbon::parse($s->login_at)->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-2">{{ $s->logout_at ? \Illuminate\Support\Carbon::parse($s->logout_at)->format('Y-m-d H:i') : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No computer sessions in this range.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
