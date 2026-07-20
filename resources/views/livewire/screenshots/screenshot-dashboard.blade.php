<div class="space-y-6">
    {{-- Filters --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-6">
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">From</label>
                <input type="date" wire:model.live="from"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">To</label>
                <input type="date" wire:model.live="to"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Employee</label>
                <select wire:model.live="employeeId"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <option value="">All employees</option>
                    @foreach ($employees as $employee)
                        <option value="{{ $employee->id }}">{{ $employee->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Computer</label>
                <select wire:model.live="computerId"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <option value="">All computers</option>
                    @foreach ($computers as $computer)
                        <option value="{{ $computer->id }}">{{ $computer->hostname ?? 'Computer #'.$computer->id }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Department</label>
                <select wire:model.live="departmentId"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <option value="">All departments</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}">{{ $department->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Application</label>
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="Process / title"
                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
        </div>
        <div class="mt-4 flex items-center justify-between">
            <span class="text-xs text-gray-400" wire:loading>updating…</span>
            <button type="button" wire:click="resetFilters"
                class="text-sm text-indigo-600 hover:underline">Reset filters</button>
        </div>
    </div>

    {{-- Capture status --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @php
            $cards = [
                ['label' => 'Captures', 'value' => number_format($status['total'])],
                ['label' => 'Computers', 'value' => number_format($status['computers'])],
                ['label' => 'Last capture', 'value' => $status['last_capture_at']?->diffForHumans() ?? '—'],
                ['label' => 'Range', 'value' => \Illuminate\Support\Carbon::parse($from)->format('M j').' – '.\Illuminate\Support\Carbon::parse($to)->format('M j')],
            ];
        @endphp
        @foreach ($cards as $card)
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $card['label'] }}</div>
                <div class="mt-2 text-2xl font-semibold text-gray-900 dark:text-gray-100 tabular-nums">
                    {{ $card['value'] }}
                </div>
            </div>
        @endforeach
    </div>

    {{-- Grid of latest screenshots --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Latest screenshots</h3>
        </div>

        @if ($screenshots->isEmpty())
            <p class="py-10 text-center text-gray-400">No screenshots in this range.</p>
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($screenshots as $shot)
                    <a href="{{ route('screenshots.show', $shot) }}" wire:key="shot-{{ $shot->id }}"
                        class="group block overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700 hover:ring-2 hover:ring-indigo-400">
                        <div class="aspect-video bg-gray-100 dark:bg-gray-900 overflow-hidden">
                            <img src="{{ $urls[$shot->id] }}" alt="Screenshot {{ $shot->id }}" loading="lazy"
                                class="h-full w-full object-cover object-top group-hover:opacity-90">
                        </div>
                        <div class="p-3 text-xs">
                            <div class="font-medium text-gray-900 dark:text-gray-100 truncate">
                                {{ $shot->employee?->name ?? 'Unassigned' }}
                            </div>
                            <div class="text-gray-500 truncate">{{ $shot->computer?->hostname ?? '—' }}</div>
                            <div class="mt-1 flex items-center justify-between text-gray-400">
                                <span>{{ $shot->captured_at?->format('M j, H:i') }}</span>
                                <span class="tabular-nums">{{ $shot->resolution }}</span>
                            </div>
                            @if ($shot->active_process)
                                <div class="mt-1 text-gray-500 truncate">{{ $shot->active_process }}</div>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $screenshots->links() }}
            </div>
        @endif
    </div>
</div>
