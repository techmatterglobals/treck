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
        return $this->hasAnyRole([UserRole::SuperAdmin->value, UserRole::Admin->value]);
    }
}
