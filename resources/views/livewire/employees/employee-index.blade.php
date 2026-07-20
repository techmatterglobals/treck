<div>
    {{-- Filters --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between mb-4">
        <div class="flex-1">
            <label for="search" class="sr-only">Search</label>
            <input id="search" type="search" wire:model.live.debounce.300ms="search"
                   placeholder="Search by name, email, or code…"
                   class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm" />
        </div>
        <div>
            <select wire:model.live="department"
                    class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm">
                <option value="">All departments</option>
                @foreach ($departments as $dept)
                    <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-md bg-green-50 dark:bg-green-900/40 p-3 text-sm text-green-800 dark:text-green-200">
            {{ session('status') }}
        </div>
    @endif

    {{-- Table --}}
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
            <thead>
                <tr class="text-left text-gray-500 dark:text-gray-400">
                    <th class="px-3 py-2">Code</th>
                    <th class="px-3 py-2">Name</th>
                    <th class="px-3 py-2">Email</th>
                    <th class="px-3 py-2">Department</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Last seen</th>
                    <th class="px-3 py-2 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($employees as $employee)
                    <tr class="text-gray-900 dark:text-gray-100">
                        <td class="px-3 py-2 font-mono">{{ $employee->employee_code }}</td>
                        <td class="px-3 py-2">{{ $employee->name }}</td>
                        <td class="px-3 py-2">{{ $employee->email }}</td>
                        <td class="px-3 py-2">{{ $employee->department?->name ?? '—' }}</td>
                        <td class="px-3 py-2">
                            <x-presence-badge :status="$statuses[$employee->id]" />
                        </td>
                        <td class="px-3 py-2 text-gray-500">
                            @php($lastSeen = $employee->computers->map(fn ($c) => $c->presence?->last_synced_at)->filter()->max())
                            {{ $lastSeen?->diffForHumans() ?? '—' }}
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex items-center justify-end gap-3">
                                <a href="{{ route('employees.show', $employee) }}" class="text-indigo-600 hover:underline">View</a>
                                <a href="{{ route('employees.edit', $employee) }}" class="text-indigo-600 hover:underline">Edit</a>
                                <button type="button"
                                        wire:click="delete({{ $employee->id }})"
                                        wire:confirm="Delete this employee? Their login will be disabled."
                                        class="text-red-600 hover:underline">
                                    Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-3 py-6 text-center text-gray-500">No employees found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $employees->links() }}
    </div>
</div>
