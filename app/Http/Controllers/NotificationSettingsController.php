<?php

namespace App\Http\Controllers;

use App\Models\NotificationLog;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Admin-only notification settings (Phase 9). Thin: renders the settings view;
 * the Livewire component reads/writes the notification_rules + the current
 * admin's notification_preferences. Gated by NotificationPolicy::manage.
 */
class NotificationSettingsController extends Controller
{
    use AuthorizesRequests;

    public function index(): View
    {
        $this->authorize('manage', NotificationLog::class);

        return view('notifications.settings');
    }
}
