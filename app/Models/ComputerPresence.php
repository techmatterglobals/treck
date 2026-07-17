<?php

namespace App\Models;

use App\Enums\PresenceStatus;
use App\Services\Presence\PresenceProjector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The materialized current presence of one computer (M7). Written by
 * {@see PresenceProjector} and read by the dashboard;
 * never derived by scanning `agent_events`.
 */
class ComputerPresence extends Model
{
    use HasFactory;

    protected $table = 'computer_presence';

    protected $fillable = [
        'computer_id',
        'status',
        'last_heartbeat_at',
        'last_activity_at',
        'last_event_at',
        'last_synced_at',
        'idle_seconds',
        'session_started_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PresenceStatus::class,
            'last_heartbeat_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'last_event_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'session_started_at' => 'datetime',
            'idle_seconds' => 'integer',
        ];
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }

    /** Rows currently counted as "online" (Active / Idle / Locked). */
    public function scopeOnline(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PresenceStatus::Active->value,
            PresenceStatus::Idle->value,
            PresenceStatus::Locked->value,
        ]);
    }
}
