{{-- Shared employee form fields. Expects: $employee, $departments, $roles, $isCreate --}}
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    {{-- Name --}}
    <div>
        <x-input-label for="name" :value="__('Full name')" />
        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                      :value="old('name', $employee->name)" required autofocus />
        <x-input-error :messages="$errors->get('name')" class="mt-1" />
    </div>

    {{-- Email --}}
    <div>
        <x-input-label for="email" :value="__('Email')" />
        <x-text-input id="email" name="email" type="email" class="mt-1 block w-full"
                      :value="old('email', $employee->email)" required />
        <x-input-error :messages="$errors->get('email')" class="mt-1" />
    </div>

    {{-- Employee code --}}
    <div>
        <x-input-label for="employee_code" :value="__('Employee code')" />
        <x-text-input id="employee_code" name="employee_code" type="text" class="mt-1 block w-full"
                      :value="old('employee_code', $employee->employee_code)" required />
        <x-input-error :messages="$errors->get('employee_code')" class="mt-1" />
    </div>

    {{-- Designation --}}
    <div>
        <x-input-label for="designation" :value="__('Designation')" />
        <x-text-input id="designation" name="designation" type="text" class="mt-1 block w-full"
                      :value="old('designation', $employee->designation)" />
        <x-input-error :messages="$errors->get('designation')" class="mt-1" />
    </div>

    {{-- Department (assign department) --}}
    <div>
        <x-input-label for="department_id" :value="__('Department')" />
        <select id="department_id" name="department_id"
                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm">
            <option value="">— Unassigned —</option>
            @foreach ($departments as $department)
                <option value="{{ $department->id }}"
                    @selected((int) old('department_id', $employee->department_id) === $department->id)>
                    {{ $department->name }}
                </option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('department_id')" class="mt-1" />
    </div>

    {{-- Role --}}
    <div>
        <x-input-label for="role" :value="__('Role')" />
        <select id="role" name="role"
                class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm">
            @foreach ($roles as $role)
                <option value="{{ $role->value }}"
                    @selected(old('role', $isCreate ? 'employee' : optional($employee->user)->getRoleNames()->first()) === $role->value)>
                    {{ $role->label() }}
                </option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('role')" class="mt-1" />
    </div>

    {{-- Phone --}}
    <div>
        <x-input-label for="phone" :value="__('Phone')" />
        <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full"
                      :value="old('phone', $employee->phone)" />
        <x-input-error :messages="$errors->get('phone')" class="mt-1" />
    </div>

    {{-- Joined on --}}
    <div>
        <x-input-label for="joined_on" :value="__('Joined on')" />
        <x-text-input id="joined_on" name="joined_on" type="date" class="mt-1 block w-full"
                      :value="old('joined_on', optional($employee->joined_on)->format('Y-m-d'))" />
        <x-input-error :messages="$errors->get('joined_on')" class="mt-1" />
    </div>

    {{-- Password --}}
    <div>
        <x-input-label for="password" :value="$isCreate ? __('Password') : __('New password (leave blank to keep)')" />
        <x-text-input id="password" name="password" type="password" class="mt-1 block w-full"
                      :required="$isCreate" autocomplete="new-password" />
        <x-input-error :messages="$errors->get('password')" class="mt-1" />
    </div>

    {{-- Password confirmation --}}
    <div>
        <x-input-label for="password_confirmation" :value="__('Confirm password')" />
        <x-text-input id="password_confirmation" name="password_confirmation" type="password"
                      class="mt-1 block w-full" :required="$isCreate" autocomplete="new-password" />
    </div>
</div>
