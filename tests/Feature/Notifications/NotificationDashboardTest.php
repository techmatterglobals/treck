<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationEventType;
use App\Enums\NotificationSeverity;
use App\Enums\OrganizationRole;
use App\Livewire\Notifications\NotificationBell;
use App\Livewire\Notifications\NotificationDashboard;
use App\Livewire\Notifications\NotificationSettings;
use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\NotificationRule;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 9 — dashboard, bell and settings UI: authorization (admin-only),
 * recipient-scoped queries, filtering/search, mark-read actions, live stats,
 * settings persistence and that no secrets leak into the rendered views.
 */
class NotificationDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
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

    private function inApp(User $user, array $attrs = []): NotificationLog
    {
        return NotificationLog::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'recipient_id' => $user->id,
            'channel' => 'in_app',
        ], $attrs));
    }

    // ---- Authorization -----------------------------------------------------

    public function test_guest_is_redirected_from_notifications(): void
    {
        $this->get('/notifications')->assertRedirect('/login');
    }

    public function test_guest_is_redirected_from_settings(): void
    {
        $this->get('/notifications/settings')->assertRedirect('/login');
    }

    public function test_non_admin_is_forbidden_from_notifications(): void
    {
        $this->actingAs($this->employee())->get('/notifications')->assertForbidden();
    }

    public function test_non_admin_is_forbidden_from_settings(): void
    {
        $this->actingAs($this->employee())->get('/notifications/settings')->assertForbidden();
    }

    public function test_admin_can_view_notifications_and_settings(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/notifications')->assertOk();
        $this->actingAs($admin)->get('/notifications/settings')->assertOk();
    }

    public function test_non_admin_cannot_mount_dashboard_component(): void
    {
        $this->actingAs($this->employee());
        Livewire::test(NotificationDashboard::class)->assertForbidden();
    }

    public function test_non_admin_cannot_mount_settings_component(): void
    {
        $this->actingAs($this->employee());
        Livewire::test(NotificationSettings::class)->assertForbidden();
    }

    // ---- Dashboard queries -------------------------------------------------

    public function test_dashboard_only_shows_the_current_admins_inbox(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        $this->inApp($admin, ['title' => 'Mine']);
        $this->inApp($other, ['title' => 'Theirs']);

        Livewire::actingAs($admin)->test(NotificationDashboard::class)
            ->assertSee('Mine')
            ->assertDontSee('Theirs');
    }

    public function test_stats_count_total_unread_and_critical(): void
    {
        $admin = $this->admin();
        $this->inApp($admin, ['severity' => 'info', 'read_at' => now()]);
        $this->inApp($admin, ['severity' => 'warning', 'read_at' => null]);
        $this->inApp($admin, ['severity' => 'critical', 'read_at' => null]);

        Livewire::actingAs($admin)->test(NotificationDashboard::class)
            ->assertViewHas('stats', fn ($s) => $s['total'] === 3 && $s['unread'] === 2 && $s['critical'] === 1);
    }

    public function test_severity_filter_narrows_results(): void
    {
        $admin = $this->admin();
        $this->inApp($admin, ['severity' => 'critical', 'title' => 'CritEvent']);
        $this->inApp($admin, ['severity' => 'info', 'title' => 'InfoEvent']);

        Livewire::actingAs($admin)->test(NotificationDashboard::class)
            ->set('severity', NotificationSeverity::Critical->value)
            ->assertSee('CritEvent')
            ->assertDontSee('InfoEvent');
    }

    public function test_search_filters_by_message_text(): void
    {
        $admin = $this->admin();
        $this->inApp($admin, ['title' => 'Alpha alert', 'message' => 'first']);
        $this->inApp($admin, ['title' => 'Beta alert', 'message' => 'second']);

        Livewire::actingAs($admin)->test(NotificationDashboard::class)
            ->set('search', 'Alpha')
            ->assertSee('Alpha alert')
            ->assertDontSee('Beta alert');
    }

    public function test_mark_read_updates_a_single_notification(): void
    {
        $admin = $this->admin();
        $log = $this->inApp($admin, ['read_at' => null]);

        Livewire::actingAs($admin)->test(NotificationDashboard::class)->call('markRead', $log->id);

        $this->assertNotNull($log->refresh()->read_at);
    }

    public function test_mark_all_read_clears_unread(): void
    {
        $admin = $this->admin();
        $this->inApp($admin, ['read_at' => null]);
        $this->inApp($admin, ['read_at' => null]);

        Livewire::actingAs($admin)->test(NotificationDashboard::class)->call('markAllRead');

        $this->assertSame(0, NotificationLog::forRecipient($admin->id)->unread()->count());
    }

    public function test_mark_read_cannot_touch_another_users_notification(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        $log = $this->inApp($other, ['read_at' => null]);

        Livewire::actingAs($admin)->test(NotificationDashboard::class)->call('markRead', $log->id);

        $this->assertNull($log->refresh()->read_at);
    }

    // ---- Bell --------------------------------------------------------------

    public function test_bell_shows_unread_count_for_current_admin(): void
    {
        $admin = $this->admin();
        $this->inApp($admin, ['read_at' => null]);
        $this->inApp($admin, ['read_at' => null]);
        $this->inApp($admin, ['read_at' => now()]);

        Livewire::actingAs($admin)->test(NotificationBell::class)
            ->assertViewHas('unreadCount', 2);
    }

    // ---- Settings ----------------------------------------------------------

    public function test_settings_save_persists_rules_thresholds_and_preference(): void
    {
        $admin = $this->admin();

        $component = Livewire::actingAs($admin)->test(NotificationSettings::class)
            ->set('idleThreshold', 1234)
            ->set('restrictedApps', 'Steam, Discord')
            ->set('blacklistedProcesses', 'mimikatz.exe')
            ->set('longUsageMax', 4200)
            ->set('prefChannels', ['in_app'])
            ->set('prefMinSeverity', 'warning')
            ->set('prefDigest', true)
            ->call('save');

        $component->assertHasNoErrors();

        $idleRule = NotificationRule::firstWhere('event_type', NotificationEventType::PresenceIdle->value);
        $this->assertSame(1234, $idleRule->config['idle_threshold_seconds']);

        $restricted = NotificationRule::firstWhere('event_type', NotificationEventType::AppRestricted->value);
        $this->assertSame(['Steam', 'Discord'], $restricted->config['applications']);

        $blacklisted = NotificationRule::firstWhere('event_type', NotificationEventType::AppBlacklisted->value);
        $this->assertSame(['mimikatz.exe'], $blacklisted->config['processes']);

        $pref = NotificationPreference::firstWhere('user_id', $admin->id);
        $this->assertNotNull($pref);
        $this->assertSame(['in_app'], $pref->channels);
        $this->assertSame('warning', (string) $pref->min_severity);
        $this->assertTrue((bool) $pref->digest);
    }

    public function test_settings_can_disable_a_rule(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(NotificationSettings::class)
            ->set('rules.0.enabled', false)
            ->call('save');

        $firstId = NotificationRule::orderBy('event_type')->first()->id;
        $this->assertFalse((bool) NotificationRule::find($firstId)->enabled);
    }

    // ---- Security: no secret leakage --------------------------------------

    public function test_dashboard_does_not_expose_credentials_stored_in_metadata(): void
    {
        $admin = $this->admin();
        $this->inApp($admin, [
            'title' => 'Agent alert',
            'message' => 'Something happened',
            'metadata' => ['api_token' => 'super-secret-token-value', 'device_token' => 'dev-xyz'],
        ]);

        Livewire::actingAs($admin)->test(NotificationDashboard::class)
            ->assertSee('Agent alert')
            ->assertDontSee('super-secret-token-value')
            ->assertDontSee('dev-xyz');
    }
}
