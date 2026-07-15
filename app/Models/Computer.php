<?php

namespace App\Models;

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
            'last_seen_at' => 'datetime',
            'paired_at' => 'datetime',
        ];
    }

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
}
