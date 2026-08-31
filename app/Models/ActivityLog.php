<?php

namespace App\Models;

use App\Enums\ComputerStatus;
use App\Enums\SessionEndReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'employee_id',
        'computer_id',
        'login_at',
        'logout_at',
        'active_seconds',
        'idle_seconds',
        'status',
        'end_reason',
        'work_date',
    ];

    protected function casts(): array
    {
        return [
            'login_at' => 'datetime',
            'logout_at' => 'datetime',
            'work_date' => 'date',
            'status' => ComputerStatus::class,
            'end_reason' => SessionEndReason::class,
        ];
    }

    // ----------------------------------------------------------------
    // Relationships
    // ----------------------------------------------------------------

    /** The employee who owned this PC session (N:1). */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** The computer this session ran on (N:1). */
    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }

    /** Application usage captured during this session (1:N). */
    public function applicationUsage(): HasMany
    {
        return $this->hasMany(ApplicationUsage::class);
    }

    /** Screenshots captured during this session (1:N). */
    public function screenshots(): HasMany
    {
        return $this->hasMany(Screenshot::class);
    }

    // ----------------------------------------------------------------
    // Accessors
    // ----------------------------------------------------------------

    /** Whether the session is still open (no logout recorded). */
    protected function isOpen(): Attribute
    {
        return Attribute::make(
            get: fn () => is_null($this->logout_at),
        );
    }

    /**
     * Session length in seconds. Uses logout_at when closed, otherwise the
     * elapsed time since login for an in-progress session.
     */
    protected function durationSeconds(): Attribute
    {
        return Attribute::make(
            get: fn () => ($this->logout_at ?? now())->diffInSeconds($this->login_at),
        );
    }

    // ----------------------------------------------------------------
    // Scopes
    // ----------------------------------------------------------------

    /** Only open (in-progress) sessions. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('logout_at');
    }

    public function scopeForOrganization(Builder $query, Organization|int $organization): Builder
    {
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;

        return $query->where('organization_id', $organizationId);
    }

    /** Sessions for a specific work date (defaults to today). */
    public function scopeForDate(Builder $query, $date = null): Builder
    {
        return $query->whereDate('work_date', $date ?? today());
    }

    /** Sessions for a specific employee. */
    public function scopeForEmployee(Builder $query, int $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /** Close the session with the given reason. */
    public function close(SessionEndReason $reason, $at = null): bool
    {
        return $this->forceFill([
            'logout_at' => $at ?? now(),
            'status' => ComputerStatus::Offline,
            'end_reason' => $reason,
        ])->save();
    }
}
