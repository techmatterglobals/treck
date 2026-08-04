<?php

namespace App\Support;

use App\Casts\UtcDateTime;
use Illuminate\Support\Carbon;

/**
 * Renders timestamps in the configured display timezone (Phase: timezone
 * hardening). Agent-sourced instants are stored as UTC and decoded as UTC by
 * {@see UtcDateTime}; this converts them to `treck.display_timezone`
 * only at the presentation layer, so relative ("x ago") and absolute times are
 * always correct regardless of the server's APP_TIMEZONE. `display_timezone`
 * defaults to APP_TIMEZONE, so a server set to Asia/Karachi shows local time
 * with no extra configuration. Backed by the @dt and @ago Blade directives.
 */
class DisplayTime
{
    /** Absolute time in the display timezone (null-safe). */
    public static function format(mixed $value, string $format = 'M j, H:i'): string
    {
        $carbon = self::toCarbon($value);

        return $carbon?->timezone(self::timezone())->format($format) ?? '—';
    }

    /** Relative time ("5 minutes ago"). Instant-based, so timezone-agnostic. */
    public static function ago(mixed $value): string
    {
        $carbon = self::toCarbon($value);

        return $carbon?->diffForHumans() ?? '—';
    }

    public static function timezone(): string
    {
        return (string) config('treck.display_timezone', config('app.timezone', 'UTC'));
    }

    /** Normalize a Carbon / DateTime / parseable string to a Carbon, else null. */
    private static function toCarbon(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
