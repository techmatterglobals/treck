<?php

namespace App\Enums;

/**
 * Granularity at which a report aggregates activity.
 */
enum ReportPeriod: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
