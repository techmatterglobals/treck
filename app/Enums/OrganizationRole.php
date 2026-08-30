<?php

namespace App\Enums;

enum OrganizationRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Manager = 'manager';
    case Employee = 'employee';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
