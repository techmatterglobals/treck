<?php

namespace App\Services\Reporting;

use App\DataObjects\ReportFilter;
use App\Enums\ReportPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds productivity/attendance report rows from the accumulated
 * `activity_logs`, aggregated per employee per period bucket (day / ISO week /
 * month) with employee + department filters and a date range.
 *
 * "Productivity" is the active-ratio proxy; repoint the SUM source at
 * productivity_reports once that rollup exists without changing the shape.
 */
class ReportService
{
    public function build(ReportFilter $filter): Collection
    {
        $bucket = $this->bucketExpression($filter->period);

        return DB::table('activity_logs')
            ->join('employees', 'employees.id', '=', 'activity_logs.employee_id')
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
            ->whereBetween('activity_logs.work_date', [
                $filter->from->toDateString(),
                $filter->to->toDateString(),
            ])
            ->when($filter->employeeId, fn ($q) => $q->where('activity_logs.employee_id', $filter->employeeId))
            ->when($filter->departmentId, fn ($q) => $q->where('employees.department_id', $filter->departmentId))
            ->groupBy(
                'activity_logs.employee_id',
                'users.name',
                'employees.employee_code',
                'departments.name',
                DB::raw($bucket),
            )
            ->orderBy('users.name')
            ->orderByRaw('MIN(activity_logs.work_date)')
            ->selectRaw("
                activity_logs.employee_id,
                users.name as employee_name,
                employees.employee_code,
                departments.name as department,
                {$bucket} as period_key,
                MIN(activity_logs.work_date) as period_start,
                SUM(activity_logs.active_seconds) as active_seconds,
                SUM(activity_logs.idle_seconds) as idle_seconds,
                COUNT(*) as sessions,
                COUNT(DISTINCT activity_logs.work_date) as days_present
            ")
            ->get()
            ->map(fn ($row) => $this->transform($row, $filter->period));
    }

    /** Totals across all report rows, for the summary line. */
    public function totals(Collection $rows): array
    {
        $active = (int) $rows->sum('active_seconds');
        $idle = (int) $rows->sum('idle_seconds');

        return [
            'active_seconds' => $active,
            'idle_seconds' => $idle,
            'active_hours' => round($active / 3600, 2),
            'idle_hours' => round($idle / 3600, 2),
            'active_ratio' => ($active + $idle) > 0 ? round($active / ($active + $idle) * 100, 1) : 0.0,
            'rows' => $rows->count(),
        ];
    }

    private function bucketExpression(ReportPeriod $period): string
    {
        return match ($period) {
            ReportPeriod::Daily => "DATE_FORMAT(activity_logs.work_date, '%Y-%m-%d')",
            ReportPeriod::Weekly => "DATE_FORMAT(activity_logs.work_date, '%x-W%v')",
            ReportPeriod::Monthly => "DATE_FORMAT(activity_logs.work_date, '%Y-%m')",
        };
    }

    private function transform(object $row, ReportPeriod $period): array
    {
        $active = (int) $row->active_seconds;
        $idle = (int) $row->idle_seconds;
        $start = Carbon::parse($row->period_start);

        return [
            'employee_id' => (int) $row->employee_id,
            'employee_name' => $row->employee_name,
            'employee_code' => $row->employee_code,
            'department' => $row->department,
            'period_label' => $this->periodLabel($period, $row->period_key, $start),
            'period_key' => $row->period_key,
            'active_seconds' => $active,
            'idle_seconds' => $idle,
            'active_hours' => round($active / 3600, 2),
            'idle_hours' => round($idle / 3600, 2),
            'active_ratio' => ($active + $idle) > 0 ? round($active / ($active + $idle) * 100, 1) : 0.0,
            'sessions' => (int) $row->sessions,
            'days_present' => (int) $row->days_present,
        ];
    }

    private function periodLabel(ReportPeriod $period, string $key, Carbon $start): string
    {
        return match ($period) {
            ReportPeriod::Daily => $start->format('d M Y'),
            ReportPeriod::Weekly => 'Week of '.$start->startOfWeek()->format('d M Y'),
            ReportPeriod::Monthly => $start->format('F Y'),
        };
    }
}
