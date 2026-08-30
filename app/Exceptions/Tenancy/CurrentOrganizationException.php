<?php

namespace App\Exceptions\Tenancy;

use RuntimeException;

class CurrentOrganizationException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('No active organization context is available.');
    }

    public static function unauthenticated(): self
    {
        return new self('unauthenticated');
    }

    public static function noMembership(): self
    {
        return new self('no_membership');
    }

    public static function inactiveMembership(): self
    {
        return new self('inactive_membership');
    }

    public static function suspendedOrganization(): self
    {
        return new self('suspended_organization');
    }

    public static function ambiguous(): self
    {
        return new self('ambiguous');
    }
}
