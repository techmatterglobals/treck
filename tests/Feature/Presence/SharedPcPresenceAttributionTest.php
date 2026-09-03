<?php

namespace Tests\Feature\Presence;

use App\Enums\AgentEventKind;
use App\Enums\PresenceStatus;
use App\Models\AgentEvent;
use App\Models\Computer;
use App\Models\ComputerPresence;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\Presence\PresenceProjector;
use App\Services\Presence\PresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Shared-PC live presence attribution (computer_presence.current_employee_id).
 *
 * A computer's static owner (computers.employee_id) is NOT the person live status
 * should follow on a shared machine - that must be whoever the newest accepted
 * event was attributed to. These tests pin that behavior end-to-end through the
 * projector (materialization + stale protection) and the PresenceService read
 * model (computer row, employee status map, online count).
 */
class SharedPcPresenceAttributionTest extends TestCase
{
    use RefreshDatabase;

    private PresenceProjector $projector;

    private PresenceService $presence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projector = app(PresenceProjector::class);
        $this->presence = app(PresenceService::class);
    }

    private function employee(string $name, ?string $department = null): Employee
    {
        return Employee::factory()->create([
            'user_id' => User::factory()->create(['name' => $name])->id,
            'department_id' => $department ? Department::factory()->create(['name' => $department])->id : null,
        ]);
    }

    private function computerOwnedBy(Employee $owner, string $hostname = 'PC-100100004'): Computer
    {
        return Computer::factory()->create([
            'employee_id' => $owner->id,
            'hostname' => $hostname,
            'paired_at' => now(),
        ]);
    }

    /** Store + project a heartbeat attributed to $employee at instant $at. */
    private function heartbeat(Computer $computer, ?Employee $employee, bool $idle, string $at): ?ComputerPresence
    {
        $when = Carbon::parse($at);

        $event = AgentEvent::create([
            'computer_id' => $computer->id,
            'employee_id' => $employee?->id,
            'kind' => AgentEventKind::Heartbeat,
            'idempotency_key' => uniqid('k', true),
            'payload' => ['IsIdle' => $idle, 'IdleTimeSeconds' => $idle ? 300 : 0],
            'occurred_at' => $when,
            'received_at' => $when,
        ]);

        return $this->projector->project($event);
    }

    private function statusOf(Employee $employee): PresenceStatus
    {
        return $this->presence->employeeStatusMap([$employee])[$employee->id];
    }

    private function presenceRow(Computer $computer): ComputerPresence
    {
        return ComputerPresence::where('computer_id', $computer->id)->firstOrFail();
    }

    // A. Single-user computer: current_employee_id tracks the sole employee.
    public function test_single_user_computer_sets_current_employee(): void
    {
        $emp = $this->employee('Solo User');
        $computer = $this->computerOwnedBy($emp, 'PC-SOLO');

        $this->heartbeat($computer, $emp, idle: false, at: '2026-09-03 09:00:00');

        $this->assertSame($emp->id, $this->presenceRow($computer)->current_employee_id);
        $this->assertSame(PresenceStatus::Active, $this->statusOf($emp));
    }

    // B. Shared PC, initial employee online.
    public function test_shared_pc_initial_employee_is_active_owner_only(): void
    {
        $isneha = $this->employee('Isneha Saeed');
        $muzammil = $this->employee('Muzammil Aziz');
        $computer = $this->computerOwnedBy($isneha);

        $this->heartbeat($computer, $isneha, idle: false, at: '2026-09-03 13:00:00');

        $this->assertSame($isneha->id, $this->presenceRow($computer)->current_employee_id);
        $this->assertSame(PresenceStatus::Active, $this->statusOf($isneha));
        $this->assertSame(PresenceStatus::Offline, $this->statusOf($muzammil));
    }

    // C. Shared PC switch: a newer heartbeat from another employee takes over.
    public function test_shared_pc_switch_moves_presence_to_new_employee(): void
    {
        $isneha = $this->employee('Isneha Saeed');
        $muzammil = $this->employee('Muzammil Aziz');
        $computer = $this->computerOwnedBy($isneha);

        $this->heartbeat($computer, $isneha, idle: false, at: '2026-09-03 13:00:00');
        $this->heartbeat($computer, $muzammil, idle: false, at: '2026-09-03 14:05:30');

        $this->assertSame($muzammil->id, $this->presenceRow($computer)->current_employee_id);
        // computers.employee_id (static owner) is untouched.
        $this->assertSame($isneha->id, $computer->fresh()->employee_id);
        $this->assertSame(PresenceStatus::Active, $this->statusOf($muzammil));
        $this->assertSame(PresenceStatus::Offline, $this->statusOf($isneha));
    }

    // D. Shared PC idle: current employee unchanged; status Idle.
    public function test_shared_pc_idle_keeps_current_employee(): void
    {
        $isneha = $this->employee('Isneha Saeed');
        $muzammil = $this->employee('Muzammil Aziz');
        $computer = $this->computerOwnedBy($isneha);

        $this->heartbeat($computer, $muzammil, idle: false, at: '2026-09-03 14:05:30');
        $this->heartbeat($computer, $muzammil, idle: true, at: '2026-09-03 14:15:30');

        $this->assertSame($muzammil->id, $this->presenceRow($computer)->current_employee_id);
        $this->assertSame(PresenceStatus::Idle, $this->statusOf($muzammil));
        $this->assertSame(PresenceStatus::Offline, $this->statusOf($isneha));
    }

    // E. Switch back to the original employee on a newer heartbeat.
    public function test_shared_pc_switches_back_to_original_employee(): void
    {
        $isneha = $this->employee('Isneha Saeed');
        $muzammil = $this->employee('Muzammil Aziz');
        $computer = $this->computerOwnedBy($isneha);

        $this->heartbeat($computer, $muzammil, idle: false, at: '2026-09-03 14:05:30');
        $this->heartbeat($computer, $isneha, idle: false, at: '2026-09-03 15:00:00');

        $this->assertSame($isneha->id, $this->presenceRow($computer)->current_employee_id);
        $this->assertSame(PresenceStatus::Active, $this->statusOf($isneha));
        $this->assertSame(PresenceStatus::Offline, $this->statusOf($muzammil));
    }

    // F. Stale/out-of-order: a delayed older event must not steal presence.
    public function test_stale_event_does_not_steal_presence(): void
    {
        $isneha = $this->employee('Isneha Saeed');
        $muzammil = $this->employee('Muzammil Aziz');
        $computer = $this->computerOwnedBy($isneha);

        // Newer Muzammil heartbeat first, then a delayed OLDER Isneha heartbeat.
        $this->heartbeat($computer, $muzammil, idle: false, at: '2026-09-03 14:05:30');
        $before = $this->presenceRow($computer);

        $result = $this->heartbeat($computer, $isneha, idle: false, at: '2026-09-03 13:55:30');

        $this->assertNull($result, 'stale event must be rejected');
        $after = $this->presenceRow($computer);
        $this->assertSame($muzammil->id, $after->current_employee_id);
        $this->assertSame(PresenceStatus::Active, $this->statusOf($muzammil));
        $this->assertSame(PresenceStatus::Offline, $this->statusOf($isneha));
        // Timestamps did not roll backwards.
        $this->assertEquals($before->last_event_at, $after->last_event_at);
    }

    // Null-employee event must not erase a known current employee.
    public function test_null_employee_event_preserves_current_employee(): void
    {
        $isneha = $this->employee('Isneha Saeed');
        $muzammil = $this->employee('Muzammil Aziz');
        $computer = $this->computerOwnedBy($isneha);

        $this->heartbeat($computer, $muzammil, idle: false, at: '2026-09-03 14:05:30');
        // A later but unattributed event (employee_id null).
        $this->heartbeat($computer, null, idle: true, at: '2026-09-03 14:15:30');

        $this->assertSame($muzammil->id, $this->presenceRow($computer)->current_employee_id);
    }

    // G. Online employee count: shared PC counts the runtime owner, once.
    public function test_online_count_uses_runtime_owner_not_static_owner(): void
    {
        $isneha = $this->employee('Isneha Saeed');
        $muzammil = $this->employee('Muzammil Aziz');
        $computer = $this->computerOwnedBy($isneha);

        $this->heartbeat($computer, $muzammil, idle: false, at: '2026-09-03 14:05:30');

        // Exactly one online employee (Muzammil); the static owner is not counted.
        $this->assertSame(1, $this->presence->onlineEmployeeCount());
    }

    // H. Live computer row shows the runtime employee + their department.
    public function test_live_computer_row_shows_current_employee(): void
    {
        $isneha = $this->employee('Isneha Saeed', 'Support');
        $muzammil = $this->employee('Muzammil Aziz', 'DP Sales');
        $computer = $this->computerOwnedBy($isneha);

        $this->heartbeat($computer, $muzammil, idle: false, at: '2026-09-03 14:05:30');

        $row = $this->presence->rows()->firstWhere('computer_id', $computer->id);

        $this->assertSame('Muzammil Aziz', $row['employee']);
        $this->assertSame('DP Sales', $row['department']);
        $this->assertSame(PresenceStatus::Active, $row['status']);
    }

    // I. Today's activity time stays attributed to the acting employee.
    public function test_todays_activity_time_stays_with_acting_employee(): void
    {
        $isneha = $this->employee('Isneha Saeed');
        $muzammil = $this->employee('Muzammil Aziz');
        $computer = $this->computerOwnedBy($isneha);

        // Isneha earlier in the day; Muzammil later. Distinct active/idle seconds.
        AgentEvent::create([
            'computer_id' => $computer->id, 'employee_id' => $isneha->id,
            'kind' => AgentEventKind::Heartbeat, 'idempotency_key' => uniqid('k', true),
            'payload' => ['IsIdle' => false, 'ActiveSeconds' => 100, 'IdleSeconds' => 10],
            'occurred_at' => now(), 'received_at' => now(),
        ]);
        AgentEvent::create([
            'computer_id' => $computer->id, 'employee_id' => $muzammil->id,
            'kind' => AgentEventKind::Heartbeat, 'idempotency_key' => uniqid('k', true),
            'payload' => ['IsIdle' => false, 'ActiveSeconds' => 200, 'IdleSeconds' => 20],
            'occurred_at' => now(), 'received_at' => now(),
        ]);

        $rows = $this->presence->employeeRows();

        $this->assertSame(100, $rows->firstWhere('id', $isneha->id)['active_seconds']);
        $this->assertSame(10, $rows->firstWhere('id', $isneha->id)['idle_seconds']);
        $this->assertSame(200, $rows->firstWhere('id', $muzammil->id)['active_seconds']);
        $this->assertSame(20, $rows->firstWhere('id', $muzammil->id)['idle_seconds']);
    }

    // J. Legacy presence row (null current_employee_id) falls back to static owner.
    public function test_legacy_null_current_employee_falls_back_to_static_owner(): void
    {
        $owner = $this->employee('Legacy Owner');
        $computer = $this->computerOwnedBy($owner, 'PC-LEGACY');
        // Factory creates a presence row WITHOUT current_employee_id (null).
        ComputerPresence::factory()->status(PresenceStatus::Active)->for($computer)->create();

        $this->assertNull($this->presenceRow($computer)->current_employee_id);
        $this->assertSame(PresenceStatus::Active, $this->statusOf($owner));
        $this->assertSame(1, $this->presence->onlineEmployeeCount());
    }

    // K. Multiple computers per employee: best status across attributed rows.
    public function test_multi_computer_employee_uses_best_status(): void
    {
        $emp = $this->employee('Multi Machine');
        $pc1 = $this->computerOwnedBy($emp, 'PC-1');
        $pc2 = $this->computerOwnedBy($emp, 'PC-2');

        // Both currently attributed to the same employee: one Idle, one Active.
        ComputerPresence::factory()->status(PresenceStatus::Idle)->for($pc1)
            ->create(['current_employee_id' => $emp->id]);
        ComputerPresence::factory()->status(PresenceStatus::Active)->for($pc2)
            ->create(['current_employee_id' => $emp->id]);

        $this->assertSame(PresenceStatus::Active, $this->statusOf($emp));
        // Counted once despite two online computers.
        $this->assertSame(1, $this->presence->onlineEmployeeCount());
    }
}
