<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'manager_id',
    ];

    // ----------------------------------------------------------------
    // Relationships
    // ----------------------------------------------------------------

    /** The user who manages this department (N:1). */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function scopeForOrganization($query, Organization|int $organization)
    {
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;

        return $query->where('organization_id', $organizationId);
    }

    /** Employees that belong to this department (1:N). */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    // ----------------------------------------------------------------
    // Accessors
    // ----------------------------------------------------------------

    /**
     * Number of employees. Uses the eager-loaded `employees_count` when the
     * query used withCount('employees'), otherwise falls back to a count query.
     */
    protected function headcount(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->employees_count ?? $this->employees()->count(),
        )->shouldCache();
    }
}
