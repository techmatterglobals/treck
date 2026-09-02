<?php

namespace App\Services\Tenancy;

use App\Models\Organization;
use Closure;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

class OrganizationContext
{
    public function __construct(private readonly PermissionRegistrar $permissions) {}

    public function run(?int $organizationId, Closure $callback): mixed
    {
        $this->set($organizationId);

        try {
            return $callback();
        } finally {
            $this->clear();
        }
    }

    public function set(?int $organizationId): void
    {
        if ($organizationId === null) {
            $this->clear();

            return;
        }

        $exists = Organization::query()->active()->whereKey($organizationId)->exists();

        if (! $exists) {
            $this->clear();

            throw new RuntimeException("Organization context {$organizationId} is not active.");
        }

        $this->permissions->setPermissionsTeamId($organizationId);
    }

    public function clear(): void
    {
        $this->permissions->setPermissionsTeamId(null);
    }
}
