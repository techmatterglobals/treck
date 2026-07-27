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

            {{-- Quick links to the (team-scoped) monitoring screens. --}}
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <a href="{{ route('presence.index') }}" class="rounded-lg bg-white dark:bg-gray-800 shadow p-4 text-center hover:ring-2 hover:ring-indigo-400">
                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100">Presence</div>
                </a>
                <a href="{{ route('application-usage.index') }}" class="rounded-lg bg-white dark:bg-gray-800 shadow p-4 text-center hover:ring-2 hover:ring-indigo-400">
                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100">App Usage</div>
                </a>
                <a href="{{ route('screenshots.index') }}" class="rounded-lg bg-white dark:bg-gray-800 shadow p-4 text-center hover:ring-2 hover:ring-indigo-400">
                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100">Screenshots</div>
                </a>
                <a href="{{ route('reports.index') }}" class="rounded-lg bg-white dark:bg-gray-800 shadow p-4 text-center hover:ring-2 hover:ring-indigo-400">
                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100">Reports</div>
                </a>
            </div>

            {{-- Team presence (scoped to this manager's employees). --}}
            <livewire:presence.presence-board />

            {{-- Team roster (scoped). --}}
            <livewire:employees.employee-index />
        </div>
    </div>
</x-app-layout>
