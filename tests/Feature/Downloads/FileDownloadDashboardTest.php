<?php

namespace Tests\Feature\Downloads;

use App\Livewire\Downloads\FileDownloadDashboard;
use App\Models\Computer;
use App\Models\Employee;
use App\Models\FileDownload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 12 — File Downloads dashboard: authorization (admin/manager only),
 * manager scoping, filtering/search, and the detail page.
 */
class FileDownloadDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        foreach (['view dashboard', 'view own data'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('manager', 'web');
        Role::findOrCreate('employee', 'web');
    }

    private function superAdmin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole('admin'));
    }

    private function manager(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole('manager'));
    }

    private function download(User $manager, string $file): FileDownload
    {
        $employee = Employee::factory()->create(['manager_user_id' => $manager->id]);
        $computer = Computer::factory()->create(['employee_id' => $employee->id]);

        return FileDownload::factory()->create([
            'employee_id' => $employee->id,
            'computer_id' => $computer->id,
            'file_name' => $file,
        ]);
    }

    // ---- Authorization -----------------------------------------------------

    public function test_guest_is_redirected(): void
    {
        $this->get('/downloads')->assertRedirect('/login');
    }

    public function test_employee_is_forbidden(): void
    {
        $employee = tap(User::factory()->create(), fn (User $u) => $u->assignRole('employee'));
        $this->actingAs($employee)->get('/downloads')->assertForbidden();
    }

    public function test_admin_and_manager_can_view(): void
    {
        $this->actingAs($this->superAdmin())->get('/downloads')->assertOk();
        $this->actingAs($this->manager())->get('/downloads')->assertOk();
    }

    // ---- Scoping -----------------------------------------------------------

    public function test_manager_sees_only_their_teams_downloads(): void
    {
        $m1 = $this->manager();
        $m2 = $this->manager();
        $this->download($m1, 'MineReport.pdf');
        $this->download($m2, 'TheirsReport.pdf');

        Livewire::actingAs($m1)->test(FileDownloadDashboard::class)
            ->assertSee('MineReport.pdf')
            ->assertDontSee('TheirsReport.pdf');
    }

    public function test_super_admin_sees_all_downloads(): void
    {
        $m1 = $this->manager();
        $m2 = $this->manager();
        $this->download($m1, 'AlphaReport.pdf');
        $this->download($m2, 'BetaReport.pdf');

        Livewire::actingAs($this->superAdmin())->test(FileDownloadDashboard::class)
            ->assertSee('AlphaReport.pdf')
            ->assertSee('BetaReport.pdf');
    }

    public function test_extension_filter_narrows_results(): void
    {
        $admin = $this->superAdmin();
        $employee = Employee::factory()->create();
        $computer = Computer::factory()->create(['employee_id' => $employee->id]);
        FileDownload::factory()->create(['employee_id' => $employee->id, 'computer_id' => $computer->id, 'file_name' => 'setup.exe', 'file_extension' => 'exe']);
        FileDownload::factory()->create(['employee_id' => $employee->id, 'computer_id' => $computer->id, 'file_name' => 'doc.pdf', 'file_extension' => 'pdf']);

        Livewire::actingAs($admin)->test(FileDownloadDashboard::class)
            ->set('extension', 'exe')
            ->assertSee('setup.exe')
            ->assertDontSee('doc.pdf');
    }

    public function test_non_admin_cannot_mount_component(): void
    {
        $employee = tap(User::factory()->create(), fn (User $u) => $u->assignRole('employee'));
        Livewire::actingAs($employee)->test(FileDownloadDashboard::class)->assertForbidden();
    }

    // ---- Detail page -------------------------------------------------------

    public function test_manager_cannot_view_another_teams_detail(): void
    {
        $m1 = $this->manager();
        $m2 = $this->manager();
        $mine = $this->download($m1, 'mine.pdf');
        $theirs = $this->download($m2, 'theirs.pdf');

        $this->actingAs($m1)->get(route('downloads.show', $mine))->assertOk();
        $this->actingAs($m1)->get(route('downloads.show', $theirs))->assertForbidden();
    }

    public function test_detail_page_shows_metadata_only(): void
    {
        $admin = $this->superAdmin();
        $employee = Employee::factory()->create();
        $computer = Computer::factory()->create(['employee_id' => $employee->id]);
        $download = FileDownload::factory()->create([
            'employee_id' => $employee->id,
            'computer_id' => $computer->id,
            'file_name' => 'secret.pdf',
            'local_path' => 'C:\\Users\\x\\Downloads\\secret.pdf',
        ]);

        $this->actingAs($admin)->get(route('downloads.show', $download))
            ->assertOk()
            ->assertSee('secret.pdf')
            ->assertSee('Metadata only');
    }
}
