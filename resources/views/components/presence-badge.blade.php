@props(['status'])

@php
    $classes = match ($status->color()) {
        'green' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        'yellow' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
        'blue' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        'gray' => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
        default => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {$classes}"]) }}>
    {{ $status->label() }}
</span>
