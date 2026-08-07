<?php

namespace App\Console\Commands;

use App\Jobs\GenerateDailyProductivity;
use App\Jobs\RollUpDailyAttendance;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Rolls up daily attendance and productivity from activity_logs.
 *
 * Schedule in routes/console.php:
 *   Schedule::command('treck:daily-rollup')->hourly();          // keep today fresh
 *   Schedule::command('treck:daily-rollup '.now()->subDay()->toDateString())->dailyAt('00:30'); // finalize yesterday
 */
class RunDailyRollup extends Command
{
    protected $signature = 'treck:daily-rollup {date? : Date (Y-m-d); defaults to today}';

    protected $description = 'Roll up daily attendance and productivity from activity logs';

    public function handle(): int
    {
        $date = $this->argument('date')
            ? Carbon::parse($this->argument('date'))
            : today();

        RollUpDailyAttendance::dispatchSync($date->toDateString());
        GenerateDailyProductivity::dispatchSync($date->toDateString());

        $this->info("Daily rollup complete for {$date->toDateString()}.");

        return self::SUCCESS;
    }
}
