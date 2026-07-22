<div class="space-y-6">
    @if (session('status'))
        <div class="rounded-md bg-green-50 dark:bg-green-900/20 px-4 py-2 text-sm text-green-700 dark:text-green-300">
            {{ session('status') }}
        </div>
    @endif

    {{-- Thresholds & lists --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Thresholds &amp; lists</h3>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Idle threshold (seconds)</label>
                <input type="number" min="0" wire:model="idleThreshold" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Long-usage max (seconds)</label>
                <input type="number" min="0" wire:model="longUsageMax" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Restricted applications (comma-separated)</label>
                <input type="text" wire:model="restrictedApps" placeholder="Steam, Discord" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Blacklisted processes (comma-separated)</label>
                <input type="text" wire:model="blacklistedProcesses" placeholder="mimikatz.exe, nc.exe" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
        </div>
    </div>

    {{-- Rules --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Rules</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="px-3 py-2">Event</th>
                        <th class="px-3 py-2">Enabled</th>
                        <th class="px-3 py-2">Severity</th>
                        <th class="px-3 py-2">In-app</th>
                        <th class="px-3 py-2">Email</th>
                        <th class="px-3 py-2">Throttle (s)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($rules as $i => $rule)
                        <tr wire:key="rule-{{ $rule['id'] }}" class="text-gray-900 dark:text-gray-100">
                            <td class="px-3 py-2">{{ $rule['label'] }}</td>
                            <td class="px-3 py-2"><input type="checkbox" wire:model="rules.{{ $i }}.enabled"></td>
                            <td class="px-3 py-2">
                                <select wire:model="rules.{{ $i }}.severity" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                    @foreach ($severities as $s)
                                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-3 py-2"><input type="checkbox" wire:model="rules.{{ $i }}.in_app"></td>
                            <td class="px-3 py-2"><input type="checkbox" wire:model="rules.{{ $i }}.email"></td>
                            <td class="px-3 py-2">
                                <input type="number" min="0" wire:model="rules.{{ $i }}.throttle_seconds" class="w-20 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- My preferences --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5">
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">My preferences</h3>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Channels</label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" value="in_app" wire:model="prefChannels"> In-app</label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" value="email" wire:model="prefChannels"> Email</label>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Minimum severity</label>
                <select wire:model="prefMinSeverity" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    @foreach ($severities as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
                <label class="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" wire:model="prefDigest"> Digest (suppress immediate email)</label>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Quiet hours start</label>
                <input type="time" wire:model="prefQuietStart" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Quiet hours end</label>
                <input type="time" wire:model="prefQuietEnd" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
        </div>
    </div>

    <div class="flex justify-end">
        <button type="button" wire:click="save"
            class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
            Save settings
        </button>
    </div>
</div>
