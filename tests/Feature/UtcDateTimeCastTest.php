<?php

namespace Tests\Feature;

use App\Casts\UtcDateTime;
use App\Models\ComputerPresence;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Proves the "5 hours ago" presence bug and that {@see UtcDateTime} fixes it.
 *
 * The agent stores instants as UTC wall-clock digits. On a server whose
 * APP_TIMEZONE is Asia/Karachi (+05:00), the built-in 'datetime' cast decodes
 * those digits *in Karachi*, producing an instant 5h before the real event, so
 * diffForHumans() reports "5 hours ago" for something that just happened. The
 * UtcDateTime cast decodes the digits as UTC, restoring the true instant —
 * without changing APP_TIMEZONE.
 */
class UtcDateTimeCastTest extends TestCase
{
    private string $originalTz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTz = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        // Restore the PHP default timezone the app boots with so we never leak
        // Asia/Karachi into sibling tests.
        date_default_timezone_set($this->originalTz);
        parent::tearDown();
    }

    /**
     * Reproduce the bug: the built-in 'datetime' cast reading UTC digits under
     * a +05:00 app timezone yields an instant 5 hours in the past.
     */
    public function test_builtin_datetime_cast_misreads_utc_digits_under_karachi(): void
    {
        // Simulate the live server: APP_TIMEZONE=Asia/Karachi.
        config(['app.timezone' => 'Asia/Karachi']);
        date_default_timezone_set('Asia/Karachi');

        // A heartbeat that happened "now" in UTC, stored as UTC digits.
        $utcNow = Carbon::now('UTC');
        $storedDigits = $utcNow->format('Y-m-d H:i:s');

        // What Laravel's default 'datetime' cast does: createFromFormat in the
        // default (Karachi) timezone — no UTC awareness.
        $misread = Carbon::createFromFormat('Y-m-d H:i:s', $storedDigits);

        // It lands ~5 hours before the real instant.
        $this->assertEqualsWithDelta(
            5 * 3600,
            $misread->diffInSeconds($utcNow),
            5,
            'The default datetime cast should be ~5h off under Asia/Karachi.',
        );
    }

    /**
     * The fix: UtcDateTime reads the same digits as UTC, so the instant is
     * exact and diffForHumans() reads "just now" rather than "5 hours ago".
     */
    public function test_utc_cast_reads_utc_digits_as_the_correct_instant(): void
    {
        config(['app.timezone' => 'Asia/Karachi']);
        date_default_timezone_set('Asia/Karachi');

        $utcNow = Carbon::now('UTC');
        $storedDigits = $utcNow->format('Y-m-d H:i:s');

        $cast = new UtcDateTime;
        $decoded = $cast->get(new ComputerPresence, 'last_activity_at', $storedDigits, []);

        $this->assertSame('UTC', $decoded->timezoneName);
        $this->assertLessThanOrEqual(1, abs($decoded->diffInSeconds($utcNow)));
        // The real payoff: relative rendering is fresh, not "5 hours ago".
        $this->assertLessThan(60, abs($decoded->diffInSeconds(now())));
    }

    /**
     * End-to-end through the model: a presence row whose column holds UTC digits
     * reports a fresh "seconds ago" under Asia/Karachi, not "5 hours ago".
     */
    public function test_presence_last_activity_is_fresh_through_the_model(): void
    {
        config(['app.timezone' => 'Asia/Karachi']);
        date_default_timezone_set('Asia/Karachi');

        $presence = new ComputerPresence;
        // Assign a Carbon "now" (UTC); the cast's set() normalizes to UTC digits.
        $presence->last_activity_at = Carbon::now('UTC');

        // The raw stored attribute must be UTC digits (no +05:00 shift).
        $this->assertSame(
            Carbon::now('UTC')->format('Y-m-d H:i'),
            substr($presence->getAttributes()['last_activity_at'], 0, 16),
        );

        // And reading it back yields the true instant → seconds, not hours.
        $this->assertLessThan(60, abs($presence->last_activity_at->diffInSeconds(now())));
    }

    /**
     * set() normalizes a non-UTC Carbon to UTC digits so writes are always
     * stored in the canonical timezone regardless of the input's zone.
     */
    public function test_set_normalizes_any_timezone_to_utc_digits(): void
    {
        $cast = new UtcDateTime;

        // 18:19 Karachi is 13:19 UTC.
        $karachi = Carbon::parse('2026-08-03 18:19:00', 'Asia/Karachi');

        $this->assertSame(
            '2026-08-03 13:19:00',
            $cast->set(new ComputerPresence, 'last_activity_at', $karachi, []),
        );
    }

    public function test_null_round_trips_as_null(): void
    {
        $cast = new UtcDateTime;

        $this->assertNull($cast->get(new ComputerPresence, 'last_activity_at', null, []));
        $this->assertNull($cast->set(new ComputerPresence, 'last_activity_at', null, []));
    }
}
