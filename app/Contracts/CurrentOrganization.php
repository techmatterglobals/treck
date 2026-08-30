<?php

namespace App\Contracts;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

interface CurrentOrganization
{
    public const SESSION_KEY = 'current_organization_id';

    public function resolve(?User $user = null, int|string|null $selectedOrganizationId = null): Organization;

    public function membership(?User $user = null, int|string|null $selectedOrganizationId = null): OrganizationMembership;

    public function select(User $user, Organization|int $organization): Organization;

    public function clear(): void;
}
