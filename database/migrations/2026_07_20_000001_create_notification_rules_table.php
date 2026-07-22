<?php

use App\Enums\NotificationEventType;
use App\Enums\NotificationSeverity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 — Notifications. Configurable, DB-backed rules keyed by event type, so
 * administrators enable/disable, re-severity, re-channel and threshold each
 * notification without code changes. Seeded with a sensible default per event
 * type; `config` holds per-rule thresholds (idle seconds, restricted apps, …).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_rules', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 64)->unique();
            $table->boolean('enabled')->default(true);
            $table->string('severity', 16);
            $table->json('channels');            // e.g. ["in_app","email"]
            $table->json('config')->nullable();  // thresholds / lists per rule
            $table->unsignedInteger('throttle_seconds')->default(300);
            $table->timestamps();
        });

        $now = now();
        $rows = [];
        foreach (NotificationEventType::cases() as $type) {
            $rows[] = [
                'event_type' => $type->value,
                'enabled' => in_array($type->category(), ['presence', 'app'], true)
                    ? $type !== NotificationEventType::PresenceOnline    // reduce noise: online off by default
                    : true,
                'severity' => $type->defaultSeverity()->value,
                'channels' => json_encode($type->defaultSeverity() === NotificationSeverity::Critical
                    ? ['in_app', 'email']
                    : ['in_app']),
                'config' => json_encode(self::defaultConfig($type)),
                'throttle_seconds' => 300,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('notification_rules')->insert($rows);
    }

    /** @return array<string,mixed> */
    private static function defaultConfig(NotificationEventType $type): array
    {
        return match ($type) {
            NotificationEventType::PresenceIdle => ['idle_threshold_seconds' => 900],
            NotificationEventType::PresenceReconnected => ['offline_threshold_seconds' => 3600],
            NotificationEventType::AppRestricted => ['applications' => []],
            NotificationEventType::AppBlacklisted => ['processes' => []],
            NotificationEventType::AppLongUsage => ['max_usage_seconds' => 3600, 'applications' => []],
            default => [],
        };
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_rules');
    }
};
