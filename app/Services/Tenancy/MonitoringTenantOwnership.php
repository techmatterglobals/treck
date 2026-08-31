<?php

namespace App\Services\Tenancy;

use App\Models\ActivityLog;
use App\Models\AgentEvent;
use App\Models\ApplicationUsage;
use App\Models\Computer;
use App\Models\Employee;

class MonitoringTenantOwnership
{
    public function resolve(
        Computer|int|null $computer = null,
        Employee|int|null $employee = null,
        bool $allowEmployeeOnly = false,
    ): MonitoringOwnershipResolution {
        $computer = $this->computer($computer);
        $employee = $this->employee($employee);

        $computerOrganizationId = $computer?->organization_id ? (int) $computer->organization_id : null;
        $employeeOrganizationId = $employee?->organization_id ? (int) $employee->organization_id : null;

        if ($computerOrganizationId !== null && $employeeOrganizationId !== null) {
            if ($computerOrganizationId !== $employeeOrganizationId) {
                return new MonitoringOwnershipResolution(null, true, 'computer_employee_conflict');
            }

            return new MonitoringOwnershipResolution($computerOrganizationId, false, 'computer_employee_match');
        }

        if ($computerOrganizationId !== null) {
            return new MonitoringOwnershipResolution($computerOrganizationId, false, 'computer');
        }

        if ($allowEmployeeOnly && $employeeOrganizationId !== null) {
            return new MonitoringOwnershipResolution($employeeOrganizationId, false, 'employee');
        }

        return new MonitoringOwnershipResolution(null, false, 'unowned_parent');
    }

    public function forComputer(Computer $computer): ?int
    {
        return $this->resolve(computer: $computer)->organizationId;
    }

    public function forActivityLog(ActivityLog $activityLog): MonitoringOwnershipResolution
    {
        return $this->resolve($activityLog->computer_id, $activityLog->employee_id, true);
    }

    public function forAgentEvent(AgentEvent $event): MonitoringOwnershipResolution
    {
        return $this->resolve($event->computer_id, $event->employee_id);
    }

    public function forApplicationUsage(ApplicationUsage $usage): MonitoringOwnershipResolution
    {
        $resolution = $this->resolve($usage->computer_id, $usage->employee_id);

        if ($resolution->organizationId !== null || $usage->activity_log_id === null) {
            return $resolution;
        }

        $activityLog = ActivityLog::find($usage->activity_log_id);

        if ($activityLog?->organization_id !== null) {
            return new MonitoringOwnershipResolution((int) $activityLog->organization_id, false, 'activity_log');
        }

        return $resolution;
    }

    private function computer(Computer|int|null $computer): ?Computer
    {
        if ($computer instanceof Computer || $computer === null) {
            return $computer;
        }

        return Computer::find($computer);
    }

    private function employee(Employee|int|null $employee): ?Employee
    {
        if ($employee instanceof Employee || $employee === null) {
            return $employee;
        }

        return Employee::find($employee);
    }
}
