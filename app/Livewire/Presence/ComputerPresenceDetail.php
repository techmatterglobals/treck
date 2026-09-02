<?php

namespace App\Livewire\Presence;

use App\Enums\AgentEventKind;
use App\Enums\PresenceStatus;
use App\Models\AgentEvent;
use App\Models\Computer;
use App\Services\Presence\PresenceService;
use App\Services\Reporting\ApplicationUsageService;
use App\Services\Tenancy\MonitoringTenantAccess;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Real-time details for a single computer (Phase 6): current presence, current
 * session duration, last sync, idle duration, and the most recent session and
 * heartbeat events (bounded reads for audit context - not a full history scan).
 *
 * Updates live over the private `presence.computer.{id}` channel - no polling.
 */
class ComputerPresenceDetail extends Component
{
    public int $computerId;

    public int $organizationId;

    public function mount(Computer $computer, MonitoringTenantAccess $tenant): void
    {
        $user = auth()->user();
        abort_unless($user && $tenant->canViewMonitoring($user), 403);

        $computer = $tenant->computer($computer, $user);
        abort_unless($tenant->canSeeComputer($user, $computer->id), 403);

        $this->computerId = $computer->id;
        $this->organizationId = (int) $computer->organization_id;
    }

    /** Dynamic Echo listener bound to this computer's private channel. */
    public function getListeners(): array
    {
        return [
            "echo-private:organization.{$this->organizationId}.presence.computer.{$this->computerId},.PresenceChanged" => 'onPresenceChanged',
        ];
    }

    public function onPresenceChanged(): void
    {
        // Arrival re-renders with fresh materialized state + recent events.
    }

    /** Human-readable duration label (delegates to the presence service). */
    public function duration(int $seconds): string
    {
        return app(PresenceService::class)->duration($seconds);
    }

    public function render(ApplicationUsageService $appUsage, MonitoringTenantAccess $tenant): View
    {
        $computer = $tenant->computer($this->computerId, auth()->user())
            ->load(['employee.department', 'presence']);

        // Application usage (Phase 7): the most recent completed session is the
        // "current" application, plus recent history and today's per-app summary.
        $currentApp = $appUsage->currentApplication($computer);
        $recentApps = $appUsage->recentForComputer($computer);
        $dailyApps = $appUsage->dailySummaryForComputer($computer);

        $recentSessions = AgentEvent::query()
            ->forOrganization($computer->organization_id)
            ->where('computer_id', $computer->id)
            ->where('kind', AgentEventKind::Session->value)
            ->latest('occurred_at')
            ->limit(10)
            ->get();

        $recentHeartbeats = AgentEvent::query()
            ->forOrganization($computer->organization_id)
            ->where('computer_id', $computer->id)
            ->where('kind', AgentEventKind::Heartbeat->value)
            ->latest('occurred_at')
            ->limit(10)
            ->get();

        $presence = $computer->presence;
        $status = $presence?->status ?? PresenceStatus::Offline;

        $sessionSeconds = $presence?->session_started_at
            ? $presence->session_started_at->diffInSeconds(now())
            : null;

        return view('livewire.presence.computer-presence-detail', [
            'computer' => $computer,
            'presence' => $presence,
            'status' => $status,
            'recentSessions' => $recentSessions,
            'recentHeartbeats' => $recentHeartbeats,
            'sessionSeconds' => $sessionSeconds,
            'currentApp' => $currentApp,
            'recentApps' => $recentApps,
            'dailyApps' => $dailyApps,
        ]);
    }
}
