<?php

namespace App\Services\Presence;

use App\Enums\AgentEventKind;
use App\Enums\PresenceStatus;
use App\Models\Computer;
use App\Models\ComputerPresence;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read model + maintenance for the real-time presence dashboard (Phase 6).
 *
 * This is the SINGLE source of truth for displayed presence/status across the
 * whole app (presence board, dashboard KPIs + status table, employees page).
 * Live status is read ONLY from the materialized `computer_presence` table (one
 * row per computer) - never derived by scanning `agent_events` - so reads stay
 * O(computers). Today's active/idle *time* is the one aggregate that must sum
 * events, bounded to today. The stored status is authoritative; {@see
 * sweepOffline()} keeps it fresh by transitioning quiet machines to Offline.
 */
class PresenceService
{
    // ---- Read model --------------------------------------------------------

    /**
     * Summary counts for the dashboard cards. Computers without a presence row
     * (never reported) count as Offline.
     *
     * When $computerIds is provided (Phase 11 manager scoping), the counts cover
     * only those computers; null (the default) keeps the organization-wide view.
     *
     * @param  list<int>|null  $computerIds
     * @return array{total:int,online:int,offline:int,active:int,idle:int,locked:int,logged_out:int}
     */
    public function summary(?array $computerIds = null): array
    {
        $counts = ComputerPresence::query()
            ->when($computerIds !== null, fn ($q) => $q->whereIn('computer_id', $computerIds ?: [0]))
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $of = fn (PresenceStatus $s): int => (int) ($counts[$s->value] ?? 0);

        $total = Computer::query()
            ->when($computerIds !== null, fn ($q) => $q->whereIn('id', $computerIds ?: [0]))
            ->count();
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
     * When $computerIds is provided (Phase 11 manager scoping), only those
     * computers are listed; null (the default) lists every computer.
     *
     * @param  list<int>|null  $computerIds
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?array $computerIds = null): Collection
    {
        // Today's accumulated active/idle per computer, so the board shows the
        // same meaningful "Idle time" the dashboard does (the materialized
        // idle_seconds is only the current streak, and is reset to 0 on offline).
        $activity = $this->todaysActivityByComputer();

        return Computer::query()
            ->when($computerIds !== null, fn ($q) => $q->whereIn('id', $computerIds ?: [0]))
            ->with(['employee.department', 'presence'])
            ->orderBy('hostname')
            ->get()
            ->map(function (Computer $computer) use ($activity) {
                $presence = $computer->presence;
                $status = $presence?->status ?? PresenceStatus::Offline;

                $act = $activity->get($computer->id);
                $todayActive = (int) ($act->active ?? 0);
                $todayIdle = (int) ($act->idle ?? 0);

                return [
                    'computer_id' => $computer->id,
                    'computer_name' => $computer->hostname,
                    'employee' => $computer->employee?->name,
                    'department' => $computer->employee?->department?->name,
                    'status' => $status,
                    'last_heartbeat_at' => $presence?->last_heartbeat_at,
                    'last_activity_at' => $presence?->last_activity_at,
                    'active_seconds' => $todayActive,
                    'active_label' => $this->hoursMinutes($todayActive),
                    'idle_seconds' => $todayIdle,
                    'idle_label' => $this->hoursMinutes($todayIdle),
                ];
            });
    }

    // ---- Employee-level read model (shared by dashboard + employees) -------

    /**
     * Distinct employees with at least one computer currently online
     * (Active / Idle / Locked) - the same rule the presence board uses.
     */
    public function onlineEmployeeCount(): int
    {
        return (int) ComputerPresence::query()
            ->online()
            ->join('computers', 'computers.id', '=', 'computer_presence.computer_id')
            ->whereNull('computers.deleted_at')
            ->whereNotNull('computers.employee_id')
            ->distinct()
            ->count('computers.employee_id');
    }

