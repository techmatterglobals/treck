<?php

namespace App\Jobs;

use App\Services\Attendance\AttendanceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued daily attendance rollup for a given date (defaults to today).
 */
class RollUpDailyAttendance implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ?string $date = null)
    {
    }

    public function handle(AttendanceService $service): void
    {
        $service->deriveDaily($this->date);
    }
}
