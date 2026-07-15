<?php

namespace App\Enums;

/**
 * Live workstation state. Shared by computers.status and activity_logs.status.
 */
enum ComputerStatus: string
{
    case Online = 'online';
    case Idle = 'idle';
    case Locked = 'locked';
    case Offline = 'offline';

    /** Human-readable label for the dashboard. */
    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Idle => 'Idle',
            self::Locked => 'Locked',
            self::Offline => 'Offline',
        };
    }

    /** Tailwind-friendly color token for status badges. */
    public function color(): string
    {
        return match ($this) {
            self::Online => 'green',
            self::Idle => 'amber',
            self::Locked => 'slate',
            self::Offline => 'red',
        };
    }

    /** Whether the workstation is currently connected (not offline). */
    public function isConnected(): bool
    {
        return $this !== self::Offline;
    }

    /** All values — handy for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
