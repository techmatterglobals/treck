<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
        return $this->hasRole(UserRole::Admin->value);
    }

    /**
     * The Super Admin is the top of the hierarchy (Phase 11). It is the existing
     * `admin` role, aliased here so hierarchy code can read intent clearly
     * without changing the underlying role string.
     */
    public function isSuperAdmin(): bool
    {
        return $this->isAdministrator();
    }

    /** Whether this user supervises employees as a Manager (Phase 11). */
    public function isManager(): bool
    {
        return $this->hasRole(UserRole::Manager->value);
    }

    /** Convenience check for the employee (self-service) role. */
    public function isEmployee(): bool
    {
        return $this->hasRole(UserRole::Employee->value);
    }
}
