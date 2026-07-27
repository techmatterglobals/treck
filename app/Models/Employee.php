<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'manager_user_id',
        'department_id',
        'employee_code',
        'designation',
        'status',
        'phone',
        'joined_on',
    ];

    protected function casts(): array
    {
        return [
            'joined_on' => 'date',
        ];
    }

    // ----------------------------------------------------------------
    // Relationships
    // ----------------------------------------------------------------

    /** The login account for this employee (N:1, effectively 1:1). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The department this employee belongs to (N:1). */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The supervising Manager (a user), Phase 11. Null when unassigned. This is
     * the direct hierarchy link (employees.manager_user_id) — independent of the
     * department's manager.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /** Per-Windows-account mappings on this employee's shared computers (1:N). */
    public function computerUsers(): HasMany
    {
        return $this->hasMany(ComputerUser::class);
    }

    /** Workstations assigned to this employee (1:N). */
    public function computers(): HasMany
    {
        return $this->hasMany(Computer::class);
    }

    /** Daily attendance records (1:N). */
    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /** PC login/logout sessions (1:N). */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /** Application usage records (1:N). */
    public function applicationUsage(): HasMany
    {
        return $this->hasMany(ApplicationUsage::class);
    }

    /** Captured screenshots (1:N). */
    public function screenshots(): HasMany
    {
        return $this->hasMany(Screenshot::class);
    }

    // ----------------------------------------------------------------
    // Accessors
    // ----------------------------------------------------------------

    /** Employee display name, proxied from the linked user. */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->user?->name,
        );
    }

    /** Employee email, proxied from the linked user. */
    protected function email(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->user?->email,
        );
    }

    // ----------------------------------------------------------------
    // Scopes
    // ----------------------------------------------------------------

    /** Employees in a given department. */
    public function scopeInDepartment(Builder $query, int $departmentId): Builder
    {
        return $query->where('department_id', $departmentId);
    }

    /** Employees supervised by a given manager (Phase 11). */
    public function scopeManagedBy(Builder $query, User|int $manager): Builder
    {
        $id = $manager instanceof User ? $manager->id : $manager;

        return $query->where('manager_user_id', $id);
    }

    /**
     * Restrict the query to the employees a user may see (Phase 11):
     *   - Super Admin  → all employees (no restriction)
     *   - Manager      → only their assigned employees
     *   - Employee     → only their own profile
     *   - anyone else  → none
     *
     * Uses indexed columns (manager_user_id / user_id); it never scans events.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isManager()) {
            return $query->where('manager_user_id', $user->id);
        }

        // A plain employee sees only their own row; the Super Admin and any other
        // privileged viewer (pre-Phase-11 permission-holders) are unrestricted.
        if ($user->isEmployee() && ! $user->isSuperAdmin()) {
            return $query->where('user_id', $user->id);
        }

        return $query;
    }

    /** Search by employee code, or by the linked user's name/email. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        // Grouped so the OR conditions don't leak past other where clauses.
        return $query->where(function (Builder $q) use ($term) {
            $q->where('employee_code', 'like', "%{$term}%")
                ->orWhereHas('user', fn (Builder $u) => $u
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%"));
        });
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /** The employee's currently open PC session, if any. */
    public function openSession(): ?ActivityLog
    {
        return $this->activityLogs()->whereNull('logout_at')->latest('login_at')->first();
    }

    /** Attendance record for a specific date (defaults to today). */
    public function attendanceFor(?string $date = null): ?Attendance
    {
        return $this->attendance()
            ->whereDate('work_date', $date ?? today())
            ->first();
    }

    /**
     * Whether any of the employee's computers is currently online, read from the
     * shared presence source (Active/Idle/Locked) so it matches the presence
     * board and dashboard exactly.
     */
    public function isOnline(): bool
    {
        return $this->computers()
            ->whereHas('presence', fn (Builder $q) => $q->online())
            ->exists();
    }
}
