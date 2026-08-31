<?php

namespace App\Livewire\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\NotificationLog;
use App\Services\Tenancy\MonitoringTenantAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin notifications dashboard (Phase 9): recent/unread/critical, full history
 * with severity/status/search/date filters, and headline stats. Reads only the
 * current admin's in-app inbox (recipient-scoped). Updates live over the
 * recipient's private channel — no polling.
 */
class NotificationDashboard extends Component
{
    use WithPagination;

    public int $userId;

    public int $organizationId;

    #[Url]
    public string $severity = '';

    #[Url]
    public string $status = ''; // '', 'unread', 'read'

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $user = auth()->user();
        $tenant = app(MonitoringTenantAccess::class);

        abort_unless($user && $tenant->canManageMonitoring($user), 403);

        $this->userId = (int) auth()->id();
        $this->organizationId = $tenant->organizationId($user);
        $this->from = $this->from ?: today()->subDays(6)->toDateString();
        $this->to = $this->to ?: today()->toDateString();
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    #[On('echo-private:notifications.user.{userId},.NotificationCreated')]
    public function onNotificationCreated(): void
    {
        // Arrival re-renders with fresh data.
    }

    public function markRead(int $id): void
    {
        NotificationLog::forRecipient($this->userId)
            ->forOrganization($this->organizationId)
            ->whereKey($id)
            ->update(['read_at' => now()]);
    }

    public function markAllRead(): void
    {
        NotificationLog::forRecipient($this->userId)
            ->forOrganization($this->organizationId)
            ->inApp()
            ->unread()
            ->update(['read_at' => now()]);
    }

    private function baseQuery()
    {
        return NotificationLog::forRecipient($this->userId)
            ->forOrganization($this->organizationId)
            ->inApp()
            ->when($this->severity, fn ($q) => $q->ofSeverity($this->severity))
            ->when($this->status === 'unread', fn ($q) => $q->unread())
            ->when($this->status === 'read', fn ($q) => $q->whereNotNull('read_at'))
            ->when($this->search, fn ($q) => $q->matching($this->search))
            ->between(Carbon::parse($this->from)->startOfDay(), Carbon::parse($this->to)->endOfDay());
    }

    public function render(): View
    {
        $inbox = NotificationLog::forRecipient($this->userId)
            ->forOrganization($this->organizationId)
            ->inApp();

        return view('livewire.notifications.notification-dashboard', [
            'notifications' => $this->baseQuery()->latest()->paginate(20)->withQueryString(),
            'stats' => [
                'total' => (clone $inbox)->count(),
                'unread' => (clone $inbox)->unread()->count(),
                'critical' => (clone $inbox)->ofSeverity(NotificationSeverity::Critical->value)->count(),
            ],
            'severities' => NotificationSeverity::cases(),
        ]);
    }
}
