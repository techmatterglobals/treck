<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addOwnershipColumn('departments', [
            ['organization_id', 'name'],
        ]);

        $this->addOwnershipColumn('employees', [
            ['organization_id', 'employee_code'],
            ['organization_id', 'department_id'],
            ['organization_id', 'manager_user_id'],
            ['organization_id', 'status'],
        ]);

        $this->addOwnershipColumn('computers', [
            ['organization_id', 'device_uuid'],
            ['organization_id', 'employee_id'],
            ['organization_id', 'status'],
            ['organization_id', 'last_seen_at'],
        ]);
    }

    public function down(): void
    {
        // Forward-only by design: do not remove populated tenant ownership data
        // or risk cascading business records during rollback.
    }

    /**
     * @param  list<list<string>>  $compoundIndexes
     */
    private function addOwnershipColumn(string $tableName, array $compoundIndexes): void
    {
        Schema::table($tableName, function (Blueprint $table) use ($tableName, $compoundIndexes) {
            if (! Schema::hasColumn($tableName, 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->after('id');
            }

            $table->index('organization_id', "{$tableName}_organization_idx");

            foreach ($compoundIndexes as $columns) {
                $table->index($columns, "{$tableName}_".implode('_', $columns).'_idx');
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
