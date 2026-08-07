<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ __('File Downloads') }}
            </h2>
            <a href="{{ route('downloads.reports') }}" class="text-sm text-indigo-600 hover:underline">Download reports &rarr;</a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <livewire:downloads.file-download-dashboard />
        </div>
    </div>
</x-app-layout>
