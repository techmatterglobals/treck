<?php

namespace Tests\Feature\Hierarchy;

use App\Models\AgentEvent;
use App\Models\Computer;
use App\Models\ComputerUser;
use App\Models\Employee;
use App\Services\Agent\AgentEventIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 11 — the event ingestion pipeline attributes each event to the employee
 * behind the reported Windows account (from the payload's SourceUser/
 * WindowsUsername stamp), while legacy payloads without a username keep
 * resolving to the computer's assigned employee.
 */
class SharedComputerIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Role::findOrCreate('admin', 'web');
    }

    private function ingest(Computer $computer, array $payload, string $key): AgentEvent
    {
        return app(AgentEventIngestionService::class)->ingest($computer, [
            'kind' => 'heartbeat',
            'idempotency_key' => $key,
            'created_at' => now()->toIso8601String(),
            'payload' => json_encode($payload),
        ]);
    }

    public function test_event_is_attributed_via_windows_username_on_a_shared_computer(): void
    {
        $computer = Computer::factory()->create(['employee_id' => null]);
        $hassan = Employee::factory()->create();
        $zain = Employee::factory()->create();
        ComputerUser::factory()->create(['computer_id' => $computer->id, 'windows_username' => 'morning_user', 'employee_id' => $hassan->id]);
        ComputerUser::factory()->create(['computer_id' => $computer->id, 'windows_username' => 'evening_user', 'employee_id' => $zain->id]);

        $morning = $this->ingest($computer, ['SourceUser' => 'morning_user', 'active_seconds' => 60], 'k-morning');
        $evening = $this->ingest($computer, ['WindowsUsername' => 'evening_user', 'active_seconds' => 60], 'k-evening');

        $this->assertSame($hassan->id, $morning->employee_id);
        $this->assertSame($zain->id, $evening->employee_id);
    }

    public function test_legacy_event_without_username_uses_the_computer_employee(): void
    {
        $employee = Employee::factory()->create();
        $computer = Computer::factory()->create(['employee_id' => $employee->id]);

        $event = $this->ingest($computer, ['active_seconds' => 60], 'k-legacy');

        $this->assertSame($employee->id, $event->employee_id);
        $this->assertSame(0, ComputerUser::count());
    }
}
