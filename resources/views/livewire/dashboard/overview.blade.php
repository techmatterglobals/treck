<div wire:poll.30s class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    {{-- Total employees --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <div class="text-sm text-gray-500 dark:text-gray-400">Total employees</div>
        <div class="mt-2 text-3xl font-semibold text-gray-900 dark:text-gray-100">
            {{ number_format($totalEmployees) }}
        </div>
    </div>

    {{-- Online employees --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <div class="text-sm text-gray-500 dark:text-gray-400">Online now</div>
        <div class="mt-2 flex items-baseline gap-2">
            <span class="inline-block h-2.5 w-2.5 rounded-full bg-green-500"></span>
            <span class="text-3xl font-semibold text-gray-900 dark:text-gray-100">
                {{ number_format($onlineEmployees) }}
            </span>
            <span class="text-sm text-gray-500">/ {{ number_format($totalEmployees) }}</span>
        </div>
    </div>

    {{-- Today's attendance --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <div class="text-sm text-gray-500 dark:text-gray-400">Today's attendance</div>
        <div class="mt-2 text-3xl font-semibold text-gray-900 dark:text-gray-100">
            {{ number_format($attendance['present']) }}
            <span class="text-base font-normal text-gray-500">({{ $attendance['percent'] }}%)</span>
        </div>
    </div>

    {{-- Average productivity --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <div class="text-sm text-gray-500 dark:text-gray-400">Avg productivity (active ratio)</div>
        <div class="mt-2 text-3xl font-semibold
            {{ $avgProductivity >= 70 ? 'text-green-600' : ($avgProductivity >= 40 ? 'text-amber-500' : 'text-red-600') }}">
            {{ $avgProductivity }}%
        </div>
    </div>
</div>
