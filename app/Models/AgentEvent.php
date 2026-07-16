<?php

namespace App\Models;

use App\Enums\AgentEventKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One acknowledged event drained from a desktop agent's offline queue (M6).
 *
 * The row is the server's durable receipt: it exists only after the ingest
 * transaction committed, which is exactly what the agent waits for before it
 * deletes the event from its local SQLite queue. `payload` is stored verbatim
 * as the agent produced it so later milestones can project it into the domain
 * tables without a second round-trip to the device.
 */
class AgentEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'computer_id',
        'employee_id',
        'kind',
        'idempotency_key',
        'payload',
        'occurred_at',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => AgentEventKind::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    // ----------------------------------------------------------------
    // Relationships
    // ----------------------------------------------------------------

    /** The computer (device token holder) that submitted the event. */
    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }

    /** The employee the submitting computer was assigned to at ingest time. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    // ----------------------------------------------------------------
    // Scopes
    // ----------------------------------------------------------------

    /** Filter by event kind. */
    public function scopeOfKind(Builder $query, AgentEventKind $kind): Builder
    {
        return $query->where('kind', $kind->value);
    }
}
