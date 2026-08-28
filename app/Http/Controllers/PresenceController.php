<?php

namespace App\Http\Controllers;

use App\Models\Computer;
use Illuminate\Contracts\View\View;

/**
 * Admin-only real-time presence dashboard (Phase 6). Thin: it only renders the views;
 * all state and queries live in the Livewire components / presence services.
 * Access is restricted to authenticated administrators by the route middleware.
 */
class PresenceController extends Controller
{
    /** The live presence board. */
    public function index(): View
    {
        return view('presence.index');
    }

    /** Live details for a single computer. */
    public function show(Computer $computer): View
    {
        return view('presence.show', ['computer' => $computer]);
    }
}
