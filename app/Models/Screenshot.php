<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Screenshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'computer_id',
        'activity_log_id',
        'path',
        'thumbnail_path',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
        ];
    }

    // ----------------------------------------------------------------
    // Relationships
    // ----------------------------------------------------------------

    /** The employee the screenshot belongs to (N:1). */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The computer the screenshot was captured on (N:1). */
    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }

    /** The PC session the screenshot was captured in (N:1, optional). */
    public function activityLog(): BelongsTo
    {
        return $this->belongsTo(ActivityLog::class);
    }

    // ----------------------------------------------------------------
    // Accessors
    // ----------------------------------------------------------------

    /** Public URL of the full screenshot. */
    protected function url(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->path ? Storage::url($this->path) : null,
        );
    }

    /** Public URL of the thumbnail (falls back to the full image). */
    protected function thumbnailUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => Storage::url($this->thumbnail_path ?? $this->path),
        );
    }

    // ----------------------------------------------------------------
    // Scopes
    // ----------------------------------------------------------------

    /** Screenshots for a specific employee. */
    public function scopeForEmployee(Builder $query, int $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }

    /** Screenshots captured on a specific date (defaults to today). */
    public function scopeForDate(Builder $query, $date = null): Builder
    {
        return $query->whereDate('captured_at', $date ?? today());
    }
}
