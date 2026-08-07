<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\PresenceStatus;
use App\Services\Presence\PresenceProjector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The materialized current presence of one computer (Phase 6). Written by
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
            // Agent-sourced instants are stored as UTC digits; read them as UTC
            // so the true instant survives regardless of APP_TIMEZONE.
            'last_heartbeat_at' => UtcDateTime::class,
            'last_activity_at' => UtcDateTime::class,
            'last_event_at' => UtcDateTime::class,
            'session_started_at' => UtcDateTime::class,
            // last_synced_at mirrors the server's now() (received_at), so it is
            // stored in the app timezone and read back with the default cast.
            'last_synced_at' => 'datetime',
            'idle_seconds' => 'integer',
        ];
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }

    /**
     * Rows currently counted as "online" (Active / Idle / Locked). The column is
     * table-qualified so the scope is safe when joined to `computers` (which also
     * has a `status` column).
     */
    public function scopeOnline(Builder $query): Builder
    {
        return $query->whereIn('computer_presence.status', [
            PresenceStatus::Active->value,
            PresenceStatus::Idle->value,
            PresenceStatus::Locked->value,
        ]);
    }
}
