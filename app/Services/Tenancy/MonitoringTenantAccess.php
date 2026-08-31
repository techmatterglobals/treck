<?php

namespace App\Services\Tenancy;

use App\Enums\OrganizationRole;
use App\Models\ActivityLog;
use App\Models\AgentEvent;
use App\Models\ApplicationUsage;
use App\Models\Computer;
use App\Models\ComputerPresence;
use App\Models\Department;
use App\Models\Employee;
use App\Models\FileDownload;
use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\Screenshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Collection;

class MonitoringTenantAccess
{
    public function __construct(private readonly CoreTenantAccess $core) {}

    public function organization(?User $user = null): Organization
    {
        return $this->core->organization($user);
    }

    public function organizationId(?User $user = null): int
    {
        return $this->organization($user)->id;
    }

    public function canViewMonitoring(User $user): bool
    {
        return $this->core->hasAnyOrganizationRole($user, [
            OrganizationRole::Owner,
            OrganizationRole::Admin,
            OrganizationRole::Manager,
        ]);
    }

    public function canManageMonitoring(User $user): bool
    {
        return $this->core->hasAnyOrganizationRole($user, [
            OrganizationRole::Owner,
            OrganizationRole::Admin,
        ]);
    }

    /**
     * @return list<int>|null
     */
    public function visibleEmployeeIds(User $user): ?array
    {
        $organization = $this->organization($user);

        if ($this->core->hasAnyOrganizationRole($user, [OrganizationRole::Owner, OrganizationRole::Admin], $organization)) {
            return null;
        }

        if ($this->core->hasAnyOrganizationRole($user, [OrganizationRole::Manager], $organization)) {
            return Employee::query()
                ->forOrganization($organization)
                ->managedBy($user)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return [];
    }

    /**
     * @return list<int>|null
     */
    public function visibleComputerIds(User $user): ?array
    {
        $organization = $this->organization($user);
        $employeeIds = $this->visibleEmployeeIds($user);

        if ($employeeIds === null) {
            return null;
        }

        return Computer::query()
            ->forOrganization($organization)
            ->whereIn('employee_id', $employeeIds ?: [0])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function canSeeEmployee(User $user, ?int $employeeId): bool
    {
        if ($employeeId === null) {
            return false;
        }

        $employee = Employee::query()
            ->forOrganization($this->organization($user))
            ->whereKey($employeeId)
            ->first();

        if ($employee === null) {
            return false;
        }

        $ids = $this->visibleEmployeeIds($user);

        return $ids === null || in_array($employeeId, $ids, true);
    }

    public function canSeeComputer(User $user, ?int $computerId): bool
    {
        if ($computerId === null) {
            return false;
        }

        $computer = $this->computer($computerId, $user);
        $ids = $this->visibleComputerIds($user);

        return $ids === null || in_array((int) $computer->id, $ids, true);
    }

    /**
     * @return Collection<int,Employee>
     */
    public function employees(User $user): Collection
    {
        $ids = $this->visibleEmployeeIds($user);

        return Employee::query()
            ->forOrganization($this->organization($user))
            ->with('user')
            ->when($ids !== null, fn (EloquentBuilder $query) => $query->whereIn('id', $ids ?: [0]))
            ->get()
            ->sortBy('name')
            ->values();
    }

    /**
     * @return Collection<int,Computer>
     */
    public function computers(User $user): Collection
    {
        $ids = $this->visibleComputerIds($user);

        return Computer::query()
            ->forOrganization($this->organization($user))
            ->when($ids !== null, fn (EloquentBuilder $query) => $query->whereIn('id', $ids ?: [0]))
            ->orderBy('hostname')
            ->get();
    }

    /**
     * @return Collection<int,Department>
     */
    public function departments(?User $user = null): Collection
    {
        return $this->core->departments($user)->orderBy('name')->get();
    }

    public function computer(Computer|int $computer, ?User $user = null): Computer
    {
        $id = $computer instanceof Computer ? $computer->id : $computer;

        return $this->core->computers($user)->whereKey($id)->firstOrFail();
    }

    public function screenshot(Screenshot|int $screenshot, ?User $user = null): Screenshot
    {
        $id = $screenshot instanceof Screenshot ? $screenshot->id : $screenshot;

        return Screenshot::query()
            ->forOrganization($this->organization($user))
            ->whereKey($id)
            ->firstOrFail();
    }

    public function fileDownload(FileDownload|int $download, ?User $user = null): FileDownload
    {
        $id = $download instanceof FileDownload ? $download->id : $download;

        return FileDownload::query()
            ->forOrganization($this->organization($user))
            ->whereKey($id)
            ->firstOrFail();
    }

    public function notification(NotificationLog|int $notification, ?User $user = null): NotificationLog
    {
        $id = $notification instanceof NotificationLog ? $notification->id : $notification;

        return NotificationLog::query()
            ->forOrganization($this->organization($user))
            ->whereKey($id)
            ->firstOrFail();
    }

    /**
     * @return EloquentBuilder<ActivityLog>
     */
    public function activityLogs(?User $user = null): EloquentBuilder
    {
        return ActivityLog::query()->forOrganization($this->organization($user));
    }

    /**
     * @return EloquentBuilder<AgentEvent>
     */
    public function agentEvents(?User $user = null): EloquentBuilder
    {
        return AgentEvent::query()->forOrganization($this->organization($user));
    }

    /**
     * @return EloquentBuilder<ApplicationUsage>
     */
    public function applicationUsage(?User $user = null): EloquentBuilder
    {
        return ApplicationUsage::query()->forOrganization($this->organization($user));
    }

    /**
     * @return EloquentBuilder<ComputerPresence>
     */
    public function computerPresence(?User $user = null): EloquentBuilder
    {
        return ComputerPresence::query()->forOrganization($this->organization($user));
    }
}
