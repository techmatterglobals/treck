<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Manager Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="rounded-md bg-indigo-50 dark:bg-indigo-900/20 px-4 py-3 text-sm text-indigo-700 dark:text-indigo-300">
                You are viewing data for <strong>your team only</strong>. Presence, application usage,
                screenshots and reports below are automatically scoped to your assigned employees.
            </div>

            {{-- Team presence (scoped to this manager's employees). --}}
            <livewire:presence.presence-board />

            {{-- Team roster (scoped). --}}
            <livewire:employees.employee-index />
        </div>
    </div>
</x-app-layout>
