<?php

namespace App\Enums;

/**
 * The catalogue of events that can raise a notification (Phase 9). Rules are
 * keyed by these values in the `notification_rules` table, so admins enable /
 * disable, re-severity, re-channel and threshold each type without code changes.
 *
 * Grouped by category: presence, application usage, screenshot, agent, system.
 */
enum NotificationEventType: string
{
    // Presence
    case PresenceOnline = 'presence.online';
    case PresenceOffline = 'presence.offline';
    case PresenceIdle = 'presence.idle';
    case PresenceLocked = 'presence.locked';
    case PresenceLoggedOut = 'presence.logged_out';
    case PresenceReconnected = 'presence.reconnected';

    // Application usage
    case AppRestricted = 'app.restricted';
    case AppLongUsage = 'app.long_usage';
    case AppBlacklisted = 'app.blacklisted';

    // Screenshot
    case ScreenshotFailed = 'screenshot.failed';
    case ScreenshotSyncFailed = 'screenshot.sync_failed';

    // Windows agent
    case AgentRegistrationFailed = 'agent.registration_failed';
    case AgentHeartbeatStopped = 'agent.heartbeat_stopped';
    case AgentSyncFailed = 'agent.sync_failed';
    case AgentQueueGrowing = 'agent.queue_growing';

    // System
    case SystemInactive = 'system.inactive';
    case SystemUnknownUser = 'system.unknown_user';

    // File downloads (Phase 12)
    case DownloadExecutable = 'download.executable';
    case DownloadArchive = 'download.archive';
    case DownloadLarge = 'download.large';
    case DownloadRestricted = 'download.restricted';

    public function category(): string
    {
        return explode('.', $this->value)[0];
    }

    public function label(): string
    {
        return match ($this) {
            self::PresenceOnline => 'Employee came online',
            self::PresenceOffline => 'Employee went offline',
            self::PresenceIdle => 'Employee idle beyond threshold',
            self::PresenceLocked => 'Workstation locked',
            self::PresenceLoggedOut => 'Employee logged out',
            self::PresenceReconnected => 'Device reconnected after extended offline',
            self::AppRestricted => 'Restricted application opened',
            self::AppLongUsage => 'Application used beyond duration',
            self::AppBlacklisted => 'Blacklisted process detected',
            self::ScreenshotFailed => 'Screenshot capture failed',
            self::ScreenshotSyncFailed => 'Screenshot synchronization failed',
            self::AgentRegistrationFailed => 'Device registration failed',
            self::AgentHeartbeatStopped => 'Agent stopped sending heartbeats',
            self::AgentSyncFailed => 'Synchronization failures',
            self::AgentQueueGrowing => 'Offline queue growing beyond threshold',
            self::SystemInactive => 'Computer inactive for configured duration',
            self::SystemUnknownUser => 'Unrecognized Windows user on a computer',
            self::DownloadExecutable => 'Executable file downloaded',
            self::DownloadArchive => 'Archive file downloaded',
            self::DownloadLarge => 'Large file downloaded',
            self::DownloadRestricted => 'Restricted file type downloaded',
        };
    }

    /** Default severity used when seeding a rule for this type. */
    public function defaultSeverity(): NotificationSeverity
    {
        return match ($this) {
            self::PresenceOnline, self::PresenceOffline, self::PresenceLocked,
            self::PresenceLoggedOut, self::PresenceReconnected => NotificationSeverity::Info,

            self::PresenceIdle, self::AppRestricted, self::AppLongUsage,
            self::ScreenshotSyncFailed, self::AgentSyncFailed,
            self::AgentQueueGrowing, self::SystemInactive,
            self::SystemUnknownUser, self::DownloadArchive,
            self::DownloadLarge => NotificationSeverity::Warning,

            self::AppBlacklisted, self::ScreenshotFailed, self::AgentRegistrationFailed,
            self::AgentHeartbeatStopped, self::DownloadExecutable,
            self::DownloadRestricted => NotificationSeverity::Critical,
        };
    }

    public static function tryFromValue(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
