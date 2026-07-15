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

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'department_id',
        'employee_code',
        'designation',
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

    /** Search by employee code, or by the linked user's name/email. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where('employee_code', 'like', "%{$term}%")
            ->orWhereHas('user', fn (Builder $q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%"));
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

    /** Whether any of the employee's computers is currently connected. */
    public function isOnline(): bool
    {
        return $this->computers()
            ->whereIn('status', [
                ComputerStatus::Online->value,
                ComputerStatus::Idle->value,
                ComputerStatus::Locked->value,
            ])
            ->exists();
    }
}
