<?php

namespace App\Services\Dashboard;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Employee;
use App\Services\Presence\PresenceService;
use App\Services\Tenancy\MonitoringTenantAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Central source for all admin-dashboard metrics. Keeps query logic out of the
 * Livewire components so they stay thin and the numbers are computed one way.
 *
 * Live status/online/last-activity come from {@see PresenceService} - the single
 * source of truth shared with the presence board and employees page - so the
 * dashboard can never disagree with the presence page. Productivity/attendance
 * still aggregate activity_logs.
 */
class DashboardMetricsService
{
    public function __construct(
        private readonly PresenceService $presence,
        private readonly MonitoringTenantAccess $tenant,
    ) {}

    // ---- Cards -------------------------------------------------------------

    public function totalEmployees(): int
    {
        [$organizationId, $employeeIds] = $this->tenantContext();

        return Employee::query()
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('id', $employeeIds ?: [0]))
            ->count();
    }

    /**
     * Distinct employees with at least one online computer (Active/Idle/Locked),
     * from the shared presence source - identical rule to the presence board.
     */
    public function onlineEmployees(): int
    {
        [$organizationId] = $this->tenantContext();

        return $this->presence->onlineEmployeeCount($organizationId);
    }

    /** Present today = distinct employees with a session today. */
    public function todaysAttendance(): array
    {
        [$organizationId, $employeeIds] = $this->tenantContext();

        $present = (int) ActivityLog::whereDate('work_date', today())
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', $employeeIds ?: [0]))
            ->distinct()
            ->count('employee_id');

        $total = $this->totalEmployees();

        return [
            'present' => $present,
            'total' => $total,
            'percent' => $total > 0 ? round($present / $total * 100, 1) : 0.0,
        ];
    }

    /** Company-wide active ratio for the day, as a percentage. */
    public function averageProductivity(Carbon|string|null $date = null): float
    {
        $date = $date ? Carbon::parse($date) : today();

        [$organizationId, $employeeIds] = $this->tenantContext();

        $row = ActivityLog::whereDate('work_date', $date)
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', $employeeIds ?: [0]))
            ->selectRaw('COALESCE(SUM(active_seconds),0) a, COALESCE(SUM(idle_seconds),0) i')
            ->first();

        return $this->ratio((int) $row->a, (int) $row->i);
    }

    // ---- Tables ------------------------------------------------------------

    /**
     * Per-employee live status + today's active/idle time. Delegated to the
     * shared presence read model so status/last-activity match the presence
     * board, and active/idle time is summed from today's heartbeat events
     * (the agent no longer writes activity_logs).
     */
    public function employeeStatusRows(): Collection
    {
        [$organizationId, $employeeIds] = $this->tenantContext();

        return $this->presence->employeeRows($organizationId, $employeeIds);
    }

    // ---- Charts ------------------------------------------------------------

    /** Daily company-wide active ratio for the last N days (gap-filled). */
    public function dailyProductivity(int $days = 14): array
    {
        $from = today()->subDays($days - 1);

        [$organizationId, $employeeIds] = $this->tenantContext();

        $rows = ActivityLog::whereBetween('work_date', [$from, today()])
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', $employeeIds ?: [0]))
            ->groupBy('work_date')
            ->selectRaw('work_date, SUM(active_seconds) a, SUM(idle_seconds) i')
            ->get()
            ->keyBy(fn ($r) => Carbon::parse($r->work_date)->toDateString());

        $series = [];
        for ($day = $from->copy(); $day->lte(today()); $day->addDay()) {
            $row = $rows->get($day->toDateString());
            $series[] = [
                'date' => $day->toDateString(),
                'label' => $day->format('d M'),
                'ratio' => $this->ratio((int) ($row->a ?? 0), (int) ($row->i ?? 0)),
            ];
        }

        return $series;
    }

    /** Average active ratio per department for the day. */
    public function departmentPerformance(Carbon|string|null $date = null): array
    {
        $date = $date ? Carbon::parse($date) : today();

        [$organizationId, $employeeIds] = $this->tenantContext();

        $grouped = DB::table('activity_logs')
            ->join('employees', 'employees.id', '=', 'activity_logs.employee_id')
            ->when($organizationId !== null, fn ($query) => $query
                ->where('activity_logs.organization_id', $organizationId)
                ->where('employees.organization_id', $organizationId))
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employees.id', $employeeIds ?: [0]))
            ->whereDate('activity_logs.work_date', $date)
            ->groupBy('employees.department_id')
            ->selectRaw('employees.department_id, SUM(active_seconds) a, SUM(idle_seconds) i')
            ->get()
            ->keyBy('department_id');

        return Department::query()
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->orderBy('name')
            ->get()
            ->map(function (Department $dept) use ($grouped) {
                $row = $grouped->get($dept->id);

                return [
                    'department' => $dept->name,
                    'ratio' => $this->ratio((int) ($row->a ?? 0), (int) ($row->i ?? 0)),
                ];
            })->all();
    }

    // ---- Helpers -----------------------------------------------------------

    /**
     * @return array{0:int|null,1:list<int>|null}
     */
    private function tenantContext(): array
    {
        $user = auth()->user();

        if (! $user) {
            return [null, null];
        }

        try {
            if (! $this->tenant->canViewMonitoring($user)) {
                return [null, []];
            }

            return [$this->tenant->organizationId($user), $this->tenant->visibleEmployeeIds($user)];
        } catch (Throwable) {
            return [null, []];
        }
    }

    private function ratio(int $active, int $idle): float
    {
        $total = $active + $idle;

        return $total > 0 ? round($active / $total * 100, 1) : 0.0;
    }
}
