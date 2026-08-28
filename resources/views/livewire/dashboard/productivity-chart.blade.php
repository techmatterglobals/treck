<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Daily productivity</h3>
        <select wire:model.live="days"
                class="text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
            <option value="7">Last 7 days</option>
            <option value="14">Last 14 days</option>
            <option value="30">Last 30 days</option>
        </select>
    </div>

    {{-- Dependency-free CSS bar chart. Swap for Chart.js by feeding @json($series). --}}
    <div class="flex items-end gap-1 h-48 border-b border-gray-200 dark:border-gray-700">
        @foreach ($series as $point)
            @php
                $color = $point['ratio'] >= 70 ? 'bg-green-500'
                    : ($point['ratio'] >= 40 ? 'bg-amber-400' : 'bg-red-400');
            @endphp
            <div class="flex-1 flex items-end h-full" title="{{ $point['label'] }}: {{ $point['ratio'] }}%">
                <div class="w-full {{ $color }} rounded-t transition-all"
                     style="height: {{ max($point['ratio'], 1) }}%"></div>
            </div>
        @endforeach
    </div>

    {{-- X-axis labels (show a subset to avoid crowding) --}}
    <div class="flex gap-1 mt-2 text-[10px] text-gray-400">
        @foreach ($series as $i => $point)
            <div class="flex-1 text-center">
                {{ ($i % max(1, intdiv(count($series), 7)) === 0) ? $point['label'] : '' }}
            </div>
        @endforeach
    </div>
</div>
