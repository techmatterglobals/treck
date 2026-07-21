<?php

namespace Tests\Feature\Agent;

use App\Models\Computer;
use App\Models\Employee;
use App\Models\Screenshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 8 — screenshot upload via the dedicated multipart agent endpoint
 * (POST /api/agent/screenshots). Covers storage, metadata, duplicate detection,
 * offline-order acknowledgement, authentication and validation.
 */
class ScreenshotUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** @return array{0:Computer,1:string} */
    private function device(?Employee $employee = null): array
    {
        $employee ??= Employee::factory()->create();
        $computer = Computer::factory()->create([
            'employee_id' => $employee->id,
            'paired_at' => now(),
        ]);
        $token = $computer->createToken('agent', ['agent:report'])->plainTextToken;

        return [$computer, $token];
    }

    /** A fresh UploadedFile carrying exactly the given bytes (for dedup control). */
    private function uploadFrom(string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ss').'.jpg';
        file_put_contents($path, $bytes);

        return new UploadedFile($path, 'shot.jpg', 'image/jpeg', null, test: true);
    }

    private function upload(string $token, UploadedFile $file, array $meta = []): TestResponse
    {
        return $this->withToken($token)->post('/api/agent/screenshots', array_merge([
            'image' => $file,
            'captured_at' => now()->toIso8601String(),
            'monitor_number' => 0,
            'active_process' => 'Code.exe',
            'active_window_title' => 'Screenshot.php — treck',
            'session_id' => 'cap-'.uniqid(),
        ], $meta), ['Accept' => 'application/json']);
    }

    public function test_stores_a_screenshot_and_its_metadata(): void
    {
        [$computer, $token] = $this->device();
        $file = UploadedFile::fake()->image('shot.jpg', 1920, 1080);

        $this->upload($token, $file, ['monitor_number' => 1])
            ->assertCreated()
            ->assertJsonPath('data.duplicate', false);

        $shot = Screenshot::firstOrFail();
        $this->assertSame($computer->id, $shot->computer_id);
        $this->assertSame($computer->employee_id, $shot->employee_id);
        $this->assertSame(1, $shot->monitor_number);
        $this->assertSame(1920, $shot->width);
        $this->assertSame(1080, $shot->height);
        $this->assertSame(64, strlen($shot->image_hash));
        $this->assertGreaterThan(0, $shot->file_size);
        $this->assertSame('Code.exe', $shot->active_process);

        // The image bytes are on the (private) disk at the recorded path.
        Storage::disk('local')->assertExists($shot->path);
    }

    public function test_stores_event_source_metadata(): void
    {
        [, $token] = $this->device();

        $this->upload($token, UploadedFile::fake()->image('s.jpg'), [
            'source_session_id' => 1,
            'source_user' => 'CORP\\alice',
            'source_process' => 'TreckAgent(helper)',
            'collection_mode' => 'InteractiveHelper',
        ])->assertCreated();

        $shot = Screenshot::firstOrFail();
        $this->assertSame(1, $shot->source_session_id);
        $this->assertSame('CORP\\alice', $shot->source_user);
        $this->assertSame('TreckAgent(helper)', $shot->source_process);
        $this->assertSame('InteractiveHelper', $shot->collection_mode);
    }

    public function test_owner_is_taken_from_the_device_not_the_body(): void
    {
        $employee = Employee::factory()->create();
        [$computer, $token] = $this->device($employee);
        $other = Employee::factory()->create();

        $this->upload($token, UploadedFile::fake()->image('a.jpg'), ['employee_id' => $other->id])
            ->assertCreated();

        $this->assertSame($employee->id, Screenshot::firstOrFail()->employee_id);
    }

    public function test_duplicate_image_is_stored_once_and_returns_success(): void
    {
        [, $token] = $this->device();
        $src = UploadedFile::fake()->image('dup.jpg', 800, 600);
        $bytes = file_get_contents($src->getRealPath());

        $this->upload($token, $this->uploadFrom($bytes))
            ->assertCreated()
            ->assertJsonPath('data.duplicate', false);

        // Same bytes again (agent retry / identical screen): dedup by hash.
        $this->upload($token, $this->uploadFrom($bytes))
            ->assertOk()
            ->assertJsonPath('data.duplicate', true);

        $this->assertSame(1, Screenshot::count());
    }

    public function test_identical_image_on_different_computers_is_not_a_collision(): void
    {
        [, $tokenA] = $this->device();
        [, $tokenB] = $this->device();
        $src = UploadedFile::fake()->image('shared.jpg', 640, 480);
        $bytes = file_get_contents($src->getRealPath());

        $this->upload($tokenA, $this->uploadFrom($bytes))->assertCreated();
        $this->upload($tokenB, $this->uploadFrom($bytes))->assertCreated();

        $this->assertSame(2, Screenshot::count());
    }

    public function test_offline_batch_of_screenshots_is_accepted_in_order(): void
    {
        [$computer, $token] = $this->device();

        foreach (range(1, 4) as $i) {
            $this->upload($token, UploadedFile::fake()->image("shot{$i}.jpg", 400 + $i, 300 + $i), [
                'captured_at' => now()->subMinutes(10 - $i)->toIso8601String(),
            ])->assertCreated();
        }

        $this->assertSame(4, Screenshot::forComputer($computer->id)->count());
    }

    public function test_unauthenticated_upload_is_rejected(): void
    {
        $this->post('/api/agent/screenshots', [
            'image' => UploadedFile::fake()->image('a.jpg'),
            'captured_at' => now()->toIso8601String(),
        ], ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_token_without_agent_ability_is_forbidden(): void
    {
        $computer = Computer::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $token = $computer->createToken('agent', ['something:else'])->plainTextToken;

        $this->upload($token, UploadedFile::fake()->image('a.jpg'))->assertForbidden();
    }

    public function test_missing_image_is_rejected(): void
    {
        [, $token] = $this->device();

        $this->withToken($token)->post('/api/agent/screenshots', [
            'captured_at' => now()->toIso8601String(),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    public function test_non_image_upload_is_rejected(): void
    {
        [, $token] = $this->device();

        $this->withToken($token)->post('/api/agent/screenshots', [
            'image' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            'captured_at' => now()->toIso8601String(),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    public function test_oversize_image_is_rejected(): void
    {
        [, $token] = $this->device();
        config(['treck.screenshots.max_upload_kb' => 100]);

        $this->withToken($token)->post('/api/agent/screenshots', [
            'image' => UploadedFile::fake()->create('big.jpg', 200, 'image/jpeg'),
            'captured_at' => now()->toIso8601String(),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }
}
