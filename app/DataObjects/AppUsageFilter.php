<?php

namespace App\DataObjects;

use Illuminate\Support\Carbon;

/**
 * Immutable filter for application-usage queries (Phase 7): date range plus
 * optional employee / computer / department / application-search narrowing.
 * Defaults to today.
 */
class AppUsageFilter
{
    /**
     * @param  list<int>|null  $employeeIds  Manager/employee visibility restriction
     *                                       (Phase 11). Null = unrestricted (Super Admin).
     */
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly ?int $employeeId = null,
        public readonly ?int $computerId = null,
        public readonly ?int $departmentId = null,
        public readonly ?string $application = null,
        public readonly ?array $employeeIds = null,
    ) {}

    /** Return a copy restricted to a visible-employee id set (null = unrestricted). */
    public function restrictToEmployees(?array $employeeIds): self
    {
        return new self(
            $this->from, $this->to, $this->employeeId, $this->computerId,
            $this->departmentId, $this->application, $employeeIds,
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            from: ! empty($data['from']) ? Carbon::parse($data['from'])->startOfDay() : today()->startOfDay(),
            to: ! empty($data['to']) ? Carbon::parse($data['to'])->endOfDay() : today()->endOfDay(),
            employeeId: ! empty($data['employee_id']) ? (int) $data['employee_id'] : null,
            computerId: ! empty($data['computer_id']) ? (int) $data['computer_id'] : null,
            departmentId: ! empty($data['department_id']) ? (int) $data['department_id'] : null,
            application: ! empty($data['application']) ? trim((string) $data['application']) : null,
        );
    }
}
