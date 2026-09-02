<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use InvalidArgumentException;

class TenantCacheKey
{
    public static function forOrganization(Organization|int $organization, string $key): string
    {
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;

        if ($organizationId <= 0) {
            throw new InvalidArgumentException('Tenant cache keys require a positive organization id.');
        }

        return 'org:'.$organizationId.':'.self::normalize($key);
    }

    public static function platform(string $key): string
    {
        return 'platform:'.self::normalize($key);
    }

    private static function normalize(string $key): string
    {
        $key = trim($key);

        if ($key === '') {
            throw new InvalidArgumentException('Cache key segment must not be blank.');
        }

        return str_replace([' ', "\t", "\n", "\r"], ':', $key);
    }
}
