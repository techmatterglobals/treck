<?php

use App\Http\Controllers\Api\Agent\ActivityController;
use App\Http\Controllers\Api\Agent\DeviceRegistrationController;
use App\Http\Controllers\Api\Agent\EnrollController;
use App\Http\Controllers\Api\Agent\EventIngestionController;
use App\Http\Controllers\Api\Agent\ScreenshotUploadController;
use App\Http\Controllers\Api\Agent\WorkSessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Desktop PC Agent API
|--------------------------------------------------------------------------
| Included from routes/api.php (which is already prefixed with /api):
|
|   require __DIR__.'/modules/agent.php';
|
| Public:
|   POST /api/agent/enroll     → one-time enrollment-code gated; creates/links a
|                                computer and mints a device token (installer flow;
|                                throttled: agent-register)
|   POST /api/agent/register   → provisioning-key gated; mints a device token
|                                (legacy; kept for existing installs)
|
| Authenticated (Bearer device token with the `agent:report` ability;
| throttled: agent):
|   POST /api/agent/login      → open a PC session
|   POST /api/activity         → report active/idle seconds
|   POST /api/agent/logout     → close the PC session
|   POST /api/agent/events     → drain one queued heartbeat/session/app-usage event (M6, Phase 7)
|   POST /api/agent/screenshots→ drain one queued screenshot (multipart; Phase 8)
*/

// Installer enrollment: computer-scoped, gated by a one-time enrollment code.
Route::post('agent/enroll', [EnrollController::class, 'store'])
    ->middleware('throttle:agent-register')
    ->name('agent.enroll');

// Legacy token bootstrap (guarded by the provisioning key inside the FormRequest).
Route::post('agent/register', [DeviceRegistrationController::class, 'store'])
    ->middleware('throttle:agent-register')
    ->name('agent.register');

Route::middleware(['auth:sanctum', 'ability:agent:report', 'throttle:agent'])->group(function () {
    Route::post('agent/login', [WorkSessionController::class, 'login'])->name('agent.login');
    Route::post('activity', [ActivityController::class, 'store'])->name('agent.activity');
    Route::post('agent/logout', [WorkSessionController::class, 'logout'])->name('agent.logout');
    Route::post('agent/events', [EventIngestionController::class, 'store'])->name('agent.events');
    Route::post('agent/screenshots', [ScreenshotUploadController::class, 'store'])->name('agent.screenshots');
});
