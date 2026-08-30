<?php

use App\Http\Controllers\Api\Agent\ActivityController;
use App\Http\Controllers\Api\Agent\AgentConfigController;
use App\Http\Controllers\Api\Agent\AgentHealthController;
use App\Http\Controllers\Api\Agent\DeviceRegistrationController;
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
|   POST /api/agent/register   → provisioning-key gated; mints a device token
|                                (throttled: agent-register)
|
| Authenticated (Bearer device token with the `agent:report` ability;
| throttled: agent):
|   GET  /api/agent/config     → revisioned server-side agent policy
|   POST /api/agent/login      → open a PC session
|   POST /api/activity         → report active/idle seconds
|   POST /api/agent/logout     → close the PC session
|   POST /api/agent/events     → drain one queued heartbeat/session/app-usage event (M6, Phase 7)
|   POST /api/agent/screenshots→ drain one queued screenshot (multipart; Phase 8)
|   POST /api/agent/health     → latest operational health snapshot
*/

// Token bootstrap (guarded by the provisioning key inside the FormRequest).
Route::post('agent/register', [DeviceRegistrationController::class, 'store'])
    ->middleware('throttle:agent-register')
    ->name('agent.register');

Route::middleware(['auth:sanctum', 'ability:agent:report', 'throttle:agent'])->group(function () {
    Route::get('agent/config', AgentConfigController::class)->name('agent.config');
    Route::post('agent/login', [WorkSessionController::class, 'login'])->name('agent.login');
    Route::post('activity', [ActivityController::class, 'store'])->name('agent.activity');
    Route::post('agent/logout', [WorkSessionController::class, 'logout'])->name('agent.logout');
    Route::post('agent/events', [EventIngestionController::class, 'store'])->name('agent.events');
    Route::post('agent/screenshots', [ScreenshotUploadController::class, 'store'])->name('agent.screenshots');
    Route::post('agent/health', AgentHealthController::class)->name('agent.health');
});
