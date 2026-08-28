<?php

namespace App\Enums;

/**
 * Severity of a notification (Phase 9). Ordered Info < Warning < Critical so
 * per-user "minimum severity" preferences can filter with {@see atLeast()}.
 */
enum NotificationSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Info',
            self::Warning => 'Warning',
            self::Critical => 'Critical',
        };
    }

    /** Tailwind-friendly colour token, consistent with the rest of the dashboard. */
    public function color(): string
    {
        return match ($this) {
            self::Info => 'blue',
            self::Warning => 'yellow',
            self::Critical => 'red',
        };
    }

    /** Numeric rank for ordering / min-severity comparison. */
    public function rank(): int
    {
        return match ($this) {
            self::Info => 1,
            self::Warning => 2,
            self::Critical => 3,
        };
    }

    /** True when this severity is at least as high as $floor. */
    public function atLeast(self $floor): bool
    {
        return $this->rank() >= $floor->rank();
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
