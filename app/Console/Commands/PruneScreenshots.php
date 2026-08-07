<?php

namespace App\Console\Commands;

use App\Models\Screenshot;
use App\Services\Screenshots\ScreenshotStorageService;
use Illuminate\Console\Command;

/**
 * Enforces the screenshot retention policy (Phase 8): deletes both the image
 * file and its metadata row for captures older than
 * `treck.retention.screenshot_days`. Runs in chunks so memory stays flat on
 * large tables. A retention of 0 disables pruning.
 *
 * Schedule it daily (see routes/console.php):
 *
 *   Schedule::command('treck:prune-screenshots')->dailyAt('01:00');
 */
class PruneScreenshots extends Command
{
    protected $signature = 'treck:prune-screenshots {--days= : Override the retention window in days}';

    protected $description = 'Delete screenshots (file + row) older than the retention window';

    public function handle(ScreenshotStorageService $storage): int
    {
        $days = (int) ($this->option('days') ?? config('treck.retention.screenshot_days', 30));

        if ($days <= 0) {
            $this->info('Screenshot retention is disabled (days <= 0); nothing pruned.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;

        Screenshot::where('captured_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(200, function ($screenshots) use ($storage, &$deleted) {
                foreach ($screenshots as $screenshot) {
                    $storage->delete($screenshot);   // best-effort file removal
                    $screenshot->delete();
                    $deleted++;
                }
            });

        $this->info("Pruned {$deleted} screenshot(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
