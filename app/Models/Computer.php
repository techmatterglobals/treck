<?php

namespace App\Models;

use App\Enums\ComputerStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Computer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'device_uuid',
        'hostname',
        'os',
        'agent_version',
        'status',
        'last_seen_at',
        'paired_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ComputerStatus::class,
            'last_seen_at' => 'datetime',
            'paired_at' => 'datetime',
        ];
    }

    // ----------------------------------------------------------------
    // Relationships
    // ----------------------------------------------------------------

    /** The employee this workstation is assigned to (N:1). */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** PC login/logout sessions recorded on this computer (1:N). */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /** Application usage recorded on this computer (1:N). */
    public function applicationUsage(): HasMany
    {
        return $this->hasMany(ApplicationUsage::class);
    }

    /** Screenshots captured on this computer (1:N). */
    public function screenshots(): HasMany
    {
        return $this->hasMany(Screenshot::class);
    }

    // ----------------------------------------------------------------
    // Accessors
    // ----------------------------------------------------------------

    /** True once the computer has been paired to an employee. */
    protected function isPaired(): Attribute
    {
        return Attribute::make(
            get: fn () => ! is_null($this->paired_at) && ! is_null($this->employee_id),
        );
    }

    // ----------------------------------------------------------------
    // Scopes
    // ----------------------------------------------------------------

    /** Filter by a specific status. */
    public function scopeWithStatus(Builder $query, ComputerStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /** Only currently connected workstations (not offline). */
    public function scopeConnected(Builder $query): Builder
    {
        return $query->where('status', '!=', ComputerStatus::Offline->value);
    }

    /** Workstations not heard from within the given number of minutes. */
    public function scopeStale(Builder $query, int $minutes = 5): Builder
    {
        return $query->where('last_seen_at', '<', now()->subMinutes($minutes));
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /** Whether the computer is currently connected. */
    public function isOnline(): bool
    {
        return $this->status?->isConnected() ?? false;
    }

    /** The currently open session on this computer, if any. */
    public function openSession(): ?ActivityLog
    {
        return $this->activityLogs()->whereNull('logout_at')->latest('login_at')->first();
    }

    /** Record a heartbeat: refresh status and last-seen timestamp. */
    public function markSeen(ComputerStatus $status): bool
    {
        return $this->forceFill([
            'status' => $status,
            'last_seen_at' => now(),
        ])->save();
    }
}
