<?php

namespace App\Enums;

/**
 * Why a PC session (activity_logs row) was closed.
 */
enum SessionEndReason: string
{
    case Logout = 'logout';
    case Shutdown = 'shutdown';
    case Timeout = 'timeout';
    case Reconciled = 'reconciled';

    public function label(): string
    {
        return match ($this) {
            self::Logout => 'Logged out',
            self::Shutdown => 'Shut down',
            self::Timeout => 'Idle timeout',
            self::Reconciled => 'Reconciled by server',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
