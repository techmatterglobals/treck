<?php

namespace App\Models;

use App\Enums\NotificationSeverity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-administrator notification preferences (Phase 9). A missing row means
 * defaults: all channels, info+ severity, immediate (no digest), no quiet hours.
 */
class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id', 'channels', 'min_severity', 'digest', 'quiet_hours_start', 'quiet_hours_end',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'digest' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function minSeverity(): NotificationSeverity
    {
        return NotificationSeverity::tryFrom((string) $this->min_severity) ?? NotificationSeverity::Info;
    }

    public function allowsChannel(string $channel): bool
    {
        return in_array($channel, $this->channels ?? [], true);
    }

    /** True when "now" falls inside the configured quiet-hours window (if any). */
    public function inQuietHours(?Carbon $now = null): bool
    {
        if (blank($this->quiet_hours_start) || blank($this->quiet_hours_end)) {
            return false;
        }

        $now ??= now();
        $current = $now->format('H:i:s');
        $start = (string) $this->quiet_hours_start;
        $end = (string) $this->quiet_hours_end;

        // Window that does not cross midnight (e.g. 22:00–23:30).
        if ($start <= $end) {
            return $current >= $start && $current < $end;
        }

        // Window that crosses midnight (e.g. 22:00–07:00).
        return $current >= $start || $current < $end;
    }
}
