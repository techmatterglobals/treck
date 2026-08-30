<?php

namespace App\Enums;

enum PlatformRole: string
{
    case SuperAdmin = 'platform-super-admin';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
