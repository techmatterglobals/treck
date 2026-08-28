<?php

namespace Tests\Feature;

use App\Support\DisplayTime;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Timestamps are stored/computed in UTC and converted to the display timezone
 * only for rendering. These lock that behavior for the @dt / @ago directives.
 */
class DisplayTimeTest extends TestCase
{
    public function test_absolute_time_is_converted_to_the_display_timezone(): void
    {
        config(['treck.display_timezone' => 'Asia/Karachi']);

        // 13:19 UTC should render as 18:19 in Karachi (+05:00).
        $utc = Carbon::parse('2026-08-03 13:19:00', 'UTC');

        $this->assertSame('2026-08-03 18:19', DisplayTime::format($utc, 'Y-m-d H:i'));
    }

    public function test_relative_time_is_instant_correct_regardless_of_display_tz(): void
    {
        config(['treck.display_timezone' => 'Asia/Karachi']);

        // A UTC instant 3 minutes ago must read "3 minutes ago", never "5 hours".
        $threeMinAgo = Carbon::now('UTC')->subMinutes(3);

        $this->assertSame('3 minutes ago', DisplayTime::ago($threeMinAgo));
    }

    public function test_null_is_rendered_as_an_em_dash(): void
    {
        $this->assertSame('—', DisplayTime::format(null));
        $this->assertSame('—', DisplayTime::ago(null));
    }

    public function test_utc_display_timezone_is_a_no_op(): void
    {
        config(['treck.display_timezone' => 'UTC']);

        $utc = Carbon::parse('2026-08-03 13:19:00', 'UTC');

        $this->assertSame('2026-08-03 13:19', DisplayTime::format($utc, 'Y-m-d H:i'));
    }
}
