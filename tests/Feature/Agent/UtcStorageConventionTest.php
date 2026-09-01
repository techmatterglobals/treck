<?php

namespace Tests\Feature\Agent;

use App\Models\Computer;
use App\Models\Employee;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves the storage convention the UtcDateTime cast relies on: agent-sourced
 * instant columns are written as UTC wall-clock digits, while now()-sourced
 * columns (received_at, last_synced_at) are written in the app timezone.
 *
 * The whole suite runs the entire ingest → project → store pipeline with
 * APP_TIMEZONE forced to Asia/Karachi (the production server), then reads the
 * columns RAW from the database (DB::table, bypassing every Eloquent cast) and
 * asserts the exact digits. This is the empirical guarantee that reading those
 * digits back as UTC is correct — and that the cast never shifts them.
 */
class UtcStorageConventionTest extends TestCase
{
    use RefreshDatabase;

    /** A fixed device-side capture instant, expressed in UTC with an offset. */
    private const CREATED_AT = '2026-08-03T13:19:39+00:00';

    /** Its UTC wall-clock digits, as they must appear in the database. */
    private const UTC_DIGITS = '2026-08-03 13:19:39';

    protected function setUp(): void
    {
        parent::setUp();

        // Simulate the live server exactly: APP_TIMEZONE=Asia/Karachi (+05:00).
        config(['app.timezone' => 'Asia/Karachi']);
        date_default_timezone_set('Asia/Karachi');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set('UTC');
        parent::tearDown();
    }

    private function ingest(Computer $computer, array $body): void
    {
        $token = $computer->createToken('agent', ['agent:report'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/agent/events', $body)
            ->assertCreated();
    }

    public function test_occurred_at_is_stored_in_utc_and_received_at_in_app_tz(): void
    {
        $employee = Employee::factory()->forOrganization(Organization::factory()->create())->create();
        $computer = Computer::factory()->forEmployee($employee)->create(['paired_at' => now()]);

        $this->ingest($computer, [
            'kind' => 'heartbeat',
            'idempotency_key' => 'hb-utc',
            'created_at' => self::CREATED_AT,
            'payload' => json_encode(['IsIdle' => false, 'ActiveSeconds' => 60]),
        ]);

        $row = DB::table('agent_events')->where('idempotency_key', 'hb-utc')->first();

        // occurred_at keeps the device's UTC digits, NOT shifted to +05:00.
        $this->assertSame(self::UTC_DIGITS, $this->trim($row->occurred_at));

        // received_at is the server's now() — Asia/Karachi digits (~5h ahead).
        $received = $this->trim($row->received_at);
        $this->assertNotSame(self::UTC_DIGITS, $received);
        $this->assertSame(
            now('Asia/Karachi')->format('Y-m-d'),
            substr($received, 0, 10),
            'received_at should carry the Karachi calendar date/time (now()).',
        );
    }

    public function test_presence_activity_is_utc_but_synced_at_is_app_tz(): void
    {
        $employee = Employee::factory()->forOrganization(Organization::factory()->create())->create();
        $computer = Computer::factory()->forEmployee($employee)->create(['paired_at' => now()]);

        $this->ingest($computer, [
            'kind' => 'heartbeat',
            'idempotency_key' => 'hb-presence',
            'created_at' => self::CREATED_AT,
            'payload' => json_encode(['IsIdle' => false, 'ActiveSeconds' => 60]),
        ]);

        $row = DB::table('computer_presence')->where('computer_id', $computer->id)->first();

        // Agent-sourced instants: UTC digits.
        $this->assertSame(self::UTC_DIGITS, $this->trim($row->last_activity_at));
        $this->assertSame(self::UTC_DIGITS, $this->trim($row->last_heartbeat_at));
        $this->assertSame(self::UTC_DIGITS, $this->trim($row->last_event_at));

        // now()-sourced mirror: Karachi digits (deliberately NOT UtcDateTime).
        $this->assertNotSame(self::UTC_DIGITS, $this->trim($row->last_synced_at));
    }

    public function test_app_usage_used_at_is_stored_in_utc(): void
    {
        $employee = Employee::factory()->forOrganization(Organization::factory()->create())->create();
        $computer = Computer::factory()->forEmployee($employee)->create(['paired_at' => now()]);

        $this->ingest($computer, [
            'kind' => 'app_usage',
            'idempotency_key' => 'au-utc',
            'created_at' => self::CREATED_AT,
            'payload' => json_encode([
                'SessionId' => 'sess-1',
                'ProcessName' => 'chrome',
                'StartedAt' => self::CREATED_AT,
                'EndedAt' => '2026-08-03T13:25:39+00:00',
                'DurationSeconds' => 360,
            ]),
        ]);

        $row = DB::table('application_usage')->where('session_id', 'sess-1')->first();

        $this->assertSame(self::UTC_DIGITS, $this->trim($row->used_at));
        $this->assertSame('2026-08-03 13:25:39', $this->trim($row->ended_at));
    }

    public function test_file_download_downloaded_at_is_stored_in_utc(): void
    {
        $employee = Employee::factory()->forOrganization(Organization::factory()->create())->create();
        $computer = Computer::factory()->forEmployee($employee)->create(['paired_at' => now()]);

        $this->ingest($computer, [
            'kind' => 'file_download',
            'idempotency_key' => 'dl-utc',
            'created_at' => self::CREATED_AT,
            'payload' => json_encode([
                'FileName' => 'report.pdf',
                'FileExtension' => 'pdf',
                'DownloadedAt' => self::CREATED_AT,
            ]),
        ]);

        $row = DB::table('file_downloads')->where('event_key', 'dl-utc')->first();

        $this->assertSame(self::UTC_DIGITS, $this->trim($row->downloaded_at));
    }

    /** Normalize a DB datetime string to "Y-m-d H:i:s" (drop any fractional part). */
    private function trim(?string $value): ?string
    {
        return $value === null ? null : substr($value, 0, 19);
    }
}
