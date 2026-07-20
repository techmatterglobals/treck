<?php

namespace App\Services\Presence;

use App\Models\AgentEvent;
use App\Models\ApplicationUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Projects one completed application-usage event (Phase 7) into the
 * `application_usage` table. Called from the ingest pipeline only for newly
 * stored `app_usage` events, so it runs once per unique event; it is
 * additionally idempotent on (computer_id, session_id).
 *
 * The agent sends completed sessions (not per-second samples) with PascalCase
 * keys: SessionId, ProcessName, ExecutableName, WindowTitle, ProcessId,
 * StartedAt, EndedAt, DurationSeconds, UserSession, IsSystemProcess. Only usage
 * metadata is stored — never input, clipboard, screen, or file contents.
 */
class ApplicationUsageProjector
{
    /** Max stored window-title length (matches the string column). */
    private const TITLE_MAX = 255;

    public function project(AgentEvent $event): ?ApplicationUsage
    {
        $payload = $event->payload ?? [];

        // Session identity: prefer the agent's GUID, else the event's
        // idempotency key so the row is still deduplicated per computer.
        $sessionId = (string) ($this->value($payload, ['SessionId', 'session_id'], $event->idempotency_key));

        $processName = trim((string) $this->value($payload, ['ProcessName', 'process_name'], ''));
        $executable = $this->value($payload, ['ExecutableName', 'executable_name', 'Executable', 'executable'], null);
        $applicationName = $processName !== '' ? $processName : (string) ($executable ?? 'Unknown');

        $startedAt = $this->toTime($this->value($payload, ['StartedAt', 'started_at'], null)) ?? $event->occurred_at;
        $endedAt = $this->toTime($this->value($payload, ['EndedAt', 'ended_at'], null));

        $duration = (int) $this->value($payload, ['DurationSeconds', 'duration_seconds'], 0);
        if ($duration <= 0 && $startedAt && $endedAt) {
            $duration = max(0, $startedAt->diffInSeconds($endedAt));
        }

        return ApplicationUsage::firstOrCreate(
            [
                'computer_id' => $event->computer_id,
                'session_id' => $sessionId,
            ],
            [
                'employee_id' => $event->employee_id,
                'application_name' => $this->sanitize($applicationName, 191),
                'executable' => $executable !== null ? $this->sanitize((string) $executable, 191) : null,
                'window_title' => $this->sanitize((string) $this->value($payload, ['WindowTitle', 'window_title'], ''), self::TITLE_MAX),
                'used_at' => $startedAt,
                'ended_at' => $endedAt,
                'duration_seconds' => $duration,
            ],
        );
    }

    /** Strip control characters and truncate to the column limit. */
    private function sanitize(string $value, int $max): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';

        return Str::limit(trim($clean), $max, '');
    }

    private function toTime(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * First present payload key (case-tolerant), else a default.
     *
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $keys
     */
    private function value(array $payload, array $keys, mixed $default): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return $payload[$key];
            }
        }

        return $default;
    }
}
