<?php

namespace App\Livewire\Notifications;

use App\Enums\NotificationEventType;
use App\Enums\NotificationSeverity;
use App\Models\NotificationPreference;
use App\Models\NotificationRule;
use App\Services\Tenancy\MonitoringTenantAccess;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Admin notification settings (Phase 9): global rules (enable/severity/channels/
 * throttle), the key thresholds/lists (idle, restricted apps, blacklisted
 * processes, long-usage), and the current admin's own preferences (channels,
 * min severity, digest, quiet hours). All persisted to the DB.
 */
class NotificationSettings extends Component
{
    /** @var array<int,array<string,mixed>> */
    public array $rules = [];

    // Key thresholds surfaced as dedicated inputs (§9).
    public int $idleThreshold = 900;

    public string $restrictedApps = '';

    public string $blacklistedProcesses = '';

    public int $longUsageMax = 3600;

    // Current admin's preference.
    public array $prefChannels = ['in_app', 'email'];

    public string $prefMinSeverity = 'info';

    public bool $prefDigest = false;

    public ?string $prefQuietStart = null;

    public ?string $prefQuietEnd = null;

    public function mount(): void
    {
        $user = auth()->user();
        abort_unless($user && app(MonitoringTenantAccess::class)->canManageMonitoring($user), 403);

        foreach (NotificationRule::orderBy('event_type')->get() as $rule) {
            $this->rules[] = [
                'id' => $rule->id,
                'event_type' => $rule->event_type,
                'label' => $rule->type()?->label() ?? $rule->event_type,
                'category' => $rule->type()?->category() ?? 'other',
                'enabled' => (bool) $rule->enabled,
                'severity' => $rule->severity,
                'in_app' => in_array('in_app', (array) $rule->channels, true),
                'email' => in_array('email', (array) $rule->channels, true),
                'throttle_seconds' => (int) $rule->throttle_seconds,
            ];
        }

        $this->idleThreshold = (int) (self::configFor(NotificationEventType::PresenceIdle)['idle_threshold_seconds'] ?? 900);
        $this->restrictedApps = implode(', ', (array) (self::configFor(NotificationEventType::AppRestricted)['applications'] ?? []));
        $this->blacklistedProcesses = implode(', ', (array) (self::configFor(NotificationEventType::AppBlacklisted)['processes'] ?? []));
        $this->longUsageMax = (int) (self::configFor(NotificationEventType::AppLongUsage)['max_usage_seconds'] ?? 3600);

        $pref = NotificationPreference::firstWhere('user_id', auth()->id());
        if ($pref) {
            $this->prefChannels = $pref->channels ?? ['in_app', 'email'];
            $this->prefMinSeverity = (string) $pref->min_severity;
            $this->prefDigest = (bool) $pref->digest;
            $this->prefQuietStart = $pref->quiet_hours_start ? substr((string) $pref->quiet_hours_start, 0, 5) : null;
            $this->prefQuietEnd = $pref->quiet_hours_end ? substr((string) $pref->quiet_hours_end, 0, 5) : null;
        }
    }

    /** @return array<string,mixed> */
    private static function configFor(NotificationEventType $type): array
    {
        // Load the model (not ->value) so the JSON cast applies.
        return (array) (NotificationRule::firstWhere('event_type', $type->value)?->config ?? []);
    }

    public function save(): void
    {
        $user = auth()->user();
        abort_unless($user && app(MonitoringTenantAccess::class)->canManageMonitoring($user), 403);

        foreach ($this->rules as $row) {
            $channels = array_values(array_filter([
                $row['in_app'] ? 'in_app' : null,
                $row['email'] ? 'email' : null,
            ]));

            NotificationRule::whereKey($row['id'])->update([
                'enabled' => (bool) $row['enabled'],
                'severity' => in_array($row['severity'], NotificationSeverity::values(), true) ? $row['severity'] : 'info',
                'channels' => $channels,
                'throttle_seconds' => max(0, (int) $row['throttle_seconds']),
            ]);
        }

        $this->mergeConfig(NotificationEventType::PresenceIdle, ['idle_threshold_seconds' => max(0, $this->idleThreshold)]);
        $this->mergeConfig(NotificationEventType::AppRestricted, ['applications' => $this->csv($this->restrictedApps)]);
        $this->mergeConfig(NotificationEventType::AppBlacklisted, ['processes' => $this->csv($this->blacklistedProcesses)]);
        $this->mergeConfig(NotificationEventType::AppLongUsage, ['max_usage_seconds' => max(0, $this->longUsageMax)]);

        NotificationPreference::updateOrCreate(
            ['user_id' => auth()->id()],
            [
                'channels' => array_values(array_intersect(['in_app', 'email'], $this->prefChannels)),
                'min_severity' => in_array($this->prefMinSeverity, NotificationSeverity::values(), true) ? $this->prefMinSeverity : 'info',
                'digest' => $this->prefDigest,
                'quiet_hours_start' => $this->prefQuietStart ?: null,
                'quiet_hours_end' => $this->prefQuietEnd ?: null,
            ],
        );

        session()->flash('status', 'Notification settings saved.');
    }

    /** @param array<string,mixed> $patch */
    private function mergeConfig(NotificationEventType $type, array $patch): void
    {
        $rule = NotificationRule::where('event_type', $type->value)->first();
        if ($rule) {
            $rule->update(['config' => array_merge((array) $rule->config, $patch)]);
        }
    }

    /** @return list<string> */
    private function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));
    }

    public function render(): View
    {
        return view('livewire.notifications.notification-settings', [
            'severities' => NotificationSeverity::cases(),
        ]);
    }
}
