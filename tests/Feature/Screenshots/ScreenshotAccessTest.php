<?php

namespace Tests\Feature\Screenshots;

use App\DataObjects\ScreenshotFilter;
use App\Enums\OrganizationRole;
use App\Livewire\Screenshots\ScreenshotDashboard;
use App\Livewire\Screenshots\ScreenshotViewer;
use App\Models\Computer;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Screenshot;
use App\Models\User;
use App\Services\Screenshots\ScreenshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 8 — screenshot dashboard, viewer, authorization, signed image access
 * and reporting queries. Only administrators may view or download screenshots.
 */
class ScreenshotAccessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('employee', 'web');
        $this->organization = Organization::factory()->create();
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), function (User $u) {
            $u->assignRole('admin');
            $this->grantOrganizationRole($u, $this->organization, OrganizationRole::Admin);
        });
    }

    private function employee(): User
    {
        return tap(User::factory()->create(), function (User $u) {
            $u->assignRole('employee');
            $this->grantOrganizationRole($u, $this->organization, OrganizationRole::Employee);
        });
    }

    /** A screenshot row with a backing file on the fake disk. */
    private function screenshot(array $attrs = []): Screenshot
    {
        $employee = Employee::factory()->forOrganization($this->organization)->create();
        $computer = Computer::factory()->forEmployee($employee)->create();
        $shot = Screenshot::factory()->forComputer($computer)->create(array_merge([
            'computer_id' => $computer->id,
            'employee_id' => $computer->employee_id,
            'organization_id' => $this->organization->id,
            'disk' => 'local',
        ], $attrs));

        Storage::disk('local')->put($shot->path, 'fake-image-bytes');

        return $shot;
    }

    // ---- Authorization -----------------------------------------------------

    public function test_guest_is_redirected(): void
    {
        $this->get('/screenshots')->assertRedirect('/login');
    }

    public function test_non_admin_is_forbidden(): void
    {
        $this->actingAs($this->employee())->get('/screenshots')->assertForbidden();
    }

    public function test_admin_can_view_the_dashboard(): void
    {
        $this->actingAs($this->admin())->get('/screenshots')->assertOk();
    }

    public function test_non_admin_cannot_mount_the_dashboard_component(): void
    {
        $this->actingAs($this->employee());
        Livewire::test(ScreenshotDashboard::class)->assertForbidden();
    }

    public function test_admin_can_open_the_viewer(): void
    {
        $shot = $this->screenshot();
        $this->actingAs($this->admin());

        Livewire::test(ScreenshotViewer::class, ['screenshot' => $shot])
            ->assertOk()
            ->assertSee($shot->active_process);
    }

    // ---- Signed image access ----------------------------------------------

    public function test_signed_url_streams_the_image_for_an_admin(): void
    {
        $shot = $this->screenshot();
        $url = URL::temporarySignedRoute('screenshots.image', now()->addMinutes(5), ['screenshot' => $shot->id]);

        $this->actingAs($this->admin())->get($url)->assertOk();
    }

    public function test_unsigned_image_request_is_rejected(): void
    {
        $shot = $this->screenshot();

        // Correct path but no signature → 403 (InvalidSignature).
        $this->actingAs($this->admin())->get("/screenshots/{$shot->id}/image")->assertForbidden();
    }

    public function test_expired_signature_is_rejected(): void
    {
        $shot = $this->screenshot();
        $url = URL::temporarySignedRoute('screenshots.image', now()->subMinute(), ['screenshot' => $shot->id]);

        $this->actingAs($this->admin())->get($url)->assertForbidden();
    }

    public function test_non_admin_cannot_stream_even_with_a_valid_signature(): void
    {
        $shot = $this->screenshot();
        $url = URL::temporarySignedRoute('screenshots.image', now()->addMinutes(5), ['screenshot' => $shot->id]);

        $this->actingAs($this->employee())->get($url)->assertForbidden();
    }

    public function test_admin_can_download_but_employee_cannot(): void
    {
        $shot = $this->screenshot();

        $this->actingAs($this->admin())->get(route('screenshots.download', $shot))->assertOk();
        $this->actingAs($this->employee())->get(route('screenshots.download', $shot))->assertForbidden();
    }

    public function test_dashboard_never_exposes_device_tokens_or_paths(): void
    {
        $employee = Employee::factory()->forOrganization($this->organization)->create();
        $computer = Computer::factory()->forEmployee($employee)->create(['paired_at' => now()]);
        $plain = $computer->createToken('agent', ['agent:report'])->plainTextToken;
        $shot = $this->screenshot([
            'computer_id' => $computer->id,
            'employee_id' => $computer->employee_id,
            'organization_id' => $this->organization->id,
            'captured_at' => now(),
        ]);

        $response = $this->actingAs($this->admin())->get('/screenshots');
        $response->assertOk();
        $response->assertDontSee($plain);
        // The raw storage path (disk key) is never rendered — only signed routes.
        $response->assertDontSee($shot->path);
    }

    // ---- Reporting ---------------------------------------------------------

    public function test_status_and_latest_queries(): void
    {
        $computer = Computer::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        Screenshot::factory()->count(3)->create([
            'computer_id' => $computer->id,
            'employee_id' => $computer->employee_id,
            'captured_at' => now(),
        ]);

        $service = app(ScreenshotService::class);
        $filter = ScreenshotFilter::fromArray(['from' => today()->toDateString(), 'to' => today()->toDateString()]);

        $status = $service->status($filter);
        $this->assertSame(3, $status['total']);
        $this->assertSame(1, $status['computers']);
        $this->assertNotNull($status['last_capture_at']);

        $this->assertSame(3, $service->latest($filter)->total());
    }

    public function test_neighbours_navigate_within_a_computer(): void
    {
        $computer = Computer::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $a = Screenshot::factory()->create(['computer_id' => $computer->id, 'employee_id' => $computer->employee_id, 'captured_at' => now()->subMinutes(10)]);
        $b = Screenshot::factory()->create(['computer_id' => $computer->id, 'employee_id' => $computer->employee_id, 'captured_at' => now()->subMinutes(5)]);
        $c = Screenshot::factory()->create(['computer_id' => $computer->id, 'employee_id' => $computer->employee_id, 'captured_at' => now()]);

        $filter = new ScreenshotFilter(from: now()->subDay(), to: now()->addDay(), computerId: $computer->id);
        $neighbours = app(ScreenshotService::class)->neighbours($filter, $b);

        $this->assertSame($a->id, $neighbours['prev']); // older
        $this->assertSame($c->id, $neighbours['next']); // newer
    }
}
