<?php

namespace App\Enums;

/**
 * Application roles. Role assignment is handled by Spatie Laravel-Permission
 * (string role names); this enum centralizes those names so they aren't
 * duplicated as magic strings across the codebase.
 *
 * The system ships with two roles — Admin and Employee. Add cases here (and a
 * matching Role in RolePermissionSeeder) to introduce more.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Employee = 'employee';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Employee => 'Employee',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
