<div class="space-y-6">
    {{-- Summary cards --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
            <div class="text-xs text-gray-500 dark:text-gray-400">Downloads</div>
            <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($summary['total']) }}</div>
        </div>
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
            <div class="text-xs text-gray-500 dark:text-gray-400">Executables</div>
            <div class="text-2xl font-semibold text-red-600">{{ number_format($summary['executables']) }}</div>
        </div>
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
            <div class="text-xs text-gray-500 dark:text-gray-400">Archives</div>
            <div class="text-2xl font-semibold text-amber-600">{{ number_format($summary['archives']) }}</div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search file / app / title…"
                class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            <select wire:model.live="employeeId" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                <option value="">All employees</option>
                @foreach ($employees as $employee)
                    <option value="{{ $employee->id }}">{{ $employee->name }}</option>
                @endforeach
            </select>
            @if ($managers->isNotEmpty())
                <select wire:model.live="managerUserId" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <option value="">All managers</option>
                    @foreach ($managers as $manager)
                        <option value="{{ $manager->id }}">{{ $manager->name }}</option>
                    @endforeach
                </select>
            @endif
            <select wire:model.live="computerId" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                <option value="">All computers</option>
                @foreach ($computers as $computer)
                    <option value="{{ $computer->id }}">{{ $computer->hostname }}</option>
                @endforeach
            </select>
            <input type="text" wire:model.live.debounce.400ms="extension" placeholder="Extension (e.g. exe)"
                class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            <input type="text" wire:model.live.debounce.400ms="application" placeholder="Application"
                class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            <input type="date" wire:model.live="from" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            <input type="date" wire:model.live="to" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
        </div>
        <div class="mt-3 flex justify-between">
            <button type="button" wire:click="resetFilters" class="text-xs text-gray-500 hover:underline">Reset filters</button>
            <a href="{{ route('downloads.export', ['from' => $from, 'to' => $to, 'employee_id' => $employeeId, 'manager_user_id' => $managerUserId, 'computer_id' => $computerId, 'extension' => $extension, 'application' => $application, 'search' => $search]) }}"
                class="text-xs text-indigo-600 hover:underline">Export (Excel)</a>
        </div>
    </div>

    {{-- Table --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
            <thead>
                <tr class="text-left text-gray-500 dark:text-gray-400">
                    <th class="px-4 py-2 cursor-pointer" wire:click="sortBy('downloaded_at')">Downloaded</th>
                    <th class="px-4 py-2 cursor-pointer" wire:click="sortBy('file_name')">File</th>
                    <th class="px-4 py-2 cursor-pointer" wire:click="sortBy('file_extension')">Type</th>
                    <th class="px-4 py-2 cursor-pointer" wire:click="sortBy('file_size')">Size</th>
                    <th class="px-4 py-2">Employee</th>
                    <th class="px-4 py-2">Manager</th>
                    <th class="px-4 py-2">Computer</th>
                    <th class="px-4 py-2 cursor-pointer" wire:click="sortBy('application_name')">Application</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($downloads as $d)
                    <tr wire:key="dl-{{ $d->id }}" class="text-gray-900 dark:text-gray-100">
                        <td class="px-4 py-2 whitespace-nowrap">@dt($d->downloaded_at, 'Y-m-d H:i')</td>
                        <td class="px-4 py-2 max-w-xs truncate" title="{{ $d->file_name }}">{{ $d->file_name }}</td>
                        <td class="px-4 py-2 uppercase">{{ $d->file_extension }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $d->sizeLabel() }}</td>
                        <td class="px-4 py-2">{{ $d->employee?->name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $d->employee?->manager?->name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $d->computer?->hostname ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $d->application_name ?? '—' }}</td>
                        <td class="px-4 py-2 text-right">
                            <a href="{{ route('downloads.show', $d) }}" class="text-xs text-indigo-600 hover:underline">Details</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-6 text-center text-gray-400">No downloads match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $downloads->links() }}</div>
</div>
