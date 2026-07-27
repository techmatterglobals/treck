<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps one Windows account seen on a computer to the employee who owns it
 * (Phase 11 — shared-computer support). A single physical computer can carry
 * many of these rows (one per Windows username), letting different employees
 * share the machine across shifts while every event is attributed correctly.
 *
 * `employee_id` may be null: an unrecognized Windows account is recorded as a
 * *pending* mapping so events are never lost, and the Super Admin maps it to an
 * employee later.
 */
class ComputerUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'computer_id',
        'employee_id',
        'windows_username',
        'last_seen_at',
        'last_login_at',
        'last_logout_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_logout_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    // ----------------------------------------------------------------
    // Relationships
    // ----------------------------------------------------------------

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    // ----------------------------------------------------------------
    // Scopes / helpers
    // ----------------------------------------------------------------

    /** Mappings still awaiting an employee assignment. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('employee_id');
    }

    /** Whether this Windows account has not yet been mapped to an employee. */
    public function isPending(): bool
    {
        return $this->employee_id === null;
    }
}
