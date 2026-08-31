<?php

namespace App\DataObjects;

use Illuminate\Support\Carbon;

/**
 * Immutable filter for file-download queries (Phase 12): date range plus
 * optional employee / manager / computer / extension / application / search
 * narrowing, and the manager/employee visibility restriction. Defaults to the
 * last 30 days.
 */
class DownloadFilter
{
    /**
     * @param  list<int>|null  $employeeIds  Visibility restriction (null = unrestricted / Super Admin).
     */
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly ?int $employeeId = null,
        public readonly ?int $managerUserId = null,
        public readonly ?int $computerId = null,
        public readonly ?string $extension = null,
        public readonly ?string $application = null,
        public readonly ?string $search = null,
        public readonly ?array $employeeIds = null,
        public readonly ?int $organizationId = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            from: ! empty($data['from']) ? Carbon::parse($data['from'])->startOfDay() : today()->subDays(29)->startOfDay(),
            to: ! empty($data['to']) ? Carbon::parse($data['to'])->endOfDay() : today()->endOfDay(),
            employeeId: ! empty($data['employee_id']) ? (int) $data['employee_id'] : null,
            managerUserId: ! empty($data['manager_user_id']) ? (int) $data['manager_user_id'] : null,
            computerId: ! empty($data['computer_id']) ? (int) $data['computer_id'] : null,
            extension: ! empty($data['extension']) ? strtolower(ltrim(trim((string) $data['extension']), '.')) : null,
            application: ! empty($data['application']) ? trim((string) $data['application']) : null,
            search: ! empty($data['search']) ? trim((string) $data['search']) : null,
        );
    }

    /** Return a copy restricted to a visible-employee id set (null = unrestricted). */
    public function restrictToEmployees(?array $employeeIds): self
    {
        return new self(
            $this->from, $this->to, $this->employeeId, $this->managerUserId,
            $this->computerId, $this->extension, $this->application, $this->search, $employeeIds, $this->organizationId,
        );
    }

    public function forOrganization(?int $organizationId): self
    {
        return new self(
            $this->from, $this->to, $this->employeeId, $this->managerUserId,
            $this->computerId, $this->extension, $this->application, $this->search, $this->employeeIds, $organizationId,
        );
    }
}
