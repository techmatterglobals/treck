<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ __('Download Details') }}
            </h2>
            <a href="{{ route('downloads.index') }}" class="text-sm text-indigo-600 hover:underline">&larr; Back to downloads</a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="rounded-md bg-blue-50 dark:bg-blue-900/20 px-4 py-2 text-xs text-blue-700 dark:text-blue-300 mb-4">
                Metadata only. Treck never reads, stores or fetches the file's contents.
            </div>

            <div class="bg-white dark:bg-gray-800 shadow rounded-lg divide-y divide-gray-100 dark:divide-gray-700">
                @php
                    $rows = [
                        'File name' => $download->file_name,
                        'Extension' => strtoupper((string) $download->file_extension),
                        'Size' => $download->sizeLabel(),
                        'Download folder' => $download->download_folder,
                        'Full local path' => $download->local_path,
                        'SHA-256 hash' => $download->sha256_hash ?: '— (hashing disabled or skipped)',
                        'Downloaded at' => $download->downloaded_at?->toDayDateTimeString(),
                        'Employee' => $download->employee?->name ?? '—',
                        'Manager' => $download->employee?->manager?->name ?? '—',
                        'Computer' => $download->computer?->hostname ?? '—',
                        'Windows username' => $download->windows_username ?? '—',
                        'Source application' => $download->application_name ?? '—',
                        'Source process' => $download->process_name ?? '—',
                        'Source window title' => $download->window_title ?? '—',
                        'Session ID' => $download->session_id ?? '—',
                    ];
                @endphp
                @foreach ($rows as $label => $value)
                    <div class="flex px-4 py-3 text-sm">
                        <div class="w-48 shrink-0 text-gray-500 dark:text-gray-400">{{ $label }}</div>
                        <div class="text-gray-900 dark:text-gray-100 break-all">{{ $value }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
