<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $teamKey = $columnNames['team_foreign_key'] ?? 'organization_id';
        $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';
        $modelMorphKey = $columnNames['model_morph_key'] ?? 'model_id';

        if ($teamKey !== 'organization_id') {
            throw new RuntimeException('Spatie Permission team_foreign_key must be organization_id for Phase A2.');
        }

        if (! Schema::hasColumn($tableNames['roles'], $teamKey)) {
            Schema::table($tableNames['roles'], function (Blueprint $table) use ($teamKey) {
                $table->unsignedBigInteger($teamKey)->nullable()->after('id');
                $table->index($teamKey, 'roles_organization_id_index');
                $table->dropUnique('roles_name_guard_name_unique');
                $table->unique([$teamKey, 'name', 'guard_name'], 'roles_organization_name_guard_unique');
            });
        }

        if (! Schema::hasColumn($tableNames['model_has_permissions'], $teamKey)) {
            Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use ($teamKey) {
                $table->unsignedBigInteger($teamKey)->nullable()->after('permission_id');
                $table->index($teamKey, 'model_has_permissions_organization_id_index');
            });
        }

        if (! Schema::hasColumn($tableNames['model_has_roles'], $teamKey)) {
            Schema::table($tableNames['model_has_roles'], function (Blueprint $table) use ($teamKey) {
                $table->unsignedBigInteger($teamKey)->nullable()->after('role_id');
                $table->index($teamKey, 'model_has_roles_organization_id_index');
            });
        }

        $this->replacePrimary(
            $tableNames['model_has_permissions'],
            'model_has_permissions_permission_model_type_primary',
            'model_has_permissions_organization_permission_model_unique',
            $tableNames['permissions'],
            [$teamKey, $pivotPermission, $modelMorphKey, 'model_type'],
        );

        $this->replacePrimary(
            $tableNames['model_has_roles'],
            'model_has_roles_role_model_type_primary',
            'model_has_roles_organization_role_model_unique',
            $tableNames['roles'],
            [$teamKey, $pivotRole, $modelMorphKey, 'model_type'],
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Production installs have the old non-team primary keys. Fresh installs
     * with teams enabled already have the desired key shape.
     *
     * @param  array<int, string>  $columns
     */
    private function replacePrimary(string $tableName, string $primaryName, string $uniqueName, string $parentTable, array $columns): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteModelPivot($tableName, $columns[1], $columns[0], $columns[2], $uniqueName);

            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $primaryName) {
            $table->dropForeign([$columns[1]]);
            $table->dropPrimary($primaryName);
        });

        Schema::table($tableName, function (Blueprint $table) use ($columns, $uniqueName, $parentTable) {
            $table->unsignedBigInteger($columns[0])->nullable()->change();
            $table->unique($columns, $uniqueName);
            $table->foreign($columns[1])->references('id')->on($parentTable)->onDelete('cascade');
        });
    }

    private function rebuildSqliteModelPivot(string $tableName, string $pivotKey, string $teamKey, string $modelKey, string $uniqueName): void
    {
        $tempTable = $tableName.'_phase_a2';

        Schema::create($tempTable, function (Blueprint $table) use ($pivotKey, $teamKey, $modelKey) {
            $table->unsignedBigInteger($pivotKey);
            $table->unsignedBigInteger($teamKey)->nullable();
            $table->string('model_type');
            $table->unsignedBigInteger($modelKey);
        });

        DB::table($tempTable)->insertUsing(
            [$pivotKey, $teamKey, 'model_type', $modelKey],
            DB::table($tableName)->select($pivotKey, $teamKey, 'model_type', $modelKey),
        );

        Schema::drop($tableName);
        Schema::rename($tempTable, $tableName);

        Schema::table($tableName, function (Blueprint $table) use ($pivotKey, $teamKey, $modelKey, $uniqueName) {
            $table->index($teamKey, $table->getTable().'_organization_id_index');
            $table->index([$modelKey, 'model_type'], $table->getTable().'_model_id_model_type_index');
            $table->unique([$teamKey, $pivotKey, $modelKey, 'model_type'], $uniqueName);
        });
    }
};
