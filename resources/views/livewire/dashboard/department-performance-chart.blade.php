<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Department performance</h3>

    <div class="space-y-3">
        @forelse ($departments as $dept)
            @php
                $color = $dept['ratio'] >= 70 ? 'bg-green-500'
                    : ($dept['ratio'] >= 40 ? 'bg-amber-400' : 'bg-red-400');
            @endphp
            <div>
                <div class="flex items-center justify-between text-sm mb-1">
                    <span class="text-gray-700 dark:text-gray-300">{{ $dept['department'] }}</span>
                    <span class="text-gray-500 tabular-nums">{{ $dept['ratio'] }}%</span>
                </div>
                <div class="w-full h-2.5 rounded-full bg-gray-100 dark:bg-gray-700 overflow-hidden">
                    <div class="h-full {{ $color }} rounded-full" style="width: {{ $dept['ratio'] }}%"></div>
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">No departments yet.</p>
        @endforelse
    </div>
</div>
