<div class="space-y-6">
    @if (session('status'))
        <div class="rounded-md bg-green-50 dark:bg-green-900/20 px-4 py-2 text-sm text-green-700 dark:text-green-300">
            {{ session('status') }}
        </div>
    @endif

    {{-- Create manager --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Create manager</h3>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Name</label>
                <input type="text" wire:model="name" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                @error('name') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Email</label>
                <input type="email" wire:model="email" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                @error('email') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Password</label>
                <input type="password" wire:model="password" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                @error('password') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </div>
            <div class="flex items-end">
                <button type="button" wire:click="createManager"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    Create manager
                </button>
            </div>
        </div>
    </div>

    {{-- Managers + team size / activity --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Managers</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="px-3 py-2">Manager</th>
                        <th class="px-3 py-2">Email</th>
                        <th class="px-3 py-2">Team size</th>
                        <th class="px-3 py-2">Online now</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($managers as $row)
                        <tr wire:key="mgr-{{ $row['user']->id }}" class="text-gray-900 dark:text-gray-100">
                            <td class="px-3 py-2">{{ $row['user']->name }}</td>
                            <td class="px-3 py-2">{{ $row['user']->email }}</td>
                            <td class="px-3 py-2">{{ $row['summary']['team_size'] }}</td>
                            <td class="px-3 py-2">{{ $row['summary']['online'] }}</td>
                            <td class="px-3 py-2 text-right">
                                <button type="button" wire:click="demote({{ $row['user']->id }})"
                                    class="text-xs text-red-600 hover:underline">Demote to employee</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-4 text-center text-gray-400">No managers yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Promote a user --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Promote a user to manager</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($promotable as $user)
                        <tr wire:key="promote-{{ $user->id }}" class="text-gray-900 dark:text-gray-100">
                            <td class="px-3 py-2">{{ $user->name }}</td>
                            <td class="px-3 py-2">{{ $user->email }}</td>
                            <td class="px-3 py-2 text-right">
                                <button type="button" wire:click="promote({{ $user->id }})"
                                    class="text-xs text-indigo-600 hover:underline">Promote to manager</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-3 py-4 text-center text-gray-400">No promotable users.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Assign / transfer employees --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Assign employee to a manager</h3>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Employee</label>
                <select wire:model="assignEmployeeId" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <option value="">Select…</option>
                    @foreach ($employees as $employee)
                        <option value="{{ $employee->id }}">{{ $employee->name }} ({{ $employee->employee_code }})</option>
                    @endforeach
                </select>
                @error('assignEmployeeId') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Manager</label>
                <select wire:model="assignManagerId" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <option value="">Select…</option>
                    @foreach ($managerOptions as $manager)
                        <option value="{{ $manager->id }}">{{ $manager->name }}</option>
                    @endforeach
                </select>
                @error('assignManagerId') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </div>
            <div class="flex items-end">
                <button type="button" wire:click="assignEmployee"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    Assign / transfer
                </button>
            </div>
        </div>

        <div class="overflow-x-auto mt-5">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="px-3 py-2">Employee</th>
                        <th class="px-3 py-2">Current manager</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($employees as $employee)
                        <tr wire:key="emp-{{ $employee->id }}" class="text-gray-900 dark:text-gray-100">
                            <td class="px-3 py-2">{{ $employee->name }}</td>
                            <td class="px-3 py-2">{{ $employee->manager?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-right">
                                @if ($employee->manager_user_id)
                                    <button type="button" wire:click="removeEmployee({{ $employee->id }})"
                                        class="text-xs text-red-600 hover:underline">Remove</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
