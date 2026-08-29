<?php

use App\Http\Controllers\Api\V1\Desktop\BootstrapController;
use App\Http\Controllers\Api\V1\Desktop\AgentHealthController;
use App\Http\Controllers\Api\V1\Desktop\EmployeeDetailController;
use App\Http\Controllers\Api\V1\Desktop\OverviewController;
use App\Http\Controllers\Api\V1\Desktop\PresenceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Windows Admin Desktop API
|--------------------------------------------------------------------------
| The controllers enforce the Admin/Manager role boundary in addition to
| record-level visibility. Employee self-service tokens are not desktop-admin
| credentials even though employees may have the web `view dashboard` grant.
*/

Route::middleware(['auth:sanctum', 'active', 'throttle:user'])
    ->prefix('v1/desktop')
    ->name('desktop.')
    ->group(function () {
        Route::get('bootstrap', BootstrapController::class)->name('bootstrap');
        Route::get('agent-health', AgentHealthController::class)->name('agent-health');
        Route::get('overview', OverviewController::class)->name('overview');
        Route::get('presence', PresenceController::class)->name('presence');
        Route::get('employees/{employee}', EmployeeDetailController::class)->name('employees.show');
    });
