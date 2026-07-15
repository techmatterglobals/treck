<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Edit Employee') }} — {{ $employee->name }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6">
                <form method="POST" action="{{ route('employees.update', $employee) }}" class="space-y-6">
                    @csrf
                    @method('PUT')
                    @include('employees._form')

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Save changes') }}</x-primary-button>
                        <a href="{{ route('employees.show', $employee) }}"
                           class="text-sm text-gray-600 dark:text-gray-400 hover:underline">
                            {{ __('Cancel') }}
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
