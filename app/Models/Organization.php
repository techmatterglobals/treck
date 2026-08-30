<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'status',
        'suspended_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
            'suspended_at' => 'datetime',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(OrganizationMembership::class)
            ->withPivot(['id', 'status', 'role', 'is_owner', 'joined_at', 'invited_by_id'])
            ->withTimestamps();
    }

    public function activeUsers(): BelongsToMany
    {
        return $this->users()->wherePivot('status', MembershipStatus::Active->value);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', OrganizationStatus::Active->value);
    }

    public function scopeSuspended(Builder $query): Builder
    {
        return $query->where('status', OrganizationStatus::Suspended->value);
    }

    public function isActive(): bool
    {
        return $this->status === OrganizationStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === OrganizationStatus::Suspended || $this->suspended_at !== null;
    }

    public function suspend(): bool
    {
        return $this->forceFill([
            'status' => OrganizationStatus::Suspended,
            'suspended_at' => $this->suspended_at ?? now(),
        ])->save();
    }

    public function activate(): bool
    {
        return $this->forceFill([
            'status' => OrganizationStatus::Active,
            'suspended_at' => null,
        ])->save();
    }
}
