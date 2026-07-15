<?php

namespace App\Models;

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
        ];
    }

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
}
