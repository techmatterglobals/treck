<?php

namespace App\Enums;

/**
 * Application roles. Role assignment is handled by Spatie Laravel-Permission
 * (string role names); this enum centralizes those names so they aren't
 * duplicated as magic strings across the codebase.
 */
enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Manager = 'manager';
    case Employee = 'employee';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Administrator',
            self::Manager => 'Manager',
            self::Employee => 'Employee',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
