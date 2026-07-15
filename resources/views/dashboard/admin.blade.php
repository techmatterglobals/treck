<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Admin Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- Cards --}}
            <livewire:dashboard.overview />

            {{-- Charts --}}
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <livewire:dashboard.productivity-chart />
                <livewire:dashboard.department-performance-chart />
            </div>

            {{-- Employee status / active / idle table --}}
            <livewire:dashboard.employee-status-table />
        </div>
    </div>
</x-app-layout>
