<?php

namespace App\Services\Activity;

use App\Models\Employee;
use App\Services\Device\DeviceStatusService;
use Illuminate\Support\Carbon;

/**
 * Read-side of activity tracking. Computes active/idle time and status for an
 * employee over a day or a date range, reading only from the accumulated
 * `activity_logs` — never from raw per-sample data.
 */
class ActivitySummaryService
{
    public function __construct(private readonly DeviceStatusService $status) {}

    /**
     * Daily summary for an employee: active/idle totals, active ratio, live
     * status, and last-activity timestamp.
     */
    public function dailySummary(Employee $employee, Carbon|string|null $date = null): array
    {
        $date = $date ? Carbon::parse($date) : today();

        $totals = $employee->activityLogs()
            ->whereDate('work_date', $date)
            ->selectRaw('COALESCE(SUM(active_seconds),0) as active, COALESCE(SUM(idle_seconds),0) as idle')
            ->first();

        $active = (int) ($totals->active ?? 0);
        $idle = (int) ($totals->idle ?? 0);

        return [
            'employee_id' => $employee->id,
            'date' => $date->toDateString(),
            'active_seconds' => $active,
            'idle_seconds' => $idle,
            'active_hours' => round($active / 3600, 2),
            'idle_hours' => round($idle / 3600, 2),
            'active_ratio' => $this->ratio($active, $idle),
            'status' => $this->status->employeeStatus($employee)->value,
            'is_online' => $this->status->employeeIsOnline($employee),
            'last_activity_at' => optional($this->status->lastActivityAt($employee))->toIso8601String(),
        ];
    }

    /**
     * Per-day totals across an inclusive date range, keyed by date — handy for
     * charts. One grouped query, no N+1.
     */
    public function rangeByDay(Employee $employee, Carbon|string $from, Carbon|string $to): array
    {
        return $employee->activityLogs()
            ->whereBetween('work_date', [Carbon::parse($from), Carbon::parse($to)])
            ->groupBy('work_date')
            ->orderBy('work_date')
            ->selectRaw('work_date, SUM(active_seconds) as active_seconds, SUM(idle_seconds) as idle_seconds')
            ->get()
            ->map(fn ($row) => [
                'date' => Carbon::parse($row->work_date)->toDateString(),
                'active_seconds' => (int) $row->active_seconds,
                'idle_seconds' => (int) $row->idle_seconds,
                'active_ratio' => $this->ratio((int) $row->active_seconds, (int) $row->idle_seconds),
            ])
            ->all();
    }

    /** Active time as a percentage of tracked (active + idle) time. */
    private function ratio(int $active, int $idle): float
    {
        $total = $active + $idle;

        return $total > 0 ? round($active / $total * 100, 1) : 0.0;
    }
}
