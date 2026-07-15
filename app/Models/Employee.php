<?php

namespace App\Models;

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
}
