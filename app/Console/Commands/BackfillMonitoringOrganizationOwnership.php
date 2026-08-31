<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Tenancy\MonitoringTenantOwnership;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillMonitoringOrganizationOwnership extends Command
{
    protected $signature = 'treck:backfill-monitoring-organization-ownership
        {--organization= : Target organization id or slug}
        {--dry-run : Report planned changes without writing them}
        {--verify : Read-only verification of remaining backfillable rows and ownership conflicts}';

    protected $description = 'Backfill nullable organization ownership for monitoring and reporting rows.';

    public function handle(MonitoringTenantOwnership $ownership): int
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
        $summary = $this->summarize($organization->id, $ownership);

        $this->line('organization_id='.$organization->id);

        foreach ($summary as $table => $counts) {
            $this->line($table.'_to_assign='.$counts['planned']);
            $this->line($table.'_conflicts='.$counts['conflicted']);
            $this->line($table.'_unresolved='.$counts['unresolved']);
            $this->line($table.'_other_organizations='.$counts['other']);
        }

        $this->line('platform_super_admin_assignments=0');

        $planned = array_sum(array_column($summary, 'planned'));
        $conflicted = array_sum(array_column($summary, 'conflicted'));

        if ($verify) {
            return $planned === 0 && $conflicted === 0
                ? self::SUCCESS
                : self::FAILURE;
        }

        if ($dryRun) {
            $this->line('Dry run only; no data was changed.');

            return self::SUCCESS;
        }

        $updated = array_fill_keys(array_keys($this->tables()), 0);

        DB::transaction(function () use ($organization, $ownership, &$updated) {
            foreach ($this->tables() as $table => $config) {
                DB::table($table)
                    ->whereNull('organization_id')
                    ->orderBy('id')
                    ->chunkById(500, function ($rows) use ($table, $config, $organization, $ownership, &$updated) {
                        foreach ($rows as $row) {
                            $resolution = $ownership->resolve(
                                computer: $config['computer'] ? $row->computer_id : null,
                                employee: $config['employee'] ? $row->employee_id : null,
                                allowEmployeeOnly: $config['allow_employee_only'],
                            );

                            if ((int) $resolution->organizationId !== (int) $organization->id) {
                                continue;
                            }

                            $updated[$table] += DB::table($table)
                                ->where('id', $row->id)
                                ->whereNull('organization_id')
                                ->update(['organization_id' => $organization->id]);
                        }
                    });
            }
        });

        foreach ($updated as $table => $count) {
            $this->info($table.' assigned: '.$count.'.');
        }

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
     * @return array<string,array{planned:int,conflicted:int,unresolved:int,other:int}>
     */
    private function summarize(int $organizationId, MonitoringTenantOwnership $ownership): array
    {
        $summary = [];

        foreach ($this->tables() as $table => $config) {
            $counts = [
                'planned' => 0,
                'conflicted' => 0,
                'unresolved' => 0,
                'other' => 0,
            ];

            DB::table($table)
                ->whereNull('organization_id')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($config, $ownership, $organizationId, &$counts) {
                    foreach ($rows as $row) {
                        $resolution = $ownership->resolve(
                            computer: $config['computer'] ? $row->computer_id : null,
                            employee: $config['employee'] ? $row->employee_id : null,
                            allowEmployeeOnly: $config['allow_employee_only'],
                        );

                        if ($resolution->conflicted) {
                            $counts['conflicted']++;
                        } elseif ($resolution->organizationId === null) {
                            $counts['unresolved']++;
                        } elseif ((int) $resolution->organizationId === $organizationId) {
                            $counts['planned']++;
                        } else {
                            $counts['other']++;
                        }
                    }
                });

            $summary[$table] = $counts;
        }

        return $summary;
    }

    /**
     * @return array<string,array{computer:bool,employee:bool,allow_employee_only:bool}>
     */
    private function tables(): array
    {
        return [
            'agent_events' => ['computer' => true, 'employee' => true, 'allow_employee_only' => false],
            'agent_health_reports' => ['computer' => true, 'employee' => false, 'allow_employee_only' => false],
            'computer_presence' => ['computer' => true, 'employee' => false, 'allow_employee_only' => false],
            'activity_logs' => ['computer' => true, 'employee' => true, 'allow_employee_only' => true],
            'application_usage' => ['computer' => true, 'employee' => true, 'allow_employee_only' => false],
            'screenshots' => ['computer' => true, 'employee' => true, 'allow_employee_only' => false],
            'file_downloads' => ['computer' => true, 'employee' => true, 'allow_employee_only' => false],
            'attendance' => ['computer' => false, 'employee' => true, 'allow_employee_only' => true],
            'productivity_reports' => ['computer' => false, 'employee' => true, 'allow_employee_only' => true],
            'notification_logs' => ['computer' => true, 'employee' => true, 'allow_employee_only' => true],
        ];
    }
}
