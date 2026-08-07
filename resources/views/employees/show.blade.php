<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ $employee->name }}
            </h2>
            <div class="flex items-center gap-2">
                <a href="{{ route('employees.edit', $employee) }}"
                   class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                    {{ __('Edit') }}
                </a>
                <form method="POST" action="{{ route('employees.destroy', $employee) }}"
                      onsubmit="return confirm('Delete this employee? Their login will be disabled.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="inline-flex items-center px-3 py-1.5 bg-red-600 text-white text-sm rounded-md hover:bg-red-700">
                        {{ __('Delete') }}
                    </button>
                </form>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-md bg-green-50 dark:bg-green-900/40 p-4 text-sm text-green-800 dark:text-green-200">
                    {{ session('status') }}
                </div>
            @endif

            {{-- Profile --}}
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">{{ __('Profile') }}</h3>
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 text-sm">
                    <div><dt class="text-gray-500">Employee code</dt><dd class="text-gray-900 dark:text-gray-100">{{ $employee->employee_code }}</dd></div>
                    <div><dt class="text-gray-500">Email</dt><dd class="text-gray-900 dark:text-gray-100">{{ $employee->email }}</dd></div>
                    <div><dt class="text-gray-500">Designation</dt><dd class="text-gray-900 dark:text-gray-100">{{ $employee->designation ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Department</dt><dd class="text-gray-900 dark:text-gray-100">{{ $employee->department?->name ?? 'Unassigned' }}</dd></div>
                    <div><dt class="text-gray-500">Phone</dt><dd class="text-gray-900 dark:text-gray-100">{{ $employee->phone ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Joined on</dt><dd class="text-gray-900 dark:text-gray-100">{{ optional($employee->joined_on)->format('d M Y') ?? '—' }}</dd></div>
                </dl>
            </div>

            {{-- Computers --}}
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">{{ __('Assigned computers') }}</h3>

                @if ($employee->computers->isEmpty())
                    <p class="text-sm text-gray-500">{{ __('No computers assigned.') }}</p>
                @else
                    <ul class="divide-y divide-gray-200 dark:divide-gray-700 mb-4">
                        @foreach ($employee->computers as $computer)
                            <li class="flex items-center justify-between py-2 text-sm">
                                <span class="text-gray-900 dark:text-gray-100">
                                    {{ $computer->hostname ?? $computer->device_uuid }}
                                    <span class="text-gray-500">({{ $computer->os ?? 'unknown OS' }})</span>
                                </span>
                                <form method="POST"
                                      action="{{ route('employees.computers.unassign', [$employee, $computer]) }}"
                                      onsubmit="return confirm('Unassign this computer?');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-red-600 hover:underline">{{ __('Unassign') }}</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif

                {{-- Assign computer --}}
                <form method="POST" action="{{ route('employees.computers.assign', $employee) }}"
                      class="flex items-end gap-3">
                    @csrf
                    <div class="flex-1">
                        <x-input-label for="computer_id" :value="__('Assign a computer')" />
                        <select id="computer_id" name="computer_id"
                                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm">
                            <option value="">— Select an unassigned computer —</option>
                            @foreach ($assignableComputers as $computer)
                                <option value="{{ $computer->id }}">
                                    {{ $computer->hostname ?? $computer->device_uuid }} ({{ $computer->os ?? 'unknown' }})
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('computer_id')" class="mt-1" />
                    </div>
                    <x-primary-button>{{ __('Assign') }}</x-primary-button>
                </form>
            </div>

        </div>
    </div>
</x-app-layout>
