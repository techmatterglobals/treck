<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentHealthReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'computer_id',
        'agent_version',
        'config_revision',
        'pending_event_count',
        'helper_running',
        'helper_session_id',
        'service_started_at',
        'last_capture_at',
        'last_successful_sync_at',
        'last_error_category',
        'reported_at',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'pending_event_count' => 'integer',
            'helper_running' => 'boolean',
            'helper_session_id' => 'integer',
            'service_started_at' => UtcDateTime::class,
            'last_capture_at' => UtcDateTime::class,
            'last_successful_sync_at' => UtcDateTime::class,
            'reported_at' => UtcDateTime::class,
            'received_at' => 'datetime',
        ];
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }
}
