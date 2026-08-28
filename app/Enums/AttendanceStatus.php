<?php

namespace App\Enums;

/**
 * Daily attendance classification for an employee.
 */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case Absent = 'absent';
    case HalfDay = 'half_day';
    case OnLeave = 'on_leave';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Late => 'Late',
            self::Absent => 'Absent',
            self::HalfDay => 'Half Day',
            self::OnLeave => 'On Leave',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Present => 'green',
            self::Late => 'amber',
            self::Absent => 'red',
            self::HalfDay => 'orange',
            self::OnLeave => 'blue',
        };
    }

    /** Whether this status counts as the employee having shown up. */
    public function isPresent(): bool
    {
        return in_array($this, [self::Present, self::Late, self::HalfDay], true);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
