<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ __('Notification Settings') }}
            </h2>
            <a href="{{ route('notifications.index') }}" class="text-sm text-indigo-600 hover:underline">&larr; Back to notifications</a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <livewire:notifications.notification-settings />
        </div>
    </div>
</x-app-layout>
