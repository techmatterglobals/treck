<?php

namespace App\Console\Commands;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Tenancy\OrganizationAuthorization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class BackfillOrganizationRoles extends Command
{
    protected $signature = 'treck:backfill-organization-roles
        {--organization-id= : Default organization id}
        {--slug=default : Default organization slug}
        {--dry-run : Report planned changes without writing them}';

    protected $description = 'Convert legacy admin users into default-organization admins without granting platform roles.';

    public function handle(OrganizationAuthorization $authorization): int
    {
        $organization = $this->resolveOrganization();

        if (! $organization) {
            $this->error('Target default organization was not found.');

            return self::FAILURE;
        }

        if ($organization->isSuspended()) {
            $this->error('Target default organization is suspended.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $legacyAdmins = User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', UserRole::Admin->value)->where('guard_name', 'web'))
            ->with('memberships')
            ->orderBy('id')
            ->get();

        $plannedMemberships = 0;
        $plannedMembershipActivations = 0;
        $plannedRoleAssignments = 0;

        foreach ($legacyAdmins as $user) {
            $membership = $user->membershipFor($organization);
            $plannedMemberships += $membership ? 0 : 1;
            $plannedMembershipActivations += $membership && ! $membership->isActive() ? 1 : 0;
            $plannedRoleAssignments += $authorization->hasOrganizationRole($user, OrganizationRole::Admin, $organization) ? 0 : 1;
        }

        $this->line('organization_id='.$organization->id);
        $this->line('legacy_admins_seen='.$legacyAdmins->count());
        $this->line('memberships_to_create='.$plannedMemberships);
        $this->line('memberships_to_activate='.$plannedMembershipActivations);
        $this->line('organization_admin_roles_to_assign='.$plannedRoleAssignments);
        $this->line('platform_super_admin_assignments=0');

        if ($dryRun) {
            $this->line('Dry run only; no data was changed.');

            return self::SUCCESS;
        }

        $createdMemberships = 0;
        $updatedMemberships = 0;
        $assignedRoles = 0;

        DB::transaction(function () use ($legacyAdmins, $organization, $authorization, &$createdMemberships, &$updatedMemberships, &$assignedRoles) {
            foreach ($legacyAdmins as $user) {
                $membership = OrganizationMembership::firstOrCreate(
                    [
                        'organization_id' => $organization->id,
                        'user_id' => $user->id,
                    ],
                    [
                        'status' => MembershipStatus::Active,
                        'role' => OrganizationRole::Admin->value,
                        'is_owner' => false,
                        'joined_at' => now(),
                    ],
                );

                if ($membership->wasRecentlyCreated) {
                    $createdMemberships++;
                } elseif (! $membership->isActive()) {
                    $membership->forceFill([
                        'status' => MembershipStatus::Active,
                        'joined_at' => $membership->joined_at ?? now(),
                    ])->save();
                    $updatedMemberships++;
                }

                if (! $authorization->hasOrganizationRole($user, OrganizationRole::Admin, $organization)) {
                    $authorization->assignOrganizationRole($user, $organization, OrganizationRole::Admin);
                    $assignedRoles++;
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info("Memberships created: {$createdMemberships}.");
        $this->info("Memberships activated: {$updatedMemberships}.");
        $this->info("Organization admin roles assigned: {$assignedRoles}.");
        $this->info('No platform-super-admin role was assigned.');

        return self::SUCCESS;
    }

    private function resolveOrganization(): ?Organization
    {
        $organizationId = $this->option('organization-id');

        if ($organizationId !== null && $organizationId !== '') {
            return filter_var($organizationId, FILTER_VALIDATE_INT) === false
                ? null
                : Organization::find((int) $organizationId);
        }

        $slug = Str::slug((string) $this->option('slug'));

        if ($slug === '') {
            return null;
        }

        return Organization::where('slug', $slug)->first();
    }
}
