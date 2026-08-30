<?php

namespace App\Services\Tenancy;

use App\Contracts\CurrentOrganization;
use App\Enums\OrganizationRole;
use App\Enums\PlatformRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class OrganizationAuthorization
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    public function hasActiveMembership(User $user, Organization|int $organization): bool
    {
        return $user->hasActiveMembership($organization);
    }

    public function isPlatformSuperAdmin(User $user): bool
    {
        return DB::table(config('permission.table_names.model_has_roles'))
            ->join(config('permission.table_names.roles'), 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', PlatformRole::SuperAdmin->value)
            ->where('roles.guard_name', 'web')
            ->whereNull('roles.organization_id')
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.model_id', $user->getKey())
            ->whereNull('model_has_roles.organization_id')
            ->exists();
    }

    public function hasOrganizationRole(User $user, OrganizationRole|string|array $roles, Organization|int|null $organization = null): bool
    {
        $organization = $organization
            ? $this->organizationId($organization)
            : $this->currentOrganization->resolve($user)->id;

        if (! $this->hasActiveMembership($user, $organization)) {
            return false;
        }

        $names = collect(Arr::wrap($roles))
            ->map(fn (OrganizationRole|string $role) => $role instanceof OrganizationRole ? $role->value : $role)
            ->all();

        return DB::table(config('permission.table_names.model_has_roles'))
            ->join(config('permission.table_names.roles'), 'roles.id', '=', 'model_has_roles.role_id')
            ->whereIn('roles.name', $names)
            ->where('roles.guard_name', 'web')
            ->where('roles.organization_id', $organization)
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.model_id', $user->getKey())
            ->where('model_has_roles.organization_id', $organization)
            ->exists();
    }

    public function assignOrganizationRole(User $user, Organization|int $organization, OrganizationRole|string $role): void
    {
        $organizationId = $this->organizationId($organization);
        $roleName = $role instanceof OrganizationRole ? $role->value : $role;
        $teamKey = config('permission.column_names.team_foreign_key', 'organization_id');

        app(PermissionRegistrar::class)->setPermissionsTeamId($organizationId);

        $roleModel = Role::query()->firstOrCreate([
            $teamKey => $organizationId,
            'name' => $roleName,
            'guard_name' => 'web',
        ]);

        DB::table(config('permission.table_names.model_has_roles'))->updateOrInsert(
            [
                'role_id' => $roleModel->id,
                'model_type' => $user->getMorphClass(),
                'model_id' => $user->getKey(),
                $teamKey => $organizationId,
            ],
            [],
        );

        $user->unsetRelation('roles');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function syncOrganizationRole(User $user, Organization|int $organization, OrganizationRole|string $role): void
    {
        $organizationId = $this->organizationId($organization);
        $roleIds = Role::query()
            ->where('organization_id', $organizationId)
            ->pluck('id');

        DB::table(config('permission.table_names.model_has_roles'))
            ->whereIn('role_id', $roleIds)
            ->where('model_has_roles.organization_id', $organizationId)
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.model_id', $user->getKey())
            ->delete();

        $this->assignOrganizationRole($user, $organizationId, $role);
    }

    private function organizationId(Organization|int $organization): int
    {
        return $organization instanceof Organization ? $organization->id : $organization;
    }
}
