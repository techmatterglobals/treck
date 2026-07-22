<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Treck') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100">
    <nav class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex h-16 items-center justify-between">
            <div class="flex items-center gap-6">
                <a href="{{ route('dashboard') }}" class="font-bold text-indigo-600">Treck</a>
                <a href="{{ route('dashboard') }}" class="text-sm hover:underline">Dashboard</a>
                @role('admin')
                    <a href="{{ route('presence.index') }}" class="text-sm hover:underline">Live Presence</a>
                    <a href="{{ route('application-usage.index') }}" class="text-sm hover:underline">App Usage</a>
                    <a href="{{ route('screenshots.index') }}" class="text-sm hover:underline">Screenshots</a>
                    <a href="{{ route('notifications.index') }}" class="text-sm hover:underline">Notifications</a>
                @endrole
                @can('manage employees')
                    <a href="{{ route('employees.index') }}" class="text-sm hover:underline">Employees</a>
                @endcan
                @can('view reports')
                    <a href="{{ route('reports.index') }}" class="text-sm hover:underline">Reports</a>
                @endcan
            </div>
            <div class="flex items-center gap-4">
                @role('admin')
                    <livewire:notifications.notification-bell />
                @endrole
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-sm text-gray-500 hover:text-gray-800 dark:hover:text-gray-200">
                        Log out ({{ auth()->user()?->name }})
                    </button>
                </form>
            </div>
        </div>
    </nav>

    @isset($header)
        <header class="bg-white dark:bg-gray-800 shadow">
            <div class="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8">{{ $header }}</div>
        </header>
    @endisset

    <main>{{ $slot }}</main>

    @livewireScripts
</body>
</html>
