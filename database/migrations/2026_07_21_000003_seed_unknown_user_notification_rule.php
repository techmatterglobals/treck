<?php

use App\Enums\NotificationEventType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 11 — seed the notification rule for an unrecognized Windows user on a
 * shared computer (system.unknown_user). Idempotent updateOrInsert so both
 * fresh installs and existing Phase 9 deployments gain the rule without
 * disturbing any operator customizations to the other rules. Delivered in-app
 * and by email so the Super Admin is alerted to map the account.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('notification_rules')->updateOrInsert(
            ['event_type' => NotificationEventType::SystemUnknownUser->value],
            [
                'enabled' => true,
                'severity' => NotificationEventType::SystemUnknownUser->defaultSeverity()->value,
                'channels' => json_encode(['in_app', 'email']),
                'config' => json_encode([]),
                'throttle_seconds' => 3600, // one alert per unknown account per hour
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table('notification_rules')
            ->where('event_type', NotificationEventType::SystemUnknownUser->value)
            ->delete();
    }
};
