<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 11 — guarantee the `manager` role exists for existing deployments.
 *
 * The role is normally created by RolePermissionSeeder, but a running
 * production instance upgraded via `php artisan migrate` (without re-seeding)
 * would not have it, so promoting/creating a manager would throw
 * RoleDoesNotExist. This migration creates the role and its scoped permissions
 * idempotently — no existing users, roles or permissions are modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // The scoped permissions a manager needs (all already part of the base
        // catalog on a seeded install; firstOrCreate makes this self-contained).
        $managerPermissions = ['view dashboard', 'view reports', 'view attendance', 'view own data'];

        foreach ($managerPermissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $manager = Role::firstOrCreate(['name' => UserRole::Manager->value, 'guard_name' => 'web']);
        $manager->givePermissionTo($managerPermissions);

        // The employee role is the demotion target; ensure it exists too.
        Role::firstOrCreate(['name' => UserRole::Employee->value, 'guard_name' => 'web']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Non-destructive: leaving the role in place is safe and avoids orphaning
        // any users assigned to it. Intentionally a no-op.
    }
};
