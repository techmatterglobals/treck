<?php

namespace App\Livewire\Presence;

use App\Livewire\Concerns\ScopesToViewer;
use App\Services\Hierarchy\EmployeeVisibility;
use App\Services\Presence\PresenceService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
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

    public function mount(): void
    {
        $this->authorizeViewer();
    }

    /**
     * Echo pushes PresenceChanged on the private `presence` channel; the mere
     * arrival re-renders the component with fresh materialized state.
     */
    #[On('echo-private:presence,.PresenceChanged')]
    public function onPresenceChanged(): void
    {
        // No state to merge: render() re-reads the presence table.
    }

    public function render(PresenceService $presence, EmployeeVisibility $visibility): View
    {
        // Super Admin: null → whole organization. Manager: only their team's
        // computers (Phase 11).
        $computerIds = $visibility->computerIds(auth()->user());

        return view('livewire.presence.presence-board', [
            'summary' => $presence->summary($computerIds),
            'rows' => $presence->rows($computerIds),
        ]);
    }
}
