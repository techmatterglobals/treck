<?php

namespace Tests\Feature\Presence;

use App\Enums\PresenceStatus;
use App\Livewire\Presence\ComputerPresenceDetail;
use App\Livewire\Presence\PresenceBoard;
use App\Models\Computer;
use App\Models\ComputerPresence;
use App\Models\Employee;
use App\Models\User;
use App\Services\Presence\PresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Dashboard read queries, authorization, and Livewire rendering (Phase 6).
 */
class PresenceDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('employee', 'web');
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole('admin'));
    }

    private function employee(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole('employee'));
    }

    private function presence(PresenceStatus $status): ComputerPresence
    {
        $computer = Computer::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'paired_at' => now(),
        ]);

        return ComputerPresence::factory()->status($status)->for($computer)->create();
    }

    // ---- Authorization -----------------------------------------------------

    public function test_guest_is_redirected_from_presence_dashboard(): void
    {
        $this->get('/presence')->assertRedirect('/login');
    }

    public function test_non_admin_is_forbidden(): void
    {
        $this->actingAs($this->employee())->get('/presence')->assertForbidden();
    }

    public function test_admin_can_view_presence_dashboard(): void
    {
        $this->actingAs($this->admin())->get('/presence')->assertOk();
    }

    public function test_non_admin_cannot_mount_the_board_component(): void
    {
        $this->actingAs($this->employee());
        Livewire::test(PresenceBoard::class)->assertForbidden();
    }

    // ---- Dashboard queries -------------------------------------------------

    public function test_summary_counts_by_status(): void
    {
        $this->presence(PresenceStatus::Active);
        $this->presence(PresenceStatus::Idle);
        $this->presence(PresenceStatus::Locked);
        $this->presence(PresenceStatus::LoggedOut);
        $this->presence(PresenceStatus::Offline);
        // A computer with no presence row counts as offline.
        Computer::factory()->create(['employee_id' => Employee::factory()->create()->id]);

        $summary = app(PresenceService::class)->summary();

        $this->assertSame(6, $summary['total']);
        $this->assertSame(3, $summary['online']);       // active + idle + locked
        $this->assertSame(2, $summary['offline']);      // one offline row + one row-less
        $this->assertSame(1, $summary['active']);
        $this->assertSame(1, $summary['idle']);
        $this->assertSame(1, $summary['locked']);
        $this->assertSame(1, $summary['logged_out']);
    }

    public function test_board_renders_computer_rows_for_admin(): void
    {
        $this->actingAs($this->admin());
        $presence = $this->presence(PresenceStatus::Active);

        Livewire::test(PresenceBoard::class)
            ->assertOk()
            ->assertSee($presence->computer->hostname)
            ->assertSee('Active');
    }

    public function test_detail_component_renders_for_admin(): void
    {
        $this->actingAs($this->admin());
        $presence = $this->presence(PresenceStatus::Idle);

        Livewire::test(ComputerPresenceDetail::class, ['computer' => $presence->computer])
            ->assertOk()
            ->assertSee($presence->computer->hostname);
    }

    public function test_presence_never_exposes_device_tokens(): void
    {
        $this->actingAs($this->admin());
        $computer = Computer::factory()->create(['employee_id' => Employee::factory()->create()->id, 'paired_at' => now()]);
        $plain = $computer->createToken('agent', ['agent:report'])->plainTextToken;
        ComputerPresence::factory()->for($computer)->create();

        $response = $this->get('/presence');
        $response->assertOk();
        $response->assertDontSee($plain);
        // The board payload never includes the token column at all.
        $this->assertStringNotContainsString('plainTextToken', $response->getContent());
    }
}
