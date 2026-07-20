<?php

use App\Http\Controllers\Admin\UserRoleController;
use App\Http\Controllers\ApplicationUsageController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PresenceController;
use App\Http\Controllers\ScreenshotController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Admin-only management (role assignment).
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::patch('users/{user}/role', [UserRoleController::class, 'update'])->name('users.role');
    });

    // Admin-only real-time presence dashboard (M7).
    Route::middleware('role:admin')->group(function () {
        Route::get('/presence', [PresenceController::class, 'index'])->name('presence.index');
        Route::get('/presence/computers/{computer}', [PresenceController::class, 'show'])->name('presence.show');

        // Admin-only application usage dashboard (Phase 7).
        Route::get('/application-usage', [ApplicationUsageController::class, 'index'])->name('application-usage.index');

        // Admin-only screenshot management (Phase 8).
        Route::get('/screenshots', [ScreenshotController::class, 'index'])->name('screenshots.index');
        Route::get('/screenshots/{screenshot}', [ScreenshotController::class, 'show'])->name('screenshots.show');
        Route::get('/screenshots/{screenshot}/download', [ScreenshotController::class, 'download'])->name('screenshots.download');
        // Image bytes: admin + a short-lived signed URL; never a filesystem path.
        Route::get('/screenshots/{screenshot}/image', [ScreenshotController::class, 'image'])
            ->middleware('signed')
            ->name('screenshots.image');
    });
});

// Feature modules (each file self-applies its own middleware group).
require __DIR__.'/modules/employees.php';
require __DIR__.'/modules/reports.php';

// Authentication (login / logout).
require __DIR__.'/auth.php';
