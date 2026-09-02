<?php

namespace App\Livewire\Presence;

use App\Livewire\Concerns\ScopesToViewer;
use App\Services\Presence\PresenceService;
use App\Services\Tenancy\MonitoringTenantAccess;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The real-time presence board (Phase 6): summary cards plus one row per computer.
 *
 * Updates live over the private `presence` broadcast channel - no polling. When
 * a PresenceChanged event arrives the component re-renders, reading only the
 * materialized presence table (never scanning agent_events).
 */
class PresenceBoard extends Component
{
    use ScopesToViewer;

    public int $organizationId;

    public function mount(MonitoringTenantAccess $tenant): void
    {
        $this->authorizeViewer();
        $this->organizationId = $tenant->organizationId(auth()->user());
    }

    /**
     * Echo pushes PresenceChanged on the private `presence` channel; the mere
     * arrival re-renders the component with fresh materialized state.
     */
    public function getListeners(): array
    {
        return [
            "echo-private:organization.{$this->organizationId}.presence,.PresenceChanged" => 'onPresenceChanged',
        ];
    }

    public function onPresenceChanged(): void
    {
        // No state to merge: render() re-reads the presence table.
    }

    public function render(PresenceService $presence, MonitoringTenantAccess $tenant): View
    {
        $user = auth()->user();
        $computerIds = $tenant->visibleComputerIds($user);
        $organizationId = $tenant->organizationId($user);

        return view('livewire.presence.presence-board', [
            'summary' => $presence->summary($computerIds, $organizationId),
            'rows' => $presence->rows($computerIds, $organizationId),
        ]);
    }
}
