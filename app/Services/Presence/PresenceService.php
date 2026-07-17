<?php

namespace App\Services\Presence;

use App\Enums\PresenceStatus;
use App\Models\Computer;
use App\Models\ComputerPresence;
use Illuminate\Support\Collection;

/**
 * Read model + maintenance for the real-time presence dashboard (M7).
 *
 * Every method reads ONLY the materialized `computer_presence` table (one row
 * per computer) - never `agent_events` - so the dashboard stays O(computers),
 * not O(events). The stored status is authoritative; {@see sweepOffline()} keeps
 * it fresh by transitioning quiet machines to Offline.
 */
class PresenceService
{
    // ---- Read model --------------------------------------------------------

    /**
     * Summary counts for the dashboard cards. Computers without a presence row
     * (never reported) count as Offline.
     *
     * @return array{total:int,online:int,offline:int,active:int,idle:int,locked:int,logged_out:int}
     */
    public function summary(): array
    {
        $counts = ComputerPresence::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $of = fn (PresenceStatus $s): int => (int) ($counts[$s->value] ?? 0);

        $total = Computer::count();
        $withPresence = (int) $counts->sum();

        $active = $of(PresenceStatus::Active);
        $idle = $of(PresenceStatus::Idle);
        $locked = $of(PresenceStatus::Locked);
        $loggedOut = $of(PresenceStatus::LoggedOut);
        $offline = $of(PresenceStatus::Offline) + max(0, $total - $withPresence);

        return [
            'total' => $total,
            'online' => $active + $idle + $locked,
            'offline' => $offline,
            'active' => $active,
            'idle' => $idle,
            'locked' => $locked,
            'logged_out' => $loggedOut,
        ];
    }

    /**
     * One row per computer for the board, ordered by hostname. Reads the
     * materialized presence (defaulting to Offline when a computer has never
     * reported).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(): Collection
    {
        return Computer::query()
            ->with(['employee.department', 'presence'])
            ->orderBy('hostname')
            ->get()
            ->map(function (Computer $computer) {
                $presence = $computer->presence;
                $status = $presence?->status ?? PresenceStatus::Offline;
                $idle = (int) ($presence?->idle_seconds ?? 0);

                return [
                    'computer_id' => $computer->id,
                    'computer_name' => $computer->hostname,
                    'employee' => $computer->employee?->name,
                    'department' => $computer->employee?->department?->name,
                    'status' => $status,
                    'last_heartbeat_at' => $presence?->last_heartbeat_at,
                    'last_activity_at' => $presence?->last_activity_at,
                    'idle_seconds' => $idle,
                    'idle_label' => $this->duration($idle),
                ];
            });
    }

    // ---- Maintenance -------------------------------------------------------

    /**
     * Transition online computers that have gone quiet past the configured
     * timeout to Offline. Returns the presences that changed so the caller can
     * broadcast each one. Reads/writes only the presence table.
     *
     * @return Collection<int, ComputerPresence>
     */
    public function sweepOffline(): Collection
    {
        $cutoff = now()->subSeconds($this->offlineTimeoutSeconds());

        $stale = ComputerPresence::query()
            ->online()
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', $cutoff))
            ->get();

        $stale->each(function (ComputerPresence $presence) {
            $presence->status = PresenceStatus::Offline;
            $presence->idle_seconds = 0;
            $presence->save();
        });

        return $stale;
    }

    // ---- Helpers -----------------------------------------------------------

    /** Compact H/M/S duration label for an idle span. */
    public function duration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }

        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return trim(($h > 0 ? "{$h}h " : '').($m > 0 ? "{$m}m " : '').($h > 0 ? '' : "{$s}s"));
    }

    public function offlineTimeoutSeconds(): int
    {
        return (int) config('treck.presence.offline_timeout_seconds', 180);
    }
}
