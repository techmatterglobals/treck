<?php

namespace App\Services\Agent;

use App\Models\ComputerUser;

/**
 * The outcome of resolving a computer + Windows username to an employee
 * (Phase 11). `employeeId` is null when the Windows account is unrecognized
 * (a pending mapping was recorded and the Super Admin notified); `mapping` is
 * the ComputerUser row when one applies (null on the legacy/single-user path).
 */
final class ResolvedIdentity
{
    public function __construct(
        public readonly ?int $employeeId,
        public readonly ?ComputerUser $mapping = null,
    ) {}

    /** Whether the Windows account is known but unmapped (unresolved). */
    public function isPending(): bool
    {
        return $this->mapping !== null && $this->employeeId === null;
    }
}
