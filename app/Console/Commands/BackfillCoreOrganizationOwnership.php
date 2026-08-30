<?php

namespace App\Console\Commands;

use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillCoreOrganizationOwnership extends Command
{
    protected $signature = 'treck:backfill-core-organization-ownership
        {--organization= : Target organization id or slug}
        {--dry-run : Report planned changes without writing them}
        {--verify : Read-only verification of null ownership and relationship conflicts}';

    protected $description = 'Backfill nullable organization ownership for departments, employees and computers.';

    public function handle(): int
    {
        $organization = $this->resolveOrganization();

        if (! $organization) {
            $this->error('Target organization was not found.');

            return self::FAILURE;
        }

        if ($organization->isSuspended()) {
            $this->error('Target organization is suspended.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $verify = (bool) $this->option('verify');
        $planned = $this->plannedCounts($organization);
        $conflicts = $this->conflictCounts($organization);

        $this->line('organization_id='.$organization->id);
        $this->line('departments_to_assign='.$planned['departments']);
        $this->line('employees_to_assign='.$planned['employees']);
        $this->line('computers_to_assign='.$planned['computers']);

        foreach ($conflicts as $name => $count) {
            $this->line($name.'='.$count);
        }

        $this->line('platform_super_admin_assignments=0');

        if ($verify) {
            return array_sum($planned) === 0 && array_sum($conflicts) === 0
                ? self::SUCCESS
                : self::FAILURE;
        }

        if ($dryRun) {
            $this->line('Dry run only; no data was changed.');

            return self::SUCCESS;
        }

        $updated = [
            'departments' => 0,
            'employees' => 0,
            'computers' => 0,
        ];

        DB::transaction(function () use ($organization, &$updated) {
            $updated['departments'] = DB::table('departments')
                ->whereNull('organization_id')
                ->update(['organization_id' => $organization->id]);

            $updated['employees'] = DB::table('employees')
                ->whereNull('organization_id')
                ->where(function ($query) use ($organization) {
                    $query->whereNull('department_id')
                        ->orWhereIn('department_id', DB::table('departments')
                            ->where('organization_id', $organization->id)
                            ->select('id'));
                })
                ->update(['organization_id' => $organization->id]);

            $updated['computers'] = DB::table('computers')
                ->whereNull('organization_id')
                ->where(function ($query) use ($organization) {
                    $query->whereNull('employee_id')
                        ->orWhereIn('employee_id', DB::table('employees')
                            ->where('organization_id', $organization->id)
                            ->select('id'));
                })
                ->update(['organization_id' => $organization->id]);
        });

        $this->info("Departments assigned: {$updated['departments']}.");
        $this->info("Employees assigned: {$updated['employees']}.");
        $this->info("Computers assigned: {$updated['computers']}.");
        $this->info('No platform-super-admin role was assigned.');

        return self::SUCCESS;
    }

    private function resolveOrganization(): ?Organization
    {
        $identifier = trim((string) $this->option('organization'));

        if ($identifier === '') {
            return null;
        }

        if (filter_var($identifier, FILTER_VALIDATE_INT) !== false) {
            return Organization::find((int) $identifier);
        }

        return Organization::query()
            ->where('slug', Str::slug($identifier))
            ->first();
    }

    /**
     * @return array{departments:int,employees:int,computers:int}
     */
    private function plannedCounts(Organization $organization): array
    {
        return [
            'departments' => DB::table('departments')->whereNull('organization_id')->count(),
            'employees' => DB::table('employees')
                ->whereNull('organization_id')
                ->where(function ($query) use ($organization) {
                    $query->whereNull('department_id')
                        ->orWhereIn('department_id', DB::table('departments')
                            ->where('organization_id', $organization->id)
                            ->select('id'));
                })
                ->count(),
            'computers' => DB::table('computers')
                ->whereNull('organization_id')
                ->where(function ($query) use ($organization) {
                    $query->whereNull('employee_id')
                        ->orWhereIn('employee_id', DB::table('employees')
                            ->where('organization_id', $organization->id)
                            ->select('id'));
                })
                ->count(),
        ];
    }

    /**
     * @return array<string,int>
     */
    private function conflictCounts(Organization $organization): array
    {
        return [
            'employee_department_conflicts' => DB::table('employees')
                ->join('departments', 'departments.id', '=', 'employees.department_id')
                ->whereNotNull('employees.organization_id')
                ->whereNotNull('departments.organization_id')
                ->whereColumn('employees.organization_id', '!=', 'departments.organization_id')
                ->count(),
            'computer_employee_conflicts' => DB::table('computers')
                ->join('employees', 'employees.id', '=', 'computers.employee_id')
                ->whereNotNull('computers.organization_id')
                ->whereNotNull('employees.organization_id')
                ->whereColumn('computers.organization_id', '!=', 'employees.organization_id')
                ->count(),
            'employees_blocked_by_other_department' => DB::table('employees')
                ->join('departments', 'departments.id', '=', 'employees.department_id')
                ->whereNull('employees.organization_id')
                ->whereNotNull('departments.organization_id')
                ->where('departments.organization_id', '!=', $organization->id)
                ->count(),
            'computers_blocked_by_other_employee' => DB::table('computers')
                ->join('employees', 'employees.id', '=', 'computers.employee_id')
                ->whereNull('computers.organization_id')
                ->whereNotNull('employees.organization_id')
                ->where('employees.organization_id', '!=', $organization->id)
                ->count(),
        ];
    }
}
