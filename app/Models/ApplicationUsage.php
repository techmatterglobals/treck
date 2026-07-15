<?php

namespace App\Models;

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
        'employee_id',
        'computer_id',
        'activity_log_id',
        'application_name',
        'executable',
        'window_title',
        'category',
        'productivity',
        'used_at',
        'duration_seconds',
    ];

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
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
}
