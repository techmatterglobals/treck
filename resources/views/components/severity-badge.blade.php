@props(['severity'])

@php
    // Literal class strings so Tailwind never purges them (severity: Info=blue,
    // Warning=yellow, Critical=red).
    $classes = match ($severity->color()) {
        'yellow' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
        'red' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        default => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
    };
    $dot = match ($severity->color()) {
        'yellow' => 'bg-yellow-500',
        'red' => 'bg-red-500',
        default => 'bg-blue-500',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium {$classes}"]) }}>
    <span class="inline-block h-1.5 w-1.5 rounded-full {{ $dot }}"></span>
    {{ $severity->label() }}
</span>
