<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * Derives the daily `attendance` row for every employee from that day's
 * activity_logs: first-in / last-out, work/active/idle seconds, and status
 * (present / late / half-day / absent). Never overwrites a manual correction.
 */
class AttendanceService
{
    public function deriveDaily(Carbon|string|null $date = null): int
    {
        $date = $date ? Carbon::parse($date) : today();
        $dateStr = $date->toDateString();

        $sessions = ActivityLog::whereDate('work_date', $dateStr)
            ->selectRaw('employee_id,
                MIN(login_at)  as first_in,
                MAX(logout_at) as last_out,
                SUM(active_seconds) as active,
                SUM(idle_seconds)   as idle')
            ->groupBy('employee_id')
            ->get()
            ->keyBy('employee_id');

        $processed = 0;

        foreach (Employee::all() as $employee) {
            $attendance = Attendance::firstOrNew([
                'employee_id' => $employee->id,
                'work_date' => $dateStr,
            ]);

            // Respect manual corrections.
            if ($attendance->is_corrected) {
                continue;
            }

            $row = $sessions->get($employee->id);

            if ($row === null) {
                $attendance->fill([
                    'first_in_at' => null,
                    'last_out_at' => null,
                    'work_seconds' => 0,
                    'active_seconds' => 0,
                    'idle_seconds' => 0,
                    'status' => AttendanceStatus::Absent,
                ])->save();
                $processed++;

                continue;
            }

            $active = (int) $row->active;
            $idle = (int) $row->idle;
            $work = $active + $idle;
            $firstIn = Carbon::parse($row->first_in);

            $attendance->fill([
                'first_in_at' => $firstIn,
                'last_out_at' => $row->last_out ? Carbon::parse($row->last_out) : null,
                'work_seconds' => $work,
                'active_seconds' => $active,
                'idle_seconds' => $idle,
                'status' => $this->classify($firstIn, $work, $date),
            ])->save();

            $processed++;
        }

        return $processed;
    }

    private function classify(Carbon $firstIn, int $workSeconds, Carbon $date): AttendanceStatus
    {
        $start = Carbon::parse($date->toDateString().' '.config('treck.attendance.workday_start', '09:00'));
        $grace = (int) config('treck.attendance.late_grace_minutes', 15);
        $fullDaySeconds = (int) config('treck.attendance.full_day_hours', 8) * 3600;

        if ($workSeconds < intdiv($fullDaySeconds, 2)) {
            return AttendanceStatus::HalfDay;
        }

        if ($firstIn->gt($start->copy()->addMinutes($grace))) {
            return AttendanceStatus::Late;
        }

        return AttendanceStatus::Present;
    }
}
