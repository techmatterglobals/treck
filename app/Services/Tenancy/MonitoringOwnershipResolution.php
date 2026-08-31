<?php

namespace App\Services\Tenancy;

class MonitoringOwnershipResolution
{
    public function __construct(
        public readonly ?int $organizationId,
        public readonly bool $conflicted = false,
        public readonly string $reason = 'unresolved',
    ) {}

    public function safe(): bool
    {
        return ! $this->conflicted && $this->organizationId !== null;
    }
}
