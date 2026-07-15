<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the two roles (Admin, Employee), the permission catalog, the
 * role → permission assignments, and a default Admin account.
 *
 * Run: php artisan db:seed --class=RolePermissionSeeder
 */
class RolePermissionSeeder extends Seeder
{
    /** All permissions in the system. Admin receives every one. */
    public const PERMISSIONS = [
        'view dashboard',
        'manage users',
        'manage employees',
        'manage departments',
        'manage computers',
        'view attendance',      // all employees' attendance
        'correct attendance',
        'view reports',         // organization-wide reports
        'manage settings',
        'view own data',        // an employee's own attendance/activity/reports
    ];

    /** Subset granted to the Employee (self-service) role. */
    public const EMPLOYEE_PERMISSIONS = [
        'view dashboard',
        'view own data',
    ];

    public function run(): void
    {
        // Reset Spatie's in-memory permission cache before seeding.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. Permissions (guard: web — shared by session + sanctum).
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // 2. Roles.
        $admin = Role::firstOrCreate(['name' => UserRole::Admin->value, 'guard_name' => 'web']);
        $employee = Role::firstOrCreate(['name' => UserRole::Employee->value, 'guard_name' => 'web']);

        // 3. Assign permissions.
        $admin->syncPermissions(Permission::all());
        $employee->syncPermissions(self::EMPLOYEE_PERMISSIONS);

        // 4. Default admin account (change the password after first login).
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@treck.test'],
            [
                'name' => 'Treck Admin',
                'password' => Hash::make('password'),
                'is_active' => true,
            ],
        );
        $adminUser->assignRole($admin);
    }
}
