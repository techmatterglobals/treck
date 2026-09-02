<?php

namespace App\Services\Desktop;

use App\Enums\AgentEventKind;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\Presence\PresenceService;
use App\Services\Tenancy\MonitoringTenantAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DesktopOverviewService
{
    public function __construct(
        private readonly MonitoringTenantAccess $tenant,
        private readonly PresenceService $presence,
    ) {}

    /** @return array<string,mixed> */
    public function forUser(User $user, int $organizationId): array
    {
        $employeeIds = $this->tenant->visibleEmployeeIds($user);
        $computerIds = $this->tenant->visibleComputerIds($user);

        $totalEmployees = Employee::query()
            ->where('organization_id', $organizationId)
            ->when($employeeIds !== null, fn (Builder $query) => $query->whereIn('id', $employeeIds ?: [0]))
            ->count();

        $present = ActivityLog::query()
            ->where('organization_id', $organizationId)
            ->whereDate('work_date', today())
            ->when($employeeIds !== null, fn (Builder $query) => $query->whereIn('employee_id', $employeeIds ?: [0]))
            ->distinct()
            ->count('employee_id');

        $activity = DB::table('agent_events')
            ->where('organization_id', $organizationId)
            ->where('kind', AgentEventKind::Heartbeat->value)
            ->whereDate('occurred_at', today())
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', $employeeIds ?: [0]))
            ->selectRaw("COALESCE(SUM(json_extract(payload, '$.ActiveSeconds')), 0) as active")
            ->selectRaw("COALESCE(SUM(json_extract(payload, '$.IdleSeconds')), 0) as idle")
            ->first();

        $active = (int) ($activity->active ?? 0);
        $idle = (int) ($activity->idle ?? 0);
        $tracked = $active + $idle;

        return [
            'date' => today()->toDateString(),
            'employees' => [
                'total' => $totalEmployees,
                'present' => $present,
                'attendance_percent' => $totalEmployees > 0
                    ? round($present / $totalEmployees * 100, 1)
                    : 0.0,
            ],
            'presence' => $this->presence->summary($computerIds, $organizationId),
            'activity' => [
                'active_seconds' => $active,
                'idle_seconds' => $idle,
                'tracked_seconds' => $tracked,
                'active_percent' => $tracked > 0 ? round($active / $tracked * 100, 1) : 0.0,
            ],
            'scope' => $this->tenant->canManageMonitoring($user) ? 'organization' : 'team',
            'generated_at' => now()->utc()->toIso8601String(),
        ];
    }
}
