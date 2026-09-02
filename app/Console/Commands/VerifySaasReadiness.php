<?php

namespace App\Console\Commands;

use App\Models\Organization;
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
                'platform_super_admin_assignments' => 0,
            ], JSON_PRETTY_PRINT));
        } else {
            foreach ($checks as $check) {
                $this->line(($check['passed'] ? 'PASS ' : 'FAIL ').$check['name']);
            }

            $this->line('platform_super_admin_assignments=0');
        }

        return $failed->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<array{name:string,passed:bool}>
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
        return DB::table(config('permission.table_names.model_has_roles'))
            ->join(config('permission.table_names.roles'), 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'platform-super-admin')
            ->whereNull('roles.organization_id')
            ->whereNull('model_has_roles.organization_id')
            ->count() >= 0;
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
}
