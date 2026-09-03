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
            ->with([
                'employee.user', 'employee.department',
                // Runtime owner for shared PCs (+ user for the name accessor and
                // department for the label). Eager-loaded to avoid N+1.
                'presence.currentEmployee.user', 'presence.currentEmployee.department',
            ])
            ->orderBy('hostname')
            ->get()
            ->map(function (Computer $computer) use ($activity) {
                $presence = $computer->presence;
                $status = $presence?->status ?? PresenceStatus::Offline;

                // Prefer the employee currently attributed to the presence row
                // (correct for shared PCs); fall back to the computer's static
                // owner for legacy rows without a materialized current employee.
                $runtimeEmployee = $presence?->currentEmployee ?? $computer->employee;

                $act = $activity->get($computer->id);
                $todayActive = (int) ($act->active ?? 0);
                $todayIdle = (int) ($act->idle ?? 0);

                return [
                    'computer_id' => $computer->id,
                    'computer_name' => $computer->hostname,
                    'employee' => $runtimeEmployee?->name,
                    'department' => $runtimeEmployee?->department?->name,
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
     * Distinct employees currently online (Active / Idle / Locked), attributed by
     * the presence row's runtime owner (current_employee_id) - so a shared PC
     * counts the person actually using it, not its static owner. An employee with
     * several computers is counted once. Legacy rows (null current_employee_id)
     * fall back to the computer's static owner.
     */
    public function onlineEmployeeCount(): int
    {
        $online = [PresenceStatus::Active, PresenceStatus::Idle, PresenceStatus::Locked];

        return $this->effectiveEmployeePresence()
            ->filter(fn (object $e): bool => in_array($e->status, $online, true))
            ->count();
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
        $presence = $this->effectiveEmployeePresence();

        return Employee::query()
            ->with(['user', 'department'])
            ->orderBy('id')
            ->get()
            ->map(function (Employee $employee) use ($activity, $presence) {
                $act = $activity->get($employee->id);
                $active = (int) ($act->active ?? 0);
                $idle = (int) ($act->idle ?? 0);
                $live = $presence->get($employee->id);

                return [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'department' => $employee->department?->name,
                    'status' => $live->status ?? PresenceStatus::Offline,
                    'active_seconds' => $active,
                    'idle_seconds' => $idle,
                    'active_label' => $this->hoursMinutes($active),
                    'idle_label' => $this->hoursMinutes($idle),
                    'active_ratio' => $this->ratio($active, $idle),
                    'last_activity_at' => $live->last_activity ?? null,
                ];
            });
    }

    /**
     * Map of employee id -> PresenceStatus for a set of employees, from the shared
     * presence source. Used by the paginated employees page so it shows the same
     * status as everywhere else. Status is attributed by the presence row's runtime
     * owner (current_employee_id), so a shared PC never shows its static owner
     * online while someone else is using it.
     *
     * @param  iterable<Employee>  $employees
     * @return array<int, PresenceStatus>
     */
    public function employeeStatusMap(iterable $employees): array
    {
        $presence = $this->effectiveEmployeePresence();

        $map = [];
        foreach ($employees as $employee) {
            $map[$employee->id] = $presence->get($employee->id)->status ?? PresenceStatus::Offline;
        }

        return $map;
    }

    /**
     * The best status and latest activity per RUNTIME employee, read only from the
     * materialized `computer_presence` table (one row per computer) - never by
     * scanning `agent_events`, so this stays O(presence rows).
     *
     * Each presence row is attributed to a single "effective" employee: its
     * current_employee_id (the runtime owner) when set, else the computer's static
     * employee_id (legacy fallback). A row therefore counts for exactly one
     * employee, so an employee is never double-counted and a shared PC's static
     * owner is not shown online while another employee currently owns the row. An
     * employee with multiple computers gets the best status across their attributed
     * rows.
     *
     * @return Collection<int, object{status: PresenceStatus, last_activity: ?Carbon}>
     */
    private function effectiveEmployeePresence(): Collection
    {
        $priority = [
            PresenceStatus::Active->value => 5,
            PresenceStatus::Idle->value => 4,
            PresenceStatus::Locked->value => 3,
            PresenceStatus::LoggedOut->value => 2,
            PresenceStatus::Offline->value => 1,
        ];

        $byEmployee = [];

        ComputerPresence::query()
            ->whereHas('computer') // exclude soft-deleted computers
            ->with('computer:id,employee_id')
            ->get()
            ->each(function (ComputerPresence $presence) use (&$byEmployee, $priority) {
                $employeeId = $presence->current_employee_id ?? $presence->computer?->employee_id;
                if ($employeeId === null) {
                    return;
                }

                $status = $presence->status;
                $lastActivity = $presence->last_activity_at ?? $presence->last_synced_at;

                if (! isset($byEmployee[$employeeId])) {
                    $byEmployee[$employeeId] = (object) [
                        'status' => $status,
                        'last_activity' => $lastActivity,
                    ];

                    return;
                }

                $current = $byEmployee[$employeeId];
                if ($priority[$status->value] > $priority[$current->status->value]) {
                    $current->status = $status;
                }
                if ($lastActivity !== null
                    && ($current->last_activity === null || $lastActivity->gt($current->last_activity))) {
                    $current->last_activity = $lastActivity;
                }
            });

        return collect($byEmployee);
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
