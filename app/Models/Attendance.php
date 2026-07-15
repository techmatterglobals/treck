<?php

namespace App\Models;

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
            'is_corrected' => 'boolean',
        ];
    }

    /** The employee this attendance row belongs to (N:1). */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
