<?php

namespace Tests\Feature\Presence;

use App\Enums\PresenceStatus;
use App\Events\PresenceChanged;
use App\Models\Computer;
use App\Models\ComputerPresence;
use App\Models\Employee;
use App\Services\Presence\PresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The "missing heartbeat -> Offline" sweep (Phase 6).
 */
class PresenceOfflineTimeoutTest extends TestCase
{
    use RefreshDatabase;

    private function presence(PresenceStatus $status, bool $stale): ComputerPresence
    {
        $computer = Computer::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'paired_at' => now(),
        ]);

        $factory = ComputerPresence::factory()->status($status)->for($computer);

        return $stale ? $factory->stale()->create() : $factory->create();
    }

    public function test_sweep_marks_quiet_online_computer_offline(): void
    {
        $stale = $this->presence(PresenceStatus::Active, stale: true);

        $changed = app(PresenceService::class)->sweepOffline();

        $this->assertCount(1, $changed);
        $this->assertSame(PresenceStatus::Offline, $stale->fresh()->status);
    }

    public function test_sweep_leaves_recently_seen_computer_online(): void
    {
        $fresh = $this->presence(PresenceStatus::Active, stale: false);

        app(PresenceService::class)->sweepOffline();

        $this->assertSame(PresenceStatus::Active, $fresh->fresh()->status);
    }

    public function test_sweep_ignores_already_offline_and_logged_out(): void
    {
        $loggedOut = $this->presence(PresenceStatus::LoggedOut, stale: true);

        $changed = app(PresenceService::class)->sweepOffline();

        $this->assertCount(0, $changed);
        $this->assertSame(PresenceStatus::LoggedOut, $loggedOut->fresh()->status);
    }

    public function test_command_broadcasts_each_offline_transition(): void
    {
        Event::fake([PresenceChanged::class]);
        $this->presence(PresenceStatus::Idle, stale: true);

        $this->artisan('treck:presence-sweep')->assertSuccessful();

        Event::assertDispatchedTimes(PresenceChanged::class, 1);
    }
}
