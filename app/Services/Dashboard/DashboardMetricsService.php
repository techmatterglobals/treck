<?php

namespace App\Services\Dashboard;

use App\Enums\ComputerStatus;
use App\Models\ActivityLog;
use App\Models\Computer;
use App\Models\Department;
use App\Models\Employee;
use App\Services\Device\DeviceStatusService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Central source for all admin-dashboard metrics. Keeps query logic out of the
 * Livewire components so they stay thin and the numbers are computed one way.
 *
 * Productivity here uses the "active ratio" proxy (active / active+idle) from
 * activity_logs; once the productivity_reports rollup lands it can read those
 * pre-aggregated rows instead without changing the component contracts.
 */
class DashboardMetricsService
{
    public function __construct(private readonly DeviceStatusService $deviceStatus)
    {
    }

    // ---- Cards -------------------------------------------------------------

    public function totalEmployees(): int
    {
        return Employee::count();
    }

    /** Distinct employees with at least one currently-connected computer. */
    public function onlineEmployees(): int
    {
        $cutoff = now()->subSeconds($this->graceSeconds());

        return (int) Computer::query()
            ->whereNotNull('employee_id')
            ->where('status', '!=', ComputerStatus::Offline->value)
            ->where('last_seen_at', '>=', $cutoff)
            ->distinct()
            ->count('employee_id');
    }

    /** Present today = distinct employees with a session today. */
    public function todaysAttendance(): array
    {
        $present = (int) ActivityLog::whereDate('work_date', today())
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

        $row = ActivityLog::whereDate('work_date', $date)
            ->selectRaw('COALESCE(SUM(active_seconds),0) a, COALESCE(SUM(idle_seconds),0) i')
            ->first();

        return $this->ratio((int) $row->a, (int) $row->i);
    }

    // ---- Tables ------------------------------------------------------------

    /** Per-employee status + today's active/idle time. */
    public function employeeStatusRows(): Collection
    {
        return Employee::query()
            ->with(['user', 'department', 'computers'])
            ->withSum(
                ['activityLogs as today_active' => fn ($q) => $q->whereDate('work_date', today())],
                'active_seconds',
            )
            ->withSum(
                ['activityLogs as today_idle' => fn ($q) => $q->whereDate('work_date', today())],
                'idle_seconds',
            )
            ->orderBy('id')
            ->get()
            ->map(function (Employee $employee) {
                $active = (int) ($employee->today_active ?? 0);
                $idle = (int) ($employee->today_idle ?? 0);

                return [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'department' => $employee->department?->name,
                    'status' => $this->deviceStatus->employeeStatus($employee),
                    'active_seconds' => $active,
                    'idle_seconds' => $idle,
                    'active_label' => $this->hoursMinutes($active),
                    'idle_label' => $this->hoursMinutes($idle),
                    'active_ratio' => $this->ratio($active, $idle),
                    'last_activity_at' => $this->deviceStatus->lastActivityAt($employee),
                ];
            });
    }

    // ---- Charts ------------------------------------------------------------

    /** Daily company-wide active ratio for the last N days (gap-filled). */
    public function dailyProductivity(int $days = 14): array
    {
        $from = today()->subDays($days - 1);

        $rows = ActivityLog::whereBetween('work_date', [$from, today()])
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

        $grouped = DB::table('activity_logs')
            ->join('employees', 'employees.id', '=', 'activity_logs.employee_id')
            ->whereDate('activity_logs.work_date', $date)
            ->groupBy('employees.department_id')
            ->selectRaw('employees.department_id, SUM(active_seconds) a, SUM(idle_seconds) i')
            ->get()
            ->keyBy('department_id');

        return Department::orderBy('name')->get()->map(function (Department $dept) use ($grouped) {
            $row = $grouped->get($dept->id);

            return [
                'department' => $dept->name,
                'ratio' => $this->ratio((int) ($row->a ?? 0), (int) ($row->i ?? 0)),
            ];
        })->all();
    }

    // ---- Helpers -----------------------------------------------------------

    private function ratio(int $active, int $idle): float
    {
        $total = $active + $idle;

        return $total > 0 ? round($active / $total * 100, 1) : 0.0;
    }

    private function hoursMinutes(int $seconds): string
    {
        return sprintf('%dh %02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    private function graceSeconds(): int
    {
        return (int) config('treck.activity.offline_grace_seconds', 180);
    }
}
