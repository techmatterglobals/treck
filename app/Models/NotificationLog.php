<?php

namespace App\Models;

use App\Enums\NotificationSeverity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One notification, per recipient + channel (Phase 9). This is the persisted
 * history and the in-app inbox source. Content is plain text produced by the
 * rules (already sanitized upstream from window titles etc.).
 */
class NotificationLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'recipient_id', 'computer_id', 'employee_id', 'event_type', 'severity',
        'title', 'message', 'channel', 'dedupe_key', 'status', 'delivered_at',
        'read_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    // ---- Relationships -----------------------------------------------------

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    // ---- Accessors ---------------------------------------------------------

    protected function severityEnum(): Attribute
    {
        return Attribute::make(
            get: fn () => NotificationSeverity::tryFrom($this->severity) ?? NotificationSeverity::Info,
        );
    }

    public function getIsReadAttribute(): bool
    {
        return $this->read_at !== null;
    }

    // ---- Scopes ------------------------------------------------------------

    public function scopeForRecipient(Builder $query, int $userId): Builder
    {
        return $query->where('recipient_id', $userId);
    }

    public function scopeForOrganization(Builder $query, Organization|int $organization): Builder
    {
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;

        return $query->where('organization_id', $organizationId);
    }

    public function scopeInApp(Builder $query): Builder
    {
        return $query->where('channel', 'in_app');
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeOfSeverity(Builder $query, string $severity): Builder
    {
        return $query->where('severity', $severity);
    }

    public function scopeMatching(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('title', 'like', "%{$term}%")
                ->orWhere('message', 'like', "%{$term}%");
        });
    }

    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }
}
