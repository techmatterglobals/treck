<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Release version
    |--------------------------------------------------------------------------
    | Kept in lock-step with the Windows agent (agent/Treck.Agent.csproj) so the
    | backend and agent report the same product version.
    */
    'version' => '1.0.0',

    /*
    |--------------------------------------------------------------------------
    | Activity tracking
    |--------------------------------------------------------------------------
    */
    'activity' => [
        'heartbeat_interval_seconds' => (int) env('TRECK_HEARTBEAT_INTERVAL', 60),
        'idle_threshold_seconds' => (int) env('TRECK_IDLE_THRESHOLD', 300),
        'offline_grace_seconds' => (int) env('TRECK_OFFLINE_GRACE', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | Real-time presence (M7)
    |--------------------------------------------------------------------------
    | A computer with no agent contact within this window is swept to Offline.
    | Should be comfortably larger than the heartbeat interval to avoid flapping.
    */
    'presence' => [
        'offline_timeout_seconds' => (int) env('TRECK_PRESENCE_OFFLINE_TIMEOUT', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | Attendance
    |--------------------------------------------------------------------------
    */
    'attendance' => [
        'workday_start' => env('TRECK_WORKDAY_START', '09:00'),
        'late_grace_minutes' => (int) env('TRECK_LATE_GRACE', 15),
        'full_day_hours' => (int) env('TRECK_FULL_DAY_HOURS', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Screenshots (opt-in) — Phase 8
    |--------------------------------------------------------------------------
    | Image bytes are stored via Laravel Storage on `disk` (keep it OUTSIDE the
    | public directory — the default `local` disk resolves to storage/app, which
    | is not web-accessible). Files are served only through a signed, authorized
    | route; the filesystem path is never exposed. Set the disk to `s3` (or any
    | configured driver) for object storage.
    */
    'screenshots' => [
        'enabled' => (bool) env('TRECK_SCREENSHOTS', false),
        'interval_seconds' => (int) env('TRECK_SCREENSHOT_INTERVAL', 600),
        'blur' => (bool) env('TRECK_SCREENSHOT_BLUR', true),

        // Storage disk for image bytes (must not be publicly listable).
        'disk' => env('TRECK_SCREENSHOT_DISK', 'local'),
        // Path prefix within the disk.
        'directory' => env('TRECK_SCREENSHOT_DIR', 'screenshots'),
        // Max accepted upload size in kilobytes (validation guard).
        'max_upload_kb' => (int) env('TRECK_SCREENSHOT_MAX_KB', 8192),
        // Signed view-URL lifetime, in minutes.
        'url_ttl_minutes' => (int) env('TRECK_SCREENSHOT_URL_TTL', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications (Phase 9)
    |--------------------------------------------------------------------------
    | Per-rule behaviour (enabled/severity/channels/thresholds/throttle) lives in
    | the `notification_rules` table so admins can retune without a deploy. These
    | are process-level defaults only.
    */
    'notifications' => [
        // Default throttle window (seconds) applied when a rule has none set.
        'default_throttle_seconds' => (int) env('TRECK_NOTIFY_THROTTLE', 300),
        // Digest email cadence for users in digest mode (cron-driven).
        'digest_hours' => (int) env('TRECK_NOTIFY_DIGEST_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | File download monitoring (Phase 12)
    |--------------------------------------------------------------------------
    | Server-side thresholds for the download-alert rules. The Windows agent
    | owns the detection policy (ignored folders/types/apps, hashing on/off, max
    | hash size) via its own appsettings; these values only tune the backend
    | alerting. Metadata only — file contents are never collected.
    */
    'downloads' => [
        // A download at/above this size (bytes) is considered "large" for alerts.
        'large_file_bytes' => (int) env('TRECK_DOWNLOAD_LARGE_BYTES', 104857600), // 100 MB
        // Extensions treated as executables / archives for the default alert rules.
        'executable_extensions' => ['exe', 'msi', 'bat', 'cmd', 'ps1', 'scr', 'com'],
        'archive_extensions' => ['zip', 'rar', '7z', 'tar', 'gz', 'iso'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Data retention (days)
    |--------------------------------------------------------------------------
    */
    'retention' => [
        'raw_heartbeat_days' => (int) env('TRECK_RAW_RETENTION', 90),
        'aggregate_days' => (int) env('TRECK_AGG_RETENTION', 730),
        // Screenshots are pruned (row + file) after this many days (0 = keep).
        'screenshot_days' => (int) env('TRECK_SCREENSHOT_RETENTION', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Desktop agent
    |--------------------------------------------------------------------------
    | Shared provisioning key used once per device to obtain a Sanctum token.
    | Leave null to disable device registration entirely.
    */
    'agent' => [
        'provisioning_key' => env('TRECK_AGENT_PROVISIONING_KEY'),
    ],

];
