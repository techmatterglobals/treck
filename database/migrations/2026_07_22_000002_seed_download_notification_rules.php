<?php

use App\Enums\NotificationEventType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 12 — seed the file-download notification rules (idempotent). Added for
 * both fresh installs and existing deployments without disturbing operator
 * customizations to other rules. Executable/restricted default to in-app+email
 * (critical); archive/large to in-app (warning). All are throttled and the
 * restricted list starts empty for the admin to fill in.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (self::rules() as $type => [$channels, $config]) {
            $eventType = NotificationEventType::from($type);

            DB::table('notification_rules')->updateOrInsert(
                ['event_type' => $type],
                [
                    'enabled' => true,
                    'severity' => $eventType->defaultSeverity()->value,
                    'channels' => json_encode($channels),
                    'config' => json_encode($config),
                    'throttle_seconds' => 300,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }

    /** @return array<string,array{0:list<string>,1:array<string,mixed>}> */
    private static function rules(): array
    {
        $critical = ['in_app', 'email'];
        $warning = ['in_app'];

        return [
            NotificationEventType::DownloadExecutable->value => [$critical, []],
            NotificationEventType::DownloadRestricted->value => [$critical, ['extensions' => []]],
            NotificationEventType::DownloadArchive->value => [$warning, []],
            NotificationEventType::DownloadLarge->value => [$warning, []],
        ];
    }

    public function down(): void
    {
        DB::table('notification_rules')->whereIn('event_type', [
            NotificationEventType::DownloadExecutable->value,
            NotificationEventType::DownloadRestricted->value,
            NotificationEventType::DownloadArchive->value,
            NotificationEventType::DownloadLarge->value,
        ])->delete();
    }
};
