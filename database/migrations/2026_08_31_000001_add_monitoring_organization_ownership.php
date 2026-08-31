<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string,list<array{name:string,columns:list<string>}>>
     */
    private array $indexes = [
        'agent_events' => [
            ['name' => 'agent_events_org_computer_occurred_idx', 'columns' => ['organization_id', 'computer_id', 'occurred_at']],
            ['name' => 'agent_events_org_employee_occurred_idx', 'columns' => ['organization_id', 'employee_id', 'occurred_at']],
            ['name' => 'agent_events_org_kind_occurred_idx', 'columns' => ['organization_id', 'kind', 'occurred_at']],
        ],
        'agent_health_reports' => [
            ['name' => 'agent_health_org_received_idx', 'columns' => ['organization_id', 'received_at']],
            ['name' => 'agent_health_org_version_idx', 'columns' => ['organization_id', 'agent_version', 'config_revision']],
        ],
        'computer_presence' => [
            ['name' => 'presence_org_status_synced_idx', 'columns' => ['organization_id', 'status', 'last_synced_at']],
            ['name' => 'presence_org_computer_idx', 'columns' => ['organization_id', 'computer_id']],
        ],
        'activity_logs' => [
            ['name' => 'activity_logs_org_work_date_idx', 'columns' => ['organization_id', 'work_date']],
            ['name' => 'activity_logs_org_employee_work_idx', 'columns' => ['organization_id', 'employee_id', 'work_date']],
            ['name' => 'activity_logs_org_computer_login_idx', 'columns' => ['organization_id', 'computer_id', 'login_at']],
        ],
        'application_usage' => [
            ['name' => 'app_usage_org_employee_used_idx', 'columns' => ['organization_id', 'employee_id', 'used_at']],
            ['name' => 'app_usage_org_computer_used_idx', 'columns' => ['organization_id', 'computer_id', 'used_at']],
            ['name' => 'app_usage_org_app_used_idx', 'columns' => ['organization_id', 'application_name', 'used_at']],
        ],
        'screenshots' => [
            ['name' => 'screenshots_org_employee_captured_idx', 'columns' => ['organization_id', 'employee_id', 'captured_at']],
            ['name' => 'screenshots_org_computer_captured_idx', 'columns' => ['organization_id', 'computer_id', 'captured_at']],
            ['name' => 'screenshots_org_captured_idx', 'columns' => ['organization_id', 'captured_at']],
        ],
        'file_downloads' => [
            ['name' => 'downloads_org_employee_downloaded_idx', 'columns' => ['organization_id', 'employee_id', 'downloaded_at']],
            ['name' => 'downloads_org_computer_downloaded_idx', 'columns' => ['organization_id', 'computer_id', 'downloaded_at']],
            ['name' => 'downloads_org_extension_downloaded_idx', 'columns' => ['organization_id', 'file_extension', 'downloaded_at']],
        ],
        'attendance' => [
            ['name' => 'attendance_org_work_date_idx', 'columns' => ['organization_id', 'work_date']],
            ['name' => 'attendance_org_employee_work_idx', 'columns' => ['organization_id', 'employee_id', 'work_date']],
        ],
        'productivity_reports' => [
            ['name' => 'productivity_org_period_idx', 'columns' => ['organization_id', 'period_type', 'period_date']],
            ['name' => 'productivity_org_employee_period_idx', 'columns' => ['organization_id', 'employee_id', 'period_type', 'period_date']],
        ],
        'notification_logs' => [
            ['name' => 'notifications_org_recipient_read_idx', 'columns' => ['organization_id', 'recipient_id', 'read_at']],
            ['name' => 'notifications_org_severity_created_idx', 'columns' => ['organization_id', 'severity', 'created_at']],
            ['name' => 'notifications_org_event_created_idx', 'columns' => ['organization_id', 'event_type', 'created_at']],
            ['name' => 'notifications_org_dedupe_created_idx', 'columns' => ['organization_id', 'dedupe_key', 'created_at']],
        ],
    ];

    public function up(): void
    {
        foreach (array_keys($this->indexes) as $tableName) {
            $this->addOwnershipColumn($tableName);
        }
    }

    public function down(): void
    {
        // Forward-only by design: monitoring ownership is a tenant-isolation
        // safety record and must not be dropped during behavioral rollback.
    }

    private function addOwnershipColumn(string $tableName): void
    {
        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (! Schema::hasColumn($tableName, 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->after('id');
            }

            $table->index('organization_id', "{$tableName}_organization_idx");

            foreach ($this->indexes[$tableName] as $index) {
                $table->index($index['columns'], $index['name']);
            }
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            $table->foreign('organization_id', "{$tableName}_organization_fk")
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();
        });
    }
};