    /**
     * One row per employee for the dashboard status table, derived from the
     * presence source: best status across the employee's computers, last
     * activity, and today's active/idle time.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function employeeRows(): Collection
    {
        $activity = $this->todaysActivityByEmployee();

        return Employee::query()
            ->with(['user', 'department', 'computers.presence'])
            ->orderBy('id')
            ->get()
            ->map(function (Employee $employee) use ($activity) {
                $act = $activity->get($employee->id);
                $active = (int) ($act->active ?? 0);
                $idle = (int) ($act->idle ?? 0);

                return [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'department' => $employee->department?->name,
                    'status' => $this->bestStatus($employee->computers),
                    'active_seconds' => $active,
                    'idle_seconds' => $idle,
                    'active_label' => $this->hoursMinutes($active),
                    'idle_label' => $this->hoursMinutes($idle),
                    'active_ratio' => $this->ratio($active, $idle),
                    'last_activity_at' => $this->employeeLastActivity($employee),
                ];
            });
    }

    /**
     * Map of employee id -> PresenceStatus for an already-loaded set of
     * employees (their `computers.presence` must be eager-loaded). Used by the
     * paginated employees page so it shows the same status as everywhere else.
     *
     * @param  iterable<Employee>  $employees
     * @return array<int, PresenceStatus>
     */
    public function employeeStatusMap(iterable $employees): array
    {
        $map = [];
        foreach ($employees as $employee) {
            $map[$employee->id] = $this->bestStatus($employee->computers);
        }

        return $map;
    }

    /** The most-connected presence status across a set of computers. */
    public function bestStatus(Collection $computers): PresenceStatus
    {
        $priority = [
            PresenceStatus::Active->value => 5,
            PresenceStatus::Idle->value => 4,
            PresenceStatus::Locked->value => 3,
            PresenceStatus::LoggedOut->value => 2,
            PresenceStatus::Offline->value => 1,
        ];

        $best = PresenceStatus::Offline;

        foreach ($computers as $computer) {
            $status = $computer->presence?->status ?? PresenceStatus::Offline;
            if ($priority[$status->value] > $priority[$best->value]) {
                $best = $status;
            }
        }

        return $best;
    }

    /**
     * Today's active/idle seconds per employee, summed from today's heartbeat
     * events (the only place per-interval activity lives now). Keyed by
     * employee_id. This is the one bounded event aggregate the read model runs.
     *
     * @return Collection<int, object>
     */
    public function todaysActivityByEmployee(): Collection
    {
        return DB::table('agent_events')
            ->where('kind', AgentEventKind::Heartbeat->value)
            ->whereDate('occurred_at', today())
            ->whereNotNull('employee_id')
            ->groupBy('employee_id')
            ->selectRaw("employee_id,
                COALESCE(SUM(json_extract(payload, '$.ActiveSeconds')), 0) as active,
                COALESCE(SUM(json_extract(payload, '$.IdleSeconds')), 0) as idle")
            ->get()
            ->keyBy('employee_id');
    }

    /**
     * Today's active/idle seconds per computer, summed from today's heartbeat
     * events. Keyed by computer_id. The per-computer counterpart of
     * {@see todaysActivityByEmployee()} used by the Live Presence board.
     *
     * @return Collection<int, object>
     */
    public function todaysActivityByComputer(): Collection
    {
        return DB::table('agent_events')
            ->where('kind', AgentEventKind::Heartbeat->value)
            ->whereDate('occurred_at', today())
            ->whereNotNull('computer_id')
            ->groupBy('computer_id')
            ->selectRaw("computer_id,
                COALESCE(SUM(json_extract(payload, '$.ActiveSeconds')), 0) as active,
                COALESCE(SUM(json_extract(payload, '$.IdleSeconds')), 0) as idle")
            ->get()
            ->keyBy('computer_id');
    }

    /** Latest activity across an employee's computers (last active, else last contact). */
    private function employeeLastActivity(Employee $employee): ?Carbon
    {
        return $employee->computers
            ->map(fn (Computer $c) => $c->presence?->last_activity_at ?? $c->presence?->last_synced_at)
            ->filter()
            ->max();
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

    /** Active-ratio percentage (active / active+idle). */
    private function ratio(int $active, int $idle): float
    {
        $total = $active + $idle;

        return $total > 0 ? round($active / $total * 100, 1) : 0.0;
    }

    /** "Hh MMm" label for a duration in seconds. */
    private function hoursMinutes(int $seconds): string
    {
        return sprintf('%dh %02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
