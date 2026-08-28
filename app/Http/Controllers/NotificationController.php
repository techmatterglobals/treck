<?php

namespace App\Http\Controllers;

use App\Models\NotificationLog;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Admin-only notifications dashboard (Phase 9). Thin: it only renders the view;
 * listing, filtering, stats and mark-read live in the Livewire component and the
 * notification services. Access is gated by route middleware + NotificationPolicy.
 */
class NotificationController extends Controller
{
    use AuthorizesRequests;

    public function index(): View
    {
        $this->authorize('viewAny', NotificationLog::class);

        return view('notifications.index');
    }
}
