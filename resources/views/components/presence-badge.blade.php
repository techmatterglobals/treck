@props(['status'])

@php
    $classes = match ($status->color()) {
        'green' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        'amber' => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
        'slate' => 'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-200',
        'indigo' => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200',
        default => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {$classes}"]) }}>
    {{ $status->label() }}
</span>
