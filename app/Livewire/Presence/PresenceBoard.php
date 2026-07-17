<?php

namespace App\Livewire\Presence;

use App\Services\Presence\PresenceService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The real-time presence board (M7): summary cards plus one row per computer.
 *
 * Updates live over the private `presence` broadcast channel - no polling. When
 * a PresenceUpdated event arrives the component re-renders, reading only the
 * materialized presence table (never scanning agent_events).
 */
class PresenceBoard extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdministrator() ?? false, 403);
    }

    /**
     * Echo pushes PresenceUpdated on the private `presence` channel; the mere
     * arrival re-renders the component with fresh materialized state.
     */
    #[On('echo-private:presence,.PresenceUpdated')]
    public function onPresenceUpdated(): void
    {
        // No state to merge: render() re-reads the presence table.
    }

    public function render(PresenceService $presence): View
    {
        return view('livewire.presence.presence-board', [
            'summary' => $presence->summary(),
            'rows' => $presence->rows(),
        ]);
    }
}
