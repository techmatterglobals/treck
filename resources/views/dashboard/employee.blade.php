<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('My Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                <p class="text-gray-700 dark:text-gray-300">
                    Welcome, {{ auth()->user()->name }}. Your personal attendance and
                    productivity widgets appear here.
                </p>
                {{-- Personal widgets (own daily summary, timeline) plug in here,
                     reusing ActivitySummaryService scoped to the current user. --}}
            </div>
        </div>
    </div>
</x-app-layout>
