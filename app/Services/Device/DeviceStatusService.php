<?php

namespace App\Services\Device;

use App\Enums\ComputerStatus;
use App\Enums\SessionEndReason;
use App\Models\Computer;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Resolves online/offline status and last-activity for computers and employees,
 * and reconciles sessions abandoned by crashed agents.
 *
 * The stored `computers.status` is the last self-reported state; this service
 * layers a staleness check on top so a computer whose agent stopped reporting
 * is treated as offline even if it never sent a logout.
 */
class DeviceStatusService
{
    /**
     * Effective status of a computer: offline if we haven't heard from it within
     * the grace window, otherwise its last self-reported status.
     */
    public function resolve(Computer $computer): ComputerStatus
    {
        if ($this->isStale($computer)) {
            return ComputerStatus::Offline;
        }

        return $computer->status ?? ComputerStatus::Offline;
    }

    public function isOnline(Computer $computer): bool
    {
        return $this->resolve($computer)->isConnected();
    }

    /** True if the agent has gone quiet longer than the offline grace window. */
    public function isStale(Computer $computer): bool
    {
        if ($computer->last_seen_at === null) {
            return true;
        }

        return $computer->last_seen_at->lt(now()->subSeconds($this->graceSeconds()));
    }

    /**
     * An employee is online if any of their assigned computers is connected.
     * Returns the "most active" status across their machines.
     */
    public function employeeStatus(Employee $employee): ComputerStatus
    {
        $priority = [
            ComputerStatus::Online->value => 4,
            ComputerStatus::Idle->value => 3,
            ComputerStatus::Locked->value => 2,
            ComputerStatus::Offline->value => 1,
        ];

        $best = ComputerStatus::Offline;

        foreach ($employee->computers as $computer) {
            $status = $this->resolve($computer);
            if ($priority[$status->value] > $priority[$best->value]) {
                $best = $status;
            }
        }

        return $best;
    }

    public function employeeIsOnline(Employee $employee): bool
    {
        return $this->employeeStatus($employee)->isConnected();
    }

    /** The most recent real-activity timestamp across an employee's computers. */
    public function lastActivityAt(Employee $employee): ?Carbon
    {
        return $employee->computers
            ->pluck('last_activity_at')
            ->filter()
            ->max();
    }

    /**
     * Sweep computers that have gone stale: mark them offline and close any
     * still-open session with a `timeout` reason. Returns the number swept.
     * Intended to run on a schedule (see ReconcileStaleSessions command).
     */
    public function reconcileStale(): int
    {
        $cutoff = now()->subSeconds($this->graceSeconds());

        $stale = Computer::query()
            ->where('status', '!=', ComputerStatus::Offline->value)
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $cutoff))
            ->get();

        foreach ($stale as $computer) {
            DB::transaction(function () use ($computer) {
                $computer->openSession()?->close(SessionEndReason::Timeout);
                $computer->forceFill(['status' => ComputerStatus::Offline])->save();
            });
        }

        return $stale->count();
    }

    private function graceSeconds(): int
    {
        return (int) config('treck.activity.offline_grace_seconds', 180);
    }
}
