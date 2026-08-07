<?php

namespace App\Enums;

/**
 * Application roles. Role assignment is handled by Spatie Laravel-Permission
 * (string role names); this enum centralizes those names so they aren't
 * duplicated as magic strings across the codebase.
 *
 * The organization hierarchy (Phase 11) is: Super Admin → Manager → Employee.
 *
 *   - Admin ('admin')  — the Super Admin: unrestricted, organization-wide access.
 *     Kept as the string 'admin' for full backward compatibility with existing
 *     deployments, `role:admin` routes and `isAdministrator()` checks.
 *   - Manager          — supervises an assigned set of employees; scoped access.
 *   - Employee         — self-service only.
 *
 * Add cases here (and a matching Role in RolePermissionSeeder) to introduce more.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Employee = 'employee';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Super Administrator',
            self::Manager => 'Manager',
            self::Employee => 'Employee',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
