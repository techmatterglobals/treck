<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use HasFactory;

    /** Table is singular ('attendance'), so it must be set explicitly. */
    protected $table = 'attendance';

    protected $fillable = [
        'employee_id',
        'work_date',
        'first_in_at',
        'last_out_at',
        'work_seconds',
        'active_seconds',
        'idle_seconds',
        'status',
        'is_corrected',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'first_in_at' => 'datetime',
            'last_out_at' => 'datetime',
            'status' => AttendanceStatus::class,
            'is_corrected' => 'boolean',
        ];
    }

    // ----------------------------------------------------------------
    // Relationships
    // ----------------------------------------------------------------

    /** The employee this attendance row belongs to (N:1). */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    // ----------------------------------------------------------------
    // Accessors
    // ----------------------------------------------------------------

    /** Total worked time in hours (2dp). */
    protected function workedHours(): Attribute
    {
        return Attribute::make(
            get: fn () => round($this->work_seconds / 3600, 2),
        );
    }

    /** Active time in hours (2dp). */
    protected function activeHours(): Attribute
    {
        return Attribute::make(
            get: fn () => round($this->active_seconds / 3600, 2),
        );
    }

    /** Idle time in hours (2dp). */
    protected function idleHours(): Attribute
    {
        return Attribute::make(
            get: fn () => round($this->idle_seconds / 3600, 2),
        );
    }

    /** Active-time ratio as a percentage (0–100). */
    protected function activeRatio(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->work_seconds > 0
                ? round($this->active_seconds / $this->work_seconds * 100, 1)
                : 0.0,
        );
    }

    // ----------------------------------------------------------------
    // Scopes
    // ----------------------------------------------------------------

    /** Records for a specific date (defaults to today). */
    public function scopeForDate(Builder $query, $date = null): Builder
    {
        return $query->whereDate('work_date', $date ?? today());
    }

    /** Records for a specific employee. */
    public function scopeForEmployee(Builder $query, int $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }

    /** Records with a given status. */
    public function scopeWithStatus(Builder $query, AttendanceStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /** Records within an inclusive date range. */
    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('work_date', [$from, $to]);
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /** Whether the employee was counted as present (present/late/half-day). */
    public function isPresent(): bool
    {
        return $this->status?->isPresent() ?? false;
    }

    /** Whether the employee clocked in late. */
    public function isLate(): bool
    {
        return $this->status === AttendanceStatus::Late;
    }
}
