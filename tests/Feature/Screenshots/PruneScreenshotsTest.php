<?php

namespace Tests\Feature\Screenshots;

use App\Models\Computer;
use App\Models\Employee;
use App\Models\Screenshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 8 — screenshot retention (treck:prune-screenshots): old captures are
 * deleted (row + file); recent ones are kept; a retention of 0 disables pruning.
 */
class PruneScreenshotsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function screenshot(\DateTimeInterface $capturedAt): Screenshot
    {
        $computer = Computer::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $shot = Screenshot::factory()->create([
            'computer_id' => $computer->id,
            'employee_id' => $computer->employee_id,
            'disk' => 'local',
            'captured_at' => $capturedAt,
        ]);
        Storage::disk('local')->put($shot->path, 'bytes');

        return $shot;
    }

    public function test_prunes_old_screenshots_and_their_files(): void
    {
        config(['treck.retention.screenshot_days' => 30]);

        $old = $this->screenshot(now()->subDays(45));
        $recent = $this->screenshot(now()->subDays(5));

        $this->artisan('treck:prune-screenshots')
            ->assertSuccessful();

        $this->assertDatabaseMissing('screenshots', ['id' => $old->id]);
        $this->assertDatabaseHas('screenshots', ['id' => $recent->id]);
        Storage::disk('local')->assertMissing($old->path);
        Storage::disk('local')->assertExists($recent->path);
    }

    public function test_retention_zero_disables_pruning(): void
    {
        config(['treck.retention.screenshot_days' => 0]);
        $old = $this->screenshot(now()->subYears(2));

        $this->artisan('treck:prune-screenshots')->assertSuccessful();

        $this->assertDatabaseHas('screenshots', ['id' => $old->id]);
    }

    public function test_days_option_overrides_config(): void
    {
        config(['treck.retention.screenshot_days' => 365]);
        $shot = $this->screenshot(now()->subDays(10));

        $this->artisan('treck:prune-screenshots', ['--days' => 7])->assertSuccessful();

        $this->assertDatabaseMissing('screenshots', ['id' => $shot->id]);
    }
}
