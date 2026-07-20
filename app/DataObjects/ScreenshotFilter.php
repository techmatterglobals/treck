<?php

namespace App\DataObjects;

use Illuminate\Support\Carbon;

/**
 * Immutable filter for screenshot queries (Phase 8): date range plus optional
 * employee / computer / department / process-search narrowing. Defaults to the
 * last 7 days.
 */
class ScreenshotFilter
{
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly ?int $employeeId = null,
        public readonly ?int $computerId = null,
        public readonly ?int $departmentId = null,
        public readonly ?string $search = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            from: ! empty($data['from']) ? Carbon::parse($data['from'])->startOfDay() : today()->subDays(6)->startOfDay(),
            to: ! empty($data['to']) ? Carbon::parse($data['to'])->endOfDay() : today()->endOfDay(),
            employeeId: ! empty($data['employee_id']) ? (int) $data['employee_id'] : null,
            computerId: ! empty($data['computer_id']) ? (int) $data['computer_id'] : null,
            departmentId: ! empty($data['department_id']) ? (int) $data['department_id'] : null,
            search: ! empty($data['search']) ? trim((string) $data['search']) : null,
        );
    }
}
