<?php

namespace App\Services\Reporting;

use App\DataObjects\ReportFilter;
use App\Enums\ReportPeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
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
    /** Full result set (used by exports). */
    public function build(ReportFilter $filter): Collection
    {
        return $this->reportQuery($filter)
            ->get()
            ->map(fn ($row) => $this->transform($row, $filter->period));
    }

    /**
     * Computer Usage History (Phase 11): each PC session across the range, in
     * time order, showing which employee used the machine and when — the
     * shared-computer shift timeline (e.g. 08:00 Hassan → 12:00 Zain → 17:00
     * Hassan). Honors the filter's date range, optional employee, and the
     * manager/employee visibility restriction (employeeIds). Reads indexed
     * `activity_logs` — never a scan of raw events.
     *
     * @return Collection<int,object>
     */
    public function computerUsageHistory(ReportFilter $filter): Collection
    {
        return DB::table('activity_logs')
            ->join('computers', 'computers.id', '=', 'activity_logs.computer_id')
            ->join('employees', 'employees.id', '=', 'activity_logs.employee_id')
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->when($filter->organizationId !== null, fn ($q) => $q->where('activity_logs.organization_id', $filter->organizationId))
            ->whereBetween('activity_logs.login_at', [
                $filter->from->startOfDay()->toDateTimeString(),
                $filter->to->endOfDay()->toDateTimeString(),
            ])
            ->when($filter->employeeId, fn ($q) => $q->where('activity_logs.employee_id', $filter->employeeId))
            ->when($filter->employeeIds !== null, fn ($q) => $q->whereIn('activity_logs.employee_id', $filter->employeeIds ?: [0]))
            ->orderBy('computers.hostname')
            ->orderBy('activity_logs.login_at')
            ->get([
                'computers.hostname as computer',
                'users.name as employee',
                'employees.employee_code',
                'activity_logs.login_at',
                'activity_logs.logout_at',
            ]);
    }

    /**
     * Paginated result set for the index page (default 50/page). Filters and the
     * fixed sort are preserved: the query carries the where/order clauses and
     * withQueryString() appends the current filters to the page links.
     */
    public function paginate(ReportFilter $filter, int $perPage = 50): LengthAwarePaginator
    {
        $paginator = $this->reportQuery($filter)->paginate($perPage)->withQueryString();

        $paginator->getCollection()->transform(fn ($row) => $this->transform($row, $filter->period));

        return $paginator;
    }

    /** The grouped/ordered report query, shared by build() and paginate(). */
    private function reportQuery(ReportFilter $filter): Builder
    {
        $bucket = $this->bucketExpression($filter->period);

        return DB::table('activity_logs')
            ->join('employees', 'employees.id', '=', 'activity_logs.employee_id')
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
            ->when($filter->organizationId !== null, fn ($q) => $q->where('activity_logs.organization_id', $filter->organizationId))
            // Day-bounded datetimes so the range is inclusive whether work_date is
            // stored as a pure date (MySQL DATE) or a datetime (SQLite).
            ->whereBetween('activity_logs.work_date', [
                $filter->from->startOfDay()->toDateTimeString(),
                $filter->to->endOfDay()->toDateTimeString(),
            ])
            ->when($filter->employeeId, fn ($q) => $q->where('activity_logs.employee_id', $filter->employeeId))
            ->when($filter->departmentId, fn ($q) => $q->where('employees.department_id', $filter->departmentId))
            ->when($filter->managerUserId, fn ($q) => $q->where('employees.manager_user_id', $filter->managerUserId))
            // Manager/employee visibility restriction (Phase 11); null = unrestricted.
            ->when($filter->employeeIds !== null, fn ($q) => $q->whereIn('activity_logs.employee_id', $filter->employeeIds ?: [0]))
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
            ");
    }

    /**
     * Totals across the FULL filtered range (not just the current page), for the
     * summary line on the paginated index. `$rowCount` is the paginator total.
     */
    public function totalsFor(ReportFilter $filter, int $rowCount): array
    {
        $agg = DB::table('activity_logs')
            ->join('employees', 'employees.id', '=', 'activity_logs.employee_id')
            ->when($filter->organizationId !== null, fn ($q) => $q->where('activity_logs.organization_id', $filter->organizationId))
            ->whereBetween('activity_logs.work_date', [
                $filter->from->startOfDay()->toDateTimeString(),
                $filter->to->endOfDay()->toDateTimeString(),
            ])
            ->when($filter->employeeId, fn ($q) => $q->where('activity_logs.employee_id', $filter->employeeId))
            ->when($filter->departmentId, fn ($q) => $q->where('employees.department_id', $filter->departmentId))
            ->when($filter->managerUserId, fn ($q) => $q->where('employees.manager_user_id', $filter->managerUserId))
            ->when($filter->employeeIds !== null, fn ($q) => $q->whereIn('activity_logs.employee_id', $filter->employeeIds ?: [0]))
            ->selectRaw('COALESCE(SUM(active_seconds),0) a, COALESCE(SUM(idle_seconds),0) i')
            ->first();

        $active = (int) $agg->a;
        $idle = (int) $agg->i;

        return [
            'active_seconds' => $active,
            'idle_seconds' => $idle,
            'active_hours' => round($active / 3600, 2),
            'idle_hours' => round($idle / 3600, 2),
            'active_ratio' => ($active + $idle) > 0 ? round($active / ($active + $idle) * 100, 1) : 0.0,
            'rows' => $rowCount,
        ];
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
        $col = 'activity_logs.work_date';

        // Driver-aware: MySQL uses DATE_FORMAT, SQLite uses strftime. Each format
        // is internally consistent for grouping within its own database.
        if (DB::connection()->getDriverName() === 'sqlite') {
            return match ($period) {
                ReportPeriod::Daily => "strftime('%Y-%m-%d', {$col})",
                ReportPeriod::Weekly => "strftime('%Y-W%W', {$col})",
                ReportPeriod::Monthly => "strftime('%Y-%m', {$col})",
            };
        }

        return match ($period) {
            ReportPeriod::Daily => "DATE_FORMAT({$col}, '%Y-%m-%d')",
            ReportPeriod::Weekly => "DATE_FORMAT({$col}, '%x-W%v')",
            ReportPeriod::Monthly => "DATE_FORMAT({$col}, '%Y-%m')",
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
