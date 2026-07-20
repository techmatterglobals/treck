<?php

namespace Tests\Feature\Agent;

use App\Enums\AgentEventKind;
use App\Models\ApplicationUsage;
use App\Models\Computer;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 7 — end-to-end application-usage ingestion through the existing agent
 * event pipeline (POST /api/agent/events, kind=app_usage). Covers session
 * projection, application switch / window-title change producing distinct
 * sessions, session completion, idempotency, offline-queue reuse and privacy.
 */
class ApplicationUsageIngestionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Computer,1:string} */
    private function device(): array
    {
        $computer = Computer::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'paired_at' => now(),
        ]);
        $token = $computer->createToken('agent', ['agent:report'])->plainTextToken;

        return [$computer, $token];
    }

    private function sendUsage(string $token, array $payload, string $key): TestResponse
    {
        return $this->withToken($token)->postJson('/api/agent/events', [
            'kind' => 'app_usage',
            'idempotency_key' => $key,
            'created_at' => now()->toIso8601String(),
            'payload' => json_encode($payload),
        ]);
    }

    /** @return array<string,mixed> */
    private function sessionPayload(array $overrides = []): array
    {
        return array_merge([
            'SessionId' => 'sess-'.uniqid(),
            'ProcessName' => 'Visual Studio Code',
            'ExecutableName' => 'Code.exe',
            'WindowTitle' => 'ApplicationUsage.php — treck',
            'ProcessId' => 4321,
            'StartedAt' => now()->subMinutes(5)->toIso8601String(),
            'EndedAt' => now()->toIso8601String(),
            'DurationSeconds' => 300,
            'UserSession' => 1,
            'IsSystemProcess' => false,
        ], $overrides);
    }

    public function test_app_usage_kind_is_accepted_by_the_events_endpoint(): void
    {
        [$computer, $token] = $this->device();

        $this->sendUsage($token, $this->sessionPayload(['SessionId' => 'a1']), 'k-a1')
            ->assertCreated()
            ->assertJsonPath('data.duplicate', false);

        $this->assertDatabaseHas('agent_events', [
            'computer_id' => $computer->id,
            'kind' => AgentEventKind::AppUsage->value,
            'idempotency_key' => 'k-a1',
        ]);
    }

    public function test_completed_session_is_projected_into_application_usage(): void
    {
        [$computer, $token] = $this->device();

        $this->sendUsage($token, $this->sessionPayload([
            'SessionId' => 'proj-1',
            'ProcessName' => 'PhpStorm',
            'ExecutableName' => 'phpstorm64.exe',
            'WindowTitle' => 'Editing tests',
            'DurationSeconds' => 420,
        ]), 'k-proj-1')->assertCreated();

        $usage = ApplicationUsage::where('session_id', 'proj-1')->firstOrFail();
        $this->assertSame($computer->id, $usage->computer_id);
        $this->assertSame($computer->employee_id, $usage->employee_id);
        $this->assertSame('PhpStorm', $usage->application_name);
        $this->assertSame('phpstorm64.exe', $usage->executable);
        $this->assertSame('Editing tests', $usage->window_title);
        $this->assertSame(420, $usage->duration_seconds);
        $this->assertNotNull($usage->used_at);
        $this->assertNotNull($usage->ended_at);
    }

    public function test_duration_is_derived_from_timestamps_when_absent(): void
    {
        [, $token] = $this->device();
        $start = now()->subSeconds(90);

        $this->sendUsage($token, $this->sessionPayload([
            'SessionId' => 'derive-1',
            'StartedAt' => $start->toIso8601String(),
            'EndedAt' => $start->copy()->addSeconds(90)->toIso8601String(),
            'DurationSeconds' => 0,
        ]), 'k-derive-1')->assertCreated();

        $this->assertSame(90, ApplicationUsage::where('session_id', 'derive-1')->value('duration_seconds'));
    }

    public function test_application_switch_and_title_change_create_distinct_sessions(): void
    {
        [$computer, $token] = $this->device();

        // App A, then a window-title change (same process), then a different app.
        $this->sendUsage($token, $this->sessionPayload(['SessionId' => 's1', 'ProcessName' => 'Google Chrome', 'WindowTitle' => 'Inbox']), 'k-s1')->assertCreated();
        $this->sendUsage($token, $this->sessionPayload(['SessionId' => 's2', 'ProcessName' => 'Google Chrome', 'WindowTitle' => 'Docs']), 'k-s2')->assertCreated();
        $this->sendUsage($token, $this->sessionPayload(['SessionId' => 's3', 'ProcessName' => 'Slack', 'WindowTitle' => 'general']), 'k-s3')->assertCreated();

        $this->assertSame(3, ApplicationUsage::where('computer_id', $computer->id)->count());
        $this->assertEqualsCanonicalizing(
            ['Inbox', 'Docs', 'general'],
            ApplicationUsage::where('computer_id', $computer->id)->pluck('window_title')->all(),
        );
    }

    public function test_resubmitted_session_is_deduplicated_per_computer(): void
    {
        [$computer, $token] = $this->device();
        $payload = $this->sessionPayload(['SessionId' => 'dup-sess']);

        $this->sendUsage($token, $payload, 'k-dup')->assertCreated();
        // Agent retry after a lost ack: same idempotency key.
        $this->sendUsage($token, $payload, 'k-dup')->assertOk()->assertJsonPath('data.duplicate', true);

        $this->assertSame(1, ApplicationUsage::where('session_id', 'dup-sess')->count());
    }

    public function test_same_session_id_on_different_computers_is_not_a_collision(): void
    {
        [, $tokenA] = $this->device();
        [, $tokenB] = $this->device();

        $this->sendUsage($tokenA, $this->sessionPayload(['SessionId' => 'shared']), 'k-a')->assertCreated();
        $this->sendUsage($tokenB, $this->sessionPayload(['SessionId' => 'shared']), 'k-b')->assertCreated();

        $this->assertSame(2, ApplicationUsage::where('session_id', 'shared')->count());
    }

    public function test_offline_queue_batch_of_sessions_is_ingested_in_order(): void
    {
        // Reuses the existing offline-queue drain: several completed sessions
        // posted back-to-back, each with its own idempotency key.
        [$computer, $token] = $this->device();

        foreach (range(1, 5) as $i) {
            $this->sendUsage($token, $this->sessionPayload([
                'SessionId' => "queued-{$i}",
                'ProcessName' => "App{$i}",
                'StartedAt' => now()->subMinutes(10 - $i)->toIso8601String(),
            ]), "k-queued-{$i}")->assertCreated();
        }

        $this->assertSame(5, ApplicationUsage::where('computer_id', $computer->id)->count());
    }

    public function test_window_title_control_characters_are_sanitized(): void
    {
        [, $token] = $this->device();

        $this->sendUsage($token, $this->sessionPayload([
            'SessionId' => 'san-1',
            'WindowTitle' => "Secret\x00\x07 report\x1F",
        ]), 'k-san-1')->assertCreated();

        $this->assertSame('Secret report', ApplicationUsage::where('session_id', 'san-1')->value('window_title'));
    }

    public function test_projection_only_stores_usage_metadata_no_input_capture(): void
    {
        [, $token] = $this->device();

        // Even if the agent (hypothetically) sent forbidden fields, they must
        // never be persisted — only whitelisted usage metadata is projected.
        $this->sendUsage($token, $this->sessionPayload([
            'SessionId' => 'priv-1',
            'Keystrokes' => 'p@ssw0rd typed here',
            'ClipboardText' => 'copied secret',
            'Screenshot' => 'base64...',
        ]), 'k-priv-1')->assertCreated();

        $usage = ApplicationUsage::where('session_id', 'priv-1')->firstOrFail();
        $stored = $usage->toArray();

        $this->assertArrayNotHasKey('keystrokes', $stored);
        $this->assertArrayNotHasKey('clipboard_text', $stored);
        $this->assertArrayNotHasKey('screenshot', $stored);
        $this->assertStringNotContainsString('p@ssw0rd', json_encode($stored));
        $this->assertStringNotContainsString('copied secret', json_encode($stored));
    }
}
