<?php

namespace App\Enums;

/**
 * Live presence of a workstation, as maintained by the real-time presence engine
 * (M7). This is deliberately separate from {@see ComputerStatus} (which models
 * the self-reported activity-log state) so the presence projection can evolve
 * independently of the M1-M6 session/activity semantics.
 *
 * Derived from agent events:
 *   Unlock / Logon / active heartbeat  -> Active
 *   idle heartbeat                     -> Idle
 *   Lock                               -> Locked
 *   Logoff / Shutdown                  -> LoggedOut
 *   no contact within the timeout      -> Offline (set by the sweep, not events)
 */
enum PresenceStatus: string
{
    case Active = 'active';
    case Idle = 'idle';
    case Locked = 'locked';
    case LoggedOut = 'logged_out';
    case Offline = 'offline';

    /** Human-readable label for the dashboard. */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Idle => 'Idle',
            self::Locked => 'Locked',
            self::LoggedOut => 'Logged Out',
            self::Offline => 'Offline',
        };
    }

    /** Tailwind-friendly color token for status badges. */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Idle => 'amber',
            self::Locked => 'slate',
            self::LoggedOut => 'indigo',
            self::Offline => 'red',
        };
    }

    /**
     * "Online" = the agent is connected and the workstation is in use, whether
     * the user is actively working, idle, or has locked the screen. Logged-out
     * and offline machines are not online.
     */
    public function isOnline(): bool
    {
        return match ($this) {
            self::Active, self::Idle, self::Locked => true,
            self::LoggedOut, self::Offline => false,
        };
    }

    /** All values - handy for validation rules and grouping. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
