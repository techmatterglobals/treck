<?php

use App\Http\Controllers\Api\V1\User\ActivityController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Activity read API (dashboards / user clients)
|--------------------------------------------------------------------------
| Include from routes/api.php:
|
|   require __DIR__.'/modules/activity.php';
|
| Authenticated with a user token (auth:sanctum). Per-action authorization is
| enforced inside the controller (view attendance permission, or self).
*/

Route::middleware(['auth:sanctum', 'active', 'throttle:user'])->prefix('v1')->group(function () {
    Route::get('activity/live', [ActivityController::class, 'live'])
        ->name('activity.live');

    Route::get('activity/{employee}/summary', [ActivityController::class, 'summary'])
        ->name('activity.summary');
});
