<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Agent Enrollment
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            @if ($newSecret)
                <div class="rounded-md bg-amber-50 border border-amber-200 px-4 py-3">
                    <div class="text-sm font-medium text-amber-900">Copy this enrollment credential now. It will not be shown again.</div>
                    <code class="mt-2 block break-all rounded bg-white px-3 py-2 text-sm text-amber-950">{{ $newSecret }}</code>
                </div>
            @endif

            <section class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Create Credential</h3>
                    <form method="POST" action="{{ route('agent-enrollment-credentials.store') }}" class="mt-4 grid gap-4 md:grid-cols-4">
                        @csrf
                        <div class="md:col-span-2">
                            <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Name</label>
                            <input id="name" name="name" type="text" value="{{ old('name', 'Agent enrollment') }}" required maxlength="120" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="expires_at" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Expires At</label>
                            <input id="expires_at" name="expires_at" type="datetime-local" value="{{ old('expires_at') }}" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            @error('expires_at') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="max_uses" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Max Uses</label>
                            <input id="max_uses" name="max_uses" type="number" value="{{ old('max_uses', 1) }}" min="1" max="10000" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            @error('max_uses') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-4">
                            <button type="submit" class="inline-flex items-center rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
                                Create
                            </button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Public ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Uses</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Expires</th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($credentials as $credential)
                                <tr>
                                    <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">{{ $credential->name }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-500"><code>{{ $credential->public_id }}</code></td>
                                    <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300">{{ ucfirst($credential->status()) }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300">{{ $credential->uses_count }} / {{ $credential->max_uses ?? 'unlimited' }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300">{{ $credential->expires_at?->toDayDateTimeString() ?? 'Never' }}</td>
                                    <td class="px-6 py-4 text-right text-sm">
                                        @if (! $credential->isRevoked())
                                            <form method="POST" action="{{ route('agent-enrollment-credentials.revoke', $credential) }}">
                                                @csrf
                                                <button type="submit" class="font-medium text-red-600 hover:text-red-800">Revoke</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-8 text-center text-sm text-gray-500">No enrollment credentials.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
