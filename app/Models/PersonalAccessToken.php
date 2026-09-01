<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'organization_id',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $token) {
            if (! Schema::hasColumn($token->getTable(), 'organization_id')) {
                return;
            }

            if ($token->organization_id !== null || $token->tokenable_type !== (new Computer)->getMorphClass()) {
                return;
            }

            $organizationId = Computer::query()
                ->whereKey($token->tokenable_id)
                ->value('organization_id');

            if ($organizationId !== null) {
                $token->organization_id = (int) $organizationId;
            }
        });
    }

    public function scopeForOrganization(Builder $query, Organization|int $organization): Builder
    {
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;

        return $query->where('organization_id', $organizationId);
    }
}
