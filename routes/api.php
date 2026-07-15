<?php

use App\Http\Controllers\Api\V1\User\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (prefixed with /api)
|--------------------------------------------------------------------------
*/

// User token auth (Sanctum).
Route::post('v1/auth/login', [AuthController::class, 'login'])->name('api.auth.login');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('v1/auth/me', [AuthController::class, 'me'])->name('api.auth.me');
    Route::post('v1/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
});

// Desktop PC agent API (device tokens).
require __DIR__.'/modules/agent.php';

// Activity read API (user tokens).
require __DIR__.'/modules/activity.php';
