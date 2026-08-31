<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\ProductivityRating;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationUsage extends Model
{
    use HasFactory;

    /** Table is 'application_usage' (not the default 'application_usages'). */
    protected $table = 'application_usage';

    protected $fillable = [
        'organization_id',
        'employee_id',
        'computer_id',
        'activity_log_id',
        'application_name',
        'executable',
        'window_title',
        'category',
        'productivity',
        'used_at',
        'ended_at',
        'duration_seconds',
        'session_id',
    ];

    protected function casts(): array
    {
        return [
            // Agent-sourced instants stored as UTC digits.
            'used_at' => UtcDateTime::class,
            'ended_at' => UtcDateTime::class,
            'productivity' => ProductivityRating::class,
        ];
    }

    // ----------------------------------------------------------------
    // Relationships
    // ----------------------------------------------------------------

    /** The employee who used the application (N:1). */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** The computer the application ran on (N:1). */
    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }

    /** The PC session this usage belongs to (N:1, optional). */
    public function activityLog(): BelongsTo
    {
        return $this->belongsTo(ActivityLog::class);
    }

    // ----------------------------------------------------------------
    // Accessors
    // ----------------------------------------------------------------

    /** Usage duration in minutes (2dp). */
    protected function durationMinutes(): Attribute
    {
        return Attribute::make(
            get: fn () => round($this->duration_seconds / 60, 2),
        );
    }

    // ----------------------------------------------------------------
    // Scopes
    // ----------------------------------------------------------------

    /** Filter by productivity rating. */
    public function scopeRated(Builder $query, ProductivityRating $rating): Builder
    {
        return $query->where('productivity', $rating->value);
    }

    public function scopeForOrganization(Builder $query, Organization|int $organization): Builder
    {
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;

        return $query->where('organization_id', $organizationId);
    }

    /** Only productive usage. */
    public function scopeProductive(Builder $query): Builder
    {
        return $query->where('productivity', ProductivityRating::Productive->value);
    }

    /** Only unproductive usage. */
    public function scopeUnproductive(Builder $query): Builder
    {
        return $query->where('productivity', ProductivityRating::Unproductive->value);
    }

    /** Usage on a specific date (defaults to today). */
    public function scopeForDate(Builder $query, $date = null): Builder
    {
        return $query->whereDate('used_at', $date ?? today());
    }

    /** Usage for a specific employee. */
    public function scopeForEmployee(Builder $query, int $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }

    /** Usage on a specific computer. */
    public function scopeForComputer(Builder $query, int $computerId): Builder
    {
        return $query->where('computer_id', $computerId);
    }

    /** Usage whose start falls in an inclusive [from, to] range. */
    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('used_at', [$from, $to]);
    }

    /** Match an application/window-title search term. */
    public function scopeMatchingApplication(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('application_name', 'like', "%{$term}%")
                ->orWhere('window_title', 'like', "%{$term}%");
        });
    }

    // ----------------------------------------------------------------
    // Accessors
    // ----------------------------------------------------------------

    /** Session end (stored, else derived from start + duration). */
    protected function endedAtOrDerived(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->ended_at ?? $this->used_at?->copy()->addSeconds($this->duration_seconds),
        );
    }
}
