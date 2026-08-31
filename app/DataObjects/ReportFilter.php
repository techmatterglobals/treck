<?php

namespace App\DataObjects;

use App\Enums\ReportPeriod;
use Illuminate\Support\Carbon;

/**
 * Immutable, typed report filter carried from the request into the service and
 * exports. Applies sensible defaults (current month, daily granularity).
 */
class ReportFilter
{
    /**
     * @param  list<int>|null  $employeeIds  Manager/employee visibility restriction
     *                                       (Phase 11). Null = unrestricted (Super Admin).
     */
    public function __construct(
        public readonly ReportPeriod $period,
        public readonly ?int $employeeId,
        public readonly ?int $departmentId,
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly ?int $managerUserId = null,
        public readonly ?array $employeeIds = null,
        public readonly ?int $organizationId = null,
    ) {}

    /** Return a copy restricted to a visible-employee id set (null = unrestricted). */
    public function restrictToEmployees(?array $employeeIds): self
    {
        return new self(
            $this->period, $this->employeeId, $this->departmentId,
            $this->from, $this->to, $this->managerUserId, $employeeIds, $this->organizationId,
        );
    }

    public function forOrganization(?int $organizationId): self
    {
        return new self(
            $this->period, $this->employeeId, $this->departmentId,
            $this->from, $this->to, $this->managerUserId, $this->employeeIds, $organizationId,
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            period: ReportPeriod::tryFrom($data['period'] ?? '') ?? ReportPeriod::Daily,
            employeeId: ! empty($data['employee_id']) ? (int) $data['employee_id'] : null,
            departmentId: ! empty($data['department_id']) ? (int) $data['department_id'] : null,
            from: ! empty($data['from']) ? Carbon::parse($data['from'])->startOfDay() : today()->startOfMonth(),
            to: ! empty($data['to']) ? Carbon::parse($data['to'])->endOfDay() : today()->endOfDay(),
            managerUserId: ! empty($data['manager_user_id']) ? (int) $data['manager_user_id'] : null,
        );
    }

    /** Filename stem for exports. */
    public function fileSlug(): string
    {
        return sprintf(
            'treck-%s-report-%s_%s',
            $this->period->value,
            $this->from->toDateString(),
            $this->to->toDateString(),
        );
    }
}
