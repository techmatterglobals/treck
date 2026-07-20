<?php

use App\Http\Controllers\Admin\UserRoleController;
use App\Http\Controllers\ApplicationUsageController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PresenceController;
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
    });
});

// Feature modules (each file self-applies its own middleware group).
require __DIR__.'/modules/employees.php';
require __DIR__.'/modules/reports.php';

// Authentication (login / logout).
require __DIR__.'/auth.php';
