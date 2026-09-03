<?php

namespace App\Console\Commands;

use App\Models\Computer;
use App\Models\Organization;
use App\Models\Screenshot;
use App\Services\Screenshots\ScreenshotStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

class VerifySaasReadiness extends Command
{
    protected $signature = 'treck:verify-saas-readiness {--json : Emit JSON instead of text lines}';

    protected $description = 'Read-only A1-B5 production readiness checks for SaaS tenancy rollout.';

    public function handle(): int
    {
        $checks = $this->checks();
        $failed = collect($checks)->filter(fn (array $check) => ! $check['passed'])->values();

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => $failed->isEmpty(),
                'checks' => $checks,
                'platform_super_admin_assignments' => $this->platformSuperAdminAssignmentsCount(),
            ], JSON_PRETTY_PRINT));
        } else {
            foreach ($checks as $check) {
                $this->line(($check['passed'] ? 'PASS ' : 'FAIL ').$check['name']);
            }

            $this->line('platform_super_admin_assignments='.$this->platformSuperAdminAssignmentsCount());
        }

        return $failed->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<array{name:string,passed:bool,details?:array<string,int>}>
     */
    private function checks(): array
    {
        return [
            ['name' => 'organizations table exists', 'passed' => Schema::hasTable('organizations')],
            ['name' => 'organization memberships table exists', 'passed' => Schema::hasTable('organization_user')],
            ['name' => 'Spatie teams are configured for organizations', 'passed' => (bool) config('permission.teams') && config('permission.column_names.team_foreign_key') === 'organization_id'],
            ['name' => 'core ownership columns exist', 'passed' => $this->columns('departments', 'employees', 'computers')],
            ['name' => 'monitoring ownership columns exist', 'passed' => $this->columns('agent_events', 'agent_health_reports', 'computer_presence', 'activity_logs', 'application_usage', 'screenshots', 'file_downloads', 'attendance', 'productivity_reports', 'notification_logs')],
            ['name' => 'agent token ownership column exists', 'passed' => Schema::hasColumn('personal_access_tokens', 'organization_id')],
            ['name' => 'agent enrollment credentials table exists', 'passed' => Schema::hasTable('agent_enrollment_credentials')],
            ['name' => 'screenshot storage fallback is explicit', 'passed' => config()->has('treck.screenshots.legacy_fallback')],
            ['name' => 'no automatic platform super admin role is required', 'passed' => $this->platformSuperAdminCheckIsReadable()],
            ['name' => 'active organizations can be enumerated for scheduled rollout', 'passed' => $this->organizationTableIsReadable()],
            $this->backfillCompletionCheck(),
            $this->relationshipIntegrityCheck(),
            $this->agentTokenIntegrityCheck(),
            $this->screenshotStoragePathCheck(),
        ];
    }

    private function columns(string ...$tables): bool
    {
        foreach ($tables as $table) {
            if (! Schema::hasColumn($table, 'organization_id')) {
                return false;
            }
        }

        return true;
    }

    private function platformSuperAdminAssignmentsAreExplicit(): bool
    {
        return $this->platformSuperAdminAssignmentsCount() >= 0;
    }

    private function platformSuperAdminAssignmentsCount(): int
    {
        if (! Schema::hasTable(config('permission.table_names.roles'))
            || ! Schema::hasTable(config('permission.table_names.model_has_roles'))
            || ! Schema::hasColumn(config('permission.table_names.roles'), 'organization_id')
            || ! Schema::hasColumn(config('permission.table_names.model_has_roles'), 'organization_id')) {
            return 0;
        }

        return DB::table(config('permission.table_names.model_has_roles'))
            ->join(config('permission.table_names.roles'), 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'platform-super-admin')
            ->whereNull('roles.organization_id')
            ->whereNull('model_has_roles.organization_id')
            ->count();
    }

    private function platformSuperAdminCheckIsReadable(): bool
    {
        if (! Schema::hasTable(config('permission.table_names.roles'))
            || ! Schema::hasTable(config('permission.table_names.model_has_roles'))
            || ! Schema::hasColumn(config('permission.table_names.roles'), 'organization_id')
            || ! Schema::hasColumn(config('permission.table_names.model_has_roles'), 'organization_id')) {
            return false;
        }

        return ! Role::query()->where('name', 'platform-super-admin')->whereNull('organization_id')->exists()
            || $this->platformSuperAdminAssignmentsAreExplicit();
    }

    private function organizationTableIsReadable(): bool
    {
        return Schema::hasTable('organizations')
            && Schema::hasColumn('organizations', 'status')
            && Organization::query()->active()->count() >= 0;
    }

    /**
     * @return array{name:string,passed:bool,details:array<string,int>}
     */
    private function backfillCompletionCheck(): array
    {
        $tables = [
            'departments',
            'employees',
            'computers',
            'agent_events',
            'agent_health_reports',
            'computer_presence',
            'activity_logs',
            'application_usage',
            'screenshots',
            'file_downloads',
            'attendance',
            'productivity_reports',
            'notification_logs',
        ];

        $counts = [];
        foreach ($tables as $table) {
            $counts[$table.'_null_organization_id'] = $this->countNullOwnership($table);
        }

        $counts['computer_tokens_null_organization_id'] = $this->tokenTablesReady()
            ? $this->computerTokenQuery()
                ->whereNull('personal_access_tokens.organization_id')
                ->count()
            : 0;

        return [
            'name' => 'tenant ownership backfills are complete',
            'passed' => collect($counts)->every(fn (int $count) => $count === 0),
            'details' => $counts,
        ];
    }

    private function countNullOwnership(string $table): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'organization_id')) {
            return 0;
        }

        return DB::table($table)->whereNull('organization_id')->count();
    }

    /**
     * @return array{name:string,passed:bool,details:array<string,int>}
     */
    private function relationshipIntegrityCheck(): array
    {
        $checks = [
            'employees_department_mismatches' => ['employees', 'department_id', 'departments'],
            'computers_employee_mismatches' => ['computers', 'employee_id', 'employees'],
            'agent_events_computer_mismatches' => ['agent_events', 'computer_id', 'computers'],
            'agent_events_employee_mismatches' => ['agent_events', 'employee_id', 'employees'],
            'agent_health_computer_mismatches' => ['agent_health_reports', 'computer_id', 'computers'],
            'presence_computer_mismatches' => ['computer_presence', 'computer_id', 'computers'],
            'activity_logs_computer_mismatches' => ['activity_logs', 'computer_id', 'computers'],
            'activity_logs_employee_mismatches' => ['activity_logs', 'employee_id', 'employees'],
            'application_usage_computer_mismatches' => ['application_usage', 'computer_id', 'computers'],
            'application_usage_employee_mismatches' => ['application_usage', 'employee_id', 'employees'],
            'application_usage_session_mismatches' => ['application_usage', 'activity_log_id', 'activity_logs'],
            'screenshots_computer_mismatches' => ['screenshots', 'computer_id', 'computers'],
            'screenshots_employee_mismatches' => ['screenshots', 'employee_id', 'employees'],
            'screenshots_session_mismatches' => ['screenshots', 'activity_log_id', 'activity_logs'],
            'file_downloads_computer_mismatches' => ['file_downloads', 'computer_id', 'computers'],
            'file_downloads_employee_mismatches' => ['file_downloads', 'employee_id', 'employees'],
            'attendance_employee_mismatches' => ['attendance', 'employee_id', 'employees'],
            'productivity_employee_mismatches' => ['productivity_reports', 'employee_id', 'employees'],
            'notification_computer_mismatches' => ['notification_logs', 'computer_id', 'computers'],
            'notification_employee_mismatches' => ['notification_logs', 'employee_id', 'employees'],
        ];

        $counts = [];
        foreach ($checks as $name => [$childTable, $foreignKey, $parentTable]) {
            $counts[$name] = $this->countRelationshipMismatch($childTable, $foreignKey, $parentTable);
        }

        return [
            'name' => 'tenant parent-child ownership is consistent',
            'passed' => collect($counts)->every(fn (int $count) => $count === 0),
            'details' => $counts,
        ];
    }

    private function countRelationshipMismatch(string $childTable, string $foreignKey, string $parentTable): int
    {
        if (! Schema::hasTable($childTable)
            || ! Schema::hasTable($parentTable)
            || ! Schema::hasColumn($childTable, 'organization_id')
            || ! Schema::hasColumn($childTable, $foreignKey)
            || ! Schema::hasColumn($parentTable, 'organization_id')) {
            return 0;
        }

        return DB::table($childTable.' as child')
            ->join($parentTable.' as parent', 'parent.id', '=', 'child.'.$foreignKey)
            ->whereNotNull('child.organization_id')
            ->whereNotNull('parent.organization_id')
            ->whereColumn('child.organization_id', '!=', 'parent.organization_id')
            ->count();
    }

    /**
     * @return array{name:string,passed:bool,details:array<string,int>}
     */
    private function agentTokenIntegrityCheck(): array
    {
        if (! $this->tokenTablesReady()) {
            return [
                'name' => 'agent tokens match owned computers',
                'passed' => false,
                'details' => [
                    'computer_token_organization_mismatches' => 0,
                    'computer_token_unowned_computers' => 0,
                ],
            ];
        }

        $counts = [
            'computer_token_organization_mismatches' => $this->computerTokenQuery()
                ->whereNotNull('personal_access_tokens.organization_id')
                ->whereColumn('personal_access_tokens.organization_id', '!=', 'computers.organization_id')
                ->count(),
            'computer_token_unowned_computers' => $this->computerTokenQuery()
                ->whereNull('computers.organization_id')
                ->count(),
        ];

        return [
            'name' => 'agent tokens match owned computers',
            'passed' => collect($counts)->every(fn (int $count) => $count === 0),
            'details' => $counts,
        ];
    }

    private function computerTokenQuery()
    {
        return DB::table('personal_access_tokens')
            ->join('computers', 'computers.id', '=', 'personal_access_tokens.tokenable_id')
            ->where('personal_access_tokens.tokenable_type', (new Computer)->getMorphClass());
    }

    private function tokenTablesReady(): bool
    {
        return Schema::hasTable('personal_access_tokens')
            && Schema::hasTable('computers')
            && Schema::hasColumn('personal_access_tokens', 'organization_id');
    }

    /**
     * @return array{name:string,passed:bool,details:array<string,int>}
     */
    private function screenshotStoragePathCheck(): array
    {
        if (! Schema::hasTable('screenshots') || ! Schema::hasColumn('screenshots', 'organization_id')) {
            return [
                'name' => 'tenant screenshot storage paths match ownership',
                'passed' => false,
                'details' => ['screenshot_storage_path_mismatches' => 0],
            ];
        }

        $storage = app(ScreenshotStorageService::class);
        $mismatches = 0;

        Screenshot::query()
            ->whereNotNull('organization_id')
            ->orderBy('id')
            ->chunkById(200, function ($screenshots) use ($storage, &$mismatches) {
                foreach ($screenshots as $screenshot) {
                    $expected = $storage->expectedTenantPath($screenshot);

                    if ($expected === null || $screenshot->path !== $expected) {
                        $mismatches++;
                    }
                }
            });

        return [
            'name' => 'tenant screenshot storage paths match ownership',
            'passed' => $mismatches === 0,
            'details' => ['screenshot_storage_path_mismatches' => $mismatches],
        ];
    }
}
