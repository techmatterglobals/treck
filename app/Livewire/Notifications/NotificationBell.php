<?php

namespace App\Livewire\Notifications;

use App\Models\NotificationLog;
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

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdministrator() ?? false, 403);

        $this->userId = (int) auth()->id();
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
        NotificationLog::forRecipient($this->userId)->inApp()->unread()->update(['read_at' => now()]);
    }

    public function render(): View
    {
        $base = NotificationLog::forRecipient($this->userId)->inApp();

        return view('livewire.notifications.notification-bell', [
            'unreadCount' => (clone $base)->unread()->count(),
            'recent' => (clone $base)->latest()->limit(8)->get(),
        ]);
    }
}
