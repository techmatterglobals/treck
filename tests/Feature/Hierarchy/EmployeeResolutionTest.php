<?php

namespace Tests\Feature\Hierarchy;

use App\Models\Computer;
use App\Models\ComputerUser;
use App\Models\Employee;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Agent\EmployeeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 11 — shared-computer employee resolution: computer + Windows username →
 * employee via computer_users, with pending mappings for unknown accounts and a
 * legacy fallback that keeps existing single-user computers working unchanged.
 */
class EmployeeResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Role::findOrCreate('admin', 'web');
    }

    private function resolver(): EmployeeResolver
    {
        return app(EmployeeResolver::class);
    }

    private function employee(): Employee
    {
        return Employee::factory()->create();
    }

    public function test_first_windows_account_on_an_assigned_computer_adopts_its_employee(): void
    {
        $employee = $this->employee();
        $computer = Computer::factory()->create(['employee_id' => $employee->id]);

        $identity = $this->resolver()->resolve($computer, 'hassan');

        $this->assertSame($employee->id, $identity->employeeId);
        $this->assertDatabaseHas('computer_users', [
            'computer_id' => $computer->id,
            'windows_username' => 'hassan',
            'employee_id' => $employee->id,
        ]);
    }

    public function test_known_windows_account_resolves_to_its_mapped_employee(): void
    {
        $computer = Computer::factory()->create(['employee_id' => null]);
        $zain = $this->employee();
        ComputerUser::factory()->create([
            'computer_id' => $computer->id,
            'windows_username' => 'evening_user',
            'employee_id' => $zain->id,
        ]);

        $identity = $this->resolver()->resolve($computer, 'evening_user');

        $this->assertSame($zain->id, $identity->employeeId);
    }

    public function test_shared_computer_resolves_each_shift_to_the_right_employee(): void
    {
        $computer = Computer::factory()->create(['employee_id' => null]);
        $hassan = $this->employee();
        $zain = $this->employee();

        ComputerUser::factory()->create(['computer_id' => $computer->id, 'windows_username' => 'morning_user', 'employee_id' => $hassan->id]);
        ComputerUser::factory()->create(['computer_id' => $computer->id, 'windows_username' => 'evening_user', 'employee_id' => $zain->id]);

        $this->assertSame($hassan->id, $this->resolver()->resolve($computer, 'morning_user')->employeeId);
        $this->assertSame($zain->id, $this->resolver()->resolve($computer, 'evening_user')->employeeId);
    }

    public function test_unknown_windows_user_creates_a_pending_mapping_and_notifies(): void
    {
        // A shared computer that already has one mapped account, so a new account
        // is genuinely unrecognized (not the single-user adopt case).
        $computer = Computer::factory()->create(['employee_id' => null]);
        ComputerUser::factory()->create(['computer_id' => $computer->id, 'windows_username' => 'known', 'employee_id' => $this->employee()->id]);

        // An admin must exist to receive the alert.
        tap(User::factory()->create(), fn (User $u) => $u->assignRole('admin'));

        $identity = $this->resolver()->resolve($computer, 'stranger');

        $this->assertNull($identity->employeeId);
        $this->assertTrue($identity->isPending());
        $this->assertDatabaseHas('computer_users', [
            'computer_id' => $computer->id,
            'windows_username' => 'stranger',
            'employee_id' => null,
        ]);
        // Super Admin was notified (in-app log created by the engine).
        $this->assertTrue(
            NotificationLog::where('event_type', 'system.unknown_user')->exists()
        );
    }

    public function test_blank_username_falls_back_to_the_computer_employee_legacy(): void
    {
        $employee = $this->employee();
        $computer = Computer::factory()->create(['employee_id' => $employee->id]);

        $identity = $this->resolver()->resolve($computer, null);

        $this->assertSame($employee->id, $identity->employeeId);
        // No mapping row is created on the legacy path.
        $this->assertSame(0, ComputerUser::count());
    }

    public function test_system_service_accounts_are_ignored(): void
    {
        $employee = $this->employee();
        $computer = Computer::factory()->create(['employee_id' => $employee->id]);

        foreach (['SYSTEM', 'NETWORK SERVICE', 'PC-100$', 'DOMAIN\\SYSTEM'] as $account) {
            $identity = $this->resolver()->resolve($computer, $account);
            $this->assertSame($employee->id, $identity->employeeId, "account {$account}");
        }

        $this->assertSame(0, ComputerUser::count());
    }

    public function test_domain_prefix_is_stripped(): void
    {
        $computer = Computer::factory()->create(['employee_id' => null]);
        $employee = $this->employee();
        ComputerUser::factory()->create(['computer_id' => $computer->id, 'windows_username' => 'hassan', 'employee_id' => $employee->id]);

        $identity = $this->resolver()->resolve($computer, 'CORP\\hassan');

        $this->assertSame($employee->id, $identity->employeeId);
    }
}
