<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">Select organization</h1>
    </x-slot>

    <div class="max-w-3xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
        @if (session('status'))
            <div class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-700">
                {{ session('status') }}
            </div>
        @endif

        @if ($organizations->isEmpty())
            <div class="rounded-md bg-white dark:bg-gray-800 p-6 shadow-sm">
                <p class="text-sm text-gray-700 dark:text-gray-200">No active organizations are available for this account.</p>
            </div>
        @else
            <div class="rounded-md bg-white dark:bg-gray-800 p-6 shadow-sm">
                <form method="POST" action="{{ route('organizations.switch') }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="redirect" value="{{ route('dashboard', absolute: false) }}">

                    <div>
                        <label for="organization_id" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Organization</label>
                        <select id="organization_id" name="organization_id" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            @foreach ($organizations as $organization)
                                <option value="{{ $organization->id }}" @selected($currentOrganization?->is($organization))>
                                    {{ $organization->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('organization_id')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                        Switch
                    </button>
                </form>
            </div>
        @endif
    </div>
</x-app-layout>
