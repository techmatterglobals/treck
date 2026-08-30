<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\UserRole;
use App\Exceptions\Tenancy\CurrentOrganizationException;
use App\Services\Tenancy\OrganizationAuthorization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    // ----------------------------------------------------------------
    // Relationships
    // ----------------------------------------------------------------

    /** The HR/employee profile paired with this login account (1:1). */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /** Departments this user manages (1:N via departments.manager_id). */
    public function managedDepartments(): HasMany
    {
        return $this->hasMany(Department::class, 'manager_id');
    }

    /**
     * Employees this user supervises as their Manager (Phase 11), via
     * employees.manager_user_id. Empty for non-managers.
     */
    public function managedEmployees(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_user_id');
    }

    /** Organization memberships for this login account. */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /** Organizations this user belongs to. */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->using(OrganizationMembership::class)
            ->withPivot(['id', 'status', 'role', 'is_owner', 'joined_at', 'invited_by_id'])
            ->withTimestamps();
    }

    /** Active memberships whose organizations are not suspended. */
    public function activeOrganizations(): BelongsToMany
    {
        return $this->organizations()
            ->wherePivot('status', MembershipStatus::Active->value)
            ->where('organizations.status', OrganizationStatus::Active->value)
            ->whereNull('organizations.suspended_at');
    }

    // ----------------------------------------------------------------
    // Scopes
    // ----------------------------------------------------------------

    /** Only active (non-disabled) accounts. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Filter by a role name / enum (uses Spatie's roles relation). */
    public function scopeWithRole(Builder $query, UserRole|string $role): Builder
    {
        $name = $role instanceof UserRole ? $role->value : $role;

        return $query->whereHas('roles', fn (Builder $q) => $q->where('name', $name));
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /** Whether this user can administer the system. */
    public function isAdministrator(): bool
    {
        return $this->hasOrganizationRoleSafely(OrganizationRole::Admin)
            || $this->hasRole(UserRole::Admin->value);
    }

    /**
     * The Super Admin is the top of the hierarchy (Phase 11). It is the existing
     * `admin` role, aliased here so hierarchy code can read intent clearly
     * without changing the underlying role string.
     */
    public function isSuperAdmin(): bool
    {
        return app(OrganizationAuthorization::class)->isPlatformSuperAdmin($this)
            || $this->hasRole(UserRole::Admin->value);
    }

    /** Whether this user supervises employees as a Manager (Phase 11). */
    public function isManager(): bool
    {
        return $this->hasOrganizationRoleSafely(OrganizationRole::Manager)
            || $this->hasRole(UserRole::Manager->value);
    }

    /** Convenience check for the employee (self-service) role. */
    public function isEmployee(): bool
    {
        return $this->hasOrganizationRoleSafely(OrganizationRole::Employee)
            || $this->hasRole(UserRole::Employee->value);
    }

    public function isPlatformSuperAdmin(): bool
    {
        return app(OrganizationAuthorization::class)->isPlatformSuperAdmin($this);
    }

    public function membershipFor(Organization|int $organization): ?OrganizationMembership
    {
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;

        if (! $organizationId) {
            return null;
        }

        return $this->memberships()
            ->where('organization_id', $organizationId)
            ->first();
    }

    public function activeMembershipFor(Organization|int $organization): ?OrganizationMembership
    {
        $membership = $this->membershipFor($organization);

        if (! $membership?->isActive()) {
            return null;
        }

        if ($membership->organization?->isSuspended()) {
            return null;
        }

        return $membership;
    }

    public function hasActiveMembership(Organization|int $organization): bool
    {
        return $this->activeMembershipFor($organization) !== null;
    }

    private function hasOrganizationRoleSafely(OrganizationRole $role): bool
    {
        try {
            return app(OrganizationAuthorization::class)->hasOrganizationRole($this, $role);
        } catch (CurrentOrganizationException) {
            return false;
        }
    }
}
