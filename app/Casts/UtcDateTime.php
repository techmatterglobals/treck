<?php

namespace App\Casts;

use App\Support\DisplayTime;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Casts a datetime column whose stored wall-clock digits are UTC.
 *
 * Agent-sourced instants (heartbeats, sessions, app usage, screenshots,
 * downloads) are captured on the device as UTC and stored verbatim as UTC
 * digits (e.g. "2026-08-03 13:19:39"). Laravel's built-in `'datetime'` cast
 * would re-read those digits in `config('app.timezone')` — so on a server
 * running Asia/Karachi it would decode "13:19:39" as 13:19 Karachi (= 08:19
 * UTC), an instant five hours *before* the real event, and `diffForHumans()`
 * would print "5 hours ago" for something that just happened.
 *
 * This cast fixes the instant at the source: it always interprets the stored
 * digits as UTC on read, and normalizes any value to UTC digits on write, so
 * the column is correct regardless of what `APP_TIMEZONE` is set to. Rendering
 * to a human timezone is a separate, explicit concern handled by
 * {@see DisplayTime} (the `@dt` / `@ago` Blade directives).
 *
 * @implements CastsAttributes<Carbon|null, Carbon|\DateTimeInterface|string|null>
 */
class UtcDateTime implements CastsAttributes
{
    /**
     * Read: interpret the stored digits as a UTC instant.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value, 'UTC');
    }

    /**
     * Write: normalize any incoming value to UTC and store its UTC digits.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $carbon = $value instanceof \DateTimeInterface
            ? Carbon::instance($value)
            : Carbon::parse($value);

        return $carbon->clone()->setTimezone('UTC')->format('Y-m-d H:i:s');
    }
}
