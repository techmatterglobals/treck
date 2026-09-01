<?php

use App\Http\Controllers\Admin\ManagerController;
use App\Http\Controllers\Admin\UserRoleController;
use App\Http\Controllers\AgentEnrollmentCredentialController;
use App\Http\Controllers\ApplicationUsageController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FileDownloadController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationSettingsController;
use App\Http\Controllers\OrganizationSelectionController;
use App\Http\Controllers\PresenceController;
use App\Http\Controllers\ScreenshotController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/organizations/select', [OrganizationSelectionController::class, 'index'])->name('organizations.select');
    Route::post('/organizations/select', [OrganizationSelectionController::class, 'update'])->name('organizations.switch');

    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('organization')->name('dashboard');

    // Super-Admin-only management (role assignment + Manager Management, Phase 11).
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::patch('users/{user}/role', [UserRoleController::class, 'update'])->name('users.role');

        // Manager Management: create/promote/demote managers, assign/transfer employees.
        Route::get('managers', [ManagerController::class, 'index'])
            ->middleware(['organization', 'organization.role:owner|admin'])
            ->name('managers.index');
    });

    // Monitoring dashboards. Super Admin sees the whole organization; a Manager
    // is admitted to the same screens but every query is scoped to their team
    // (Phase 11). Scoping is enforced in the components/services/policies.
    Route::middleware(['organization', 'organization.role:owner|admin|manager'])->group(function () {
        Route::get('/presence', [PresenceController::class, 'index'])->name('presence.index');
        Route::get('/presence/computers/{computer}', [PresenceController::class, 'show'])->name('presence.show');

        // Application usage dashboard (Phase 7).
        Route::get('/application-usage', [ApplicationUsageController::class, 'index'])->name('application-usage.index');

        // Screenshot management (Phase 8).
        Route::get('/screenshots', [ScreenshotController::class, 'index'])->name('screenshots.index');
        Route::get('/screenshots/{screenshot}', [ScreenshotController::class, 'show'])->name('screenshots.show');
        Route::get('/screenshots/{screenshot}/download', [ScreenshotController::class, 'download'])->name('screenshots.download');
        // Image bytes: authorized + a short-lived signed URL; never a filesystem path.
        Route::get('/screenshots/{screenshot}/image', [ScreenshotController::class, 'image'])
            ->middleware('signed')
            ->name('screenshots.image');

        // File download monitoring (Phase 12) — metadata only.
        Route::get('/downloads', [FileDownloadController::class, 'index'])->name('downloads.index');
        Route::get('/downloads/reports', [FileDownloadController::class, 'reports'])->name('downloads.reports');
        Route::get('/downloads/reports/export', [FileDownloadController::class, 'export'])->name('downloads.export');
        Route::get('/downloads/{download}', [FileDownloadController::class, 'show'])->name('downloads.show');
    });

    // Notifications remain Super-Admin-only (the alert inbox + global settings
    // are organization-wide; per-manager notification routing is a future phase).
    Route::middleware(['organization', 'organization.role:owner|admin'])->group(function () {
        Route::get('/agent-enrollment-credentials', [AgentEnrollmentCredentialController::class, 'index'])
            ->name('agent-enrollment-credentials.index');
        Route::post('/agent-enrollment-credentials', [AgentEnrollmentCredentialController::class, 'store'])
            ->name('agent-enrollment-credentials.store');
        Route::post('/agent-enrollment-credentials/{credential}/revoke', [AgentEnrollmentCredentialController::class, 'revoke'])
            ->name('agent-enrollment-credentials.revoke');

        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/settings', [NotificationSettingsController::class, 'index'])->name('notifications.settings');
    });
});

// Feature modules (each file self-applies its own middleware group).
require __DIR__.'/modules/employees.php';
require __DIR__.'/modules/reports.php';

// Authentication (login / logout).
require __DIR__.'/auth.php';
