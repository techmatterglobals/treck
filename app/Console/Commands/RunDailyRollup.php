<?php

namespace App\Console\Commands;

use App\Jobs\GenerateDailyProductivity;
use App\Jobs\RollUpDailyAttendance;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rolls up daily attendance and productivity from activity_logs.
 *
 * Schedule in routes/console.php:
 *   Schedule::command('treck:daily-rollup')->hourly();          // keep today fresh
 *   Schedule::command('treck:daily-rollup '.now()->subDay()->toDateString())->dailyAt('00:30'); // finalize yesterday
 */
class RunDailyRollup extends Command
{
    protected $signature = 'treck:daily-rollup
        {date? : Date (Y-m-d); defaults to today}
        {--organization= : Organization id or slug to roll up; defaults to all active organizations}';

    protected $description = 'Roll up daily attendance and productivity from activity logs';

    public function handle(): int
    {
        $date = $this->argument('date')
            ? Carbon::parse($this->argument('date'))
            : today();
        $dateString = $date->toDateString();
        $organizations = $this->organizations();

        if ($organizations === null) {
            return self::FAILURE;
        }

        foreach ($organizations as $organization) {
            RollUpDailyAttendance::dispatchSync($dateString, $organization->id);
            GenerateDailyProductivity::dispatchSync($dateString, $organization->id);
        }

        $this->info("Daily rollup complete for {$dateString} across {$organizations->count()} organization(s).");

        return self::SUCCESS;
    }

    private function organizations(): ?Collection
    {
        $selected = $this->option('organization');

        if ($selected === null || $selected === '') {
            return Organization::query()->active()->orderBy('id')->get();
        }

        $query = Organization::query()->active();
        $organization = filter_var($selected, FILTER_VALIDATE_INT) !== false
            ? $query->whereKey((int) $selected)->first()
            : $query->where('slug', (string) $selected)->first();

        if ($organization === null) {
            $this->error('Active organization not found.');

            return null;
        }

        return collect([$organization]);
    }
}
