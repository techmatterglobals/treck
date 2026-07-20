<?php

namespace App\Services\Reporting;

use App\DataObjects\AppUsageFilter;
use App\Models\ApplicationUsage;
use App\Models\Computer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read model + reporting for application usage (Phase 7). All queries run over
 * the materialized `application_usage` rows (one per completed session) with
 * indexes on (employee_id,used_at), (computer_id,used_at) and
 * (application_name,used_at) - never over raw agent_events. Aggregates scale
 * with the number of sessions, not events.
 */
class ApplicationUsageService
{
    // ---- Filtered base query ----------------------------------------------

    /** Base query with all filters applied (joins employees only for department). */
    public function query(AppUsageFilter $filter): Builder
    {
        return ApplicationUsage::query()
            ->between($filter->from, $filter->to)
            ->when($filter->employeeId, fn (Builder $q) => $q->forEmployee($filter->employeeId))
            ->when($filter->computerId, fn (Builder $q) => $q->forComputer($filter->computerId))
            ->when($filter->application, fn (Builder $q) => $q->matchingApplication($filter->application))
            ->when($filter->departmentId, fn (Builder $q) => $q->whereHas(
                'employee',
                fn (Builder $e) => $e->where('department_id', $filter->departmentId),
            ));
    }

    // ---- Summary / reporting ----------------------------------------------

    /**
     * Headline totals for the range.
     *
     * @return array{total_seconds:int,total_label:string,sessions:int,applications:int}
     */
    public function summary(AppUsageFilter $filter): array
    {
        $row = $this->query($filter)
            ->selectRaw('COALESCE(SUM(duration_seconds),0) secs, COUNT(*) sessions, COUNT(DISTINCT application_name) apps')
            ->first();

        return [
            'total_seconds' => (int) $row->secs,
            'total_label' => $this->hoursMinutes((int) $row->secs),
            'sessions' => (int) $row->sessions,
            'applications' => (int) $row->apps,
        ];
    }

    /** Most-used applications by total time. @return Collection<int,array<string,mixed>> */
    public function topApplications(AppUsageFilter $filter, int $limit = 10): Collection
    {
        return $this->query($filter)
            ->groupBy('application_name')
            ->selectRaw('application_name, SUM(duration_seconds) secs, COUNT(*) sessions')
            ->orderByDesc('secs')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'application' => $r->application_name,
                'seconds' => (int) $r->secs,
                'label' => $this->hoursMinutes((int) $r->secs),
                'sessions' => (int) $r->sessions,
            ]);
    }

    /** Per-day totals across the range (for a daily timeline). */
    public function dailyUsage(AppUsageFilter $filter): Collection
    {
        return $this->query($filter)
            ->selectRaw($this->dateExpr('used_at').' as day, SUM(duration_seconds) secs')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($r) => [
                'day' => $r->day,
                'seconds' => (int) $r->secs,
                'label' => $this->hoursMinutes((int) $r->secs),
            ]);
    }

    /** Total time per employee. */
    public function perEmployee(AppUsageFilter $filter): Collection
    {
        return $this->query($filter)
            ->join('employees', 'employees.id', '=', 'application_usage.employee_id')
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->groupBy('application_usage.employee_id', 'users.name')
            ->selectRaw('users.name as employee, SUM(duration_seconds) secs')
            ->orderByDesc('secs')
            ->get()
            ->map(fn ($r) => ['employee' => $r->employee, 'seconds' => (int) $r->secs, 'label' => $this->hoursMinutes((int) $r->secs)]);
    }

    /** Total time per department. */
    public function perDepartment(AppUsageFilter $filter): Collection
    {
        return $this->query($filter)
            ->join('employees', 'employees.id', '=', 'application_usage.employee_id')
            ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
            ->groupBy('departments.name')
            ->selectRaw('departments.name as department, SUM(duration_seconds) secs')
            ->orderByDesc('secs')
            ->get()
            ->map(fn ($r) => ['department' => $r->department ?? 'Unassigned', 'seconds' => (int) $r->secs, 'label' => $this->hoursMinutes((int) $r->secs)]);
    }

    /** Recent completed sessions (paginated), newest first. */
    public function recent(AppUsageFilter $filter, int $perPage = 50): LengthAwarePaginator
    {
        return $this->query($filter)
            ->with(['employee.user', 'computer'])
            ->orderByDesc('used_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    // ---- Computer details page --------------------------------------------

    /** The most recent (i.e. current) application session on a computer. */
    public function currentApplication(Computer $computer): ?ApplicationUsage
    {
        return ApplicationUsage::forComputer($computer->id)->latest('used_at')->first();
    }

    /** Recent application history for a computer. */
    public function recentForComputer(Computer $computer, int $limit = 15): Collection
    {
        return ApplicationUsage::forComputer($computer->id)->latest('used_at')->limit($limit)->get();
    }

    /** Today's top applications for a computer. @return Collection<int,array<string,mixed>> */
    public function dailySummaryForComputer(Computer $computer, ?int $limit = 8): Collection
    {
        return ApplicationUsage::forComputer($computer->id)
            ->forDate(today())
            ->groupBy('application_name')
            ->selectRaw('application_name, SUM(duration_seconds) secs')
            ->orderByDesc('secs')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => ['application' => $r->application_name, 'seconds' => (int) $r->secs, 'label' => $this->hoursMinutes((int) $r->secs)]);
    }

    // ---- Helpers ----------------------------------------------------------

    /** Driver-aware date bucket (SQLite strftime / MySQL DATE_FORMAT). */
    private function dateExpr(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d', {$column})"
            : "DATE_FORMAT({$column}, '%Y-%m-%d')";
    }

    private function hoursMinutes(int $seconds): string
    {
        return sprintf('%dh %02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
