<?php

namespace App\Models;

use App\Enums\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductivityReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'period_type',
        'period_date',
        'active_seconds',
        'productive_seconds',
        'unproductive_seconds',
        'neutral_seconds',
        'productivity_score',
    ];

    protected function casts(): array
    {
        return [
            'period_type' => ReportPeriod::class,
            'period_date' => 'date',
            'productivity_score' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeOfType(Builder $query, ReportPeriod $type): Builder
    {
        return $query->where('period_type', $type->value);
    }

    public function scopeForEmployee(Builder $query, int $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('period_date', [$from, $to]);
    }
}
