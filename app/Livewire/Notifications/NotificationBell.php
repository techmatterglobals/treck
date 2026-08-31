<?php

namespace App\Livewire\Notifications;

use App\Models\NotificationLog;
use App\Services\Tenancy\MonitoringTenantAccess;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Live notification bell (Phase 9): unread badge + recent-unread dropdown for the
 * current admin. Updates in real time over the recipient's private channel
 * (`notifications.user.{id}`) — no polling. Reads only the current user's in-app
 * inbox.
 */
class NotificationBell extends Component
{
    public int $userId;

    public int $organizationId;

    public function mount(): void
    {
        $user = auth()->user();
        $tenant = app(MonitoringTenantAccess::class);

        abort_unless($user && $tenant->canManageMonitoring($user), 403);

        $this->userId = (int) auth()->id();
        $this->organizationId = $tenant->organizationId($user);
    }

    /** Echo pushes NotificationCreated on the recipient's private channel. */
    public function getListeners(): array
    {
        return [
            "echo-private:notifications.user.{$this->userId},.NotificationCreated" => '$refresh',
        ];
    }

    public function markAllRead(): void
    {
        NotificationLog::forRecipient($this->userId)
            ->forOrganization($this->organizationId)
            ->inApp()
            ->unread()
            ->update(['read_at' => now()]);
    }

    public function render(): View
    {
        $base = NotificationLog::forRecipient($this->userId)
            ->forOrganization($this->organizationId)
            ->inApp();

        return view('livewire.notifications.notification-bell', [
            'unreadCount' => (clone $base)->unread()->count(),
            'recent' => (clone $base)->latest()->limit(8)->get(),
        ]);
    }
}
