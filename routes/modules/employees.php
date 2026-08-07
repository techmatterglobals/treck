<?php

use App\Http\Controllers\EmployeeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Employee Management routes
|--------------------------------------------------------------------------
| Include from routes/web.php inside the authenticated group:
|
|   Route::middleware(['auth', 'verified', 'active'])->group(function () {
|       require __DIR__.'/modules/employees.php';
|   });
|
| Per-action authorization is handled by EmployeePolicy via
| authorizeResource() in the controller. Add 'permission:manage employees'
| here too if you prefer gating at the route layer.
*/

// Authenticated + active users only; per-action authorization is handled by
// EmployeePolicy via the controller's HasMiddleware `can:` gates.
Route::middleware(['auth', 'active'])->group(function () {
    Route::resource('employees', EmployeeController::class);

    Route::post('employees/{employee}/computers', [EmployeeController::class, 'assignComputer'])
        ->name('employees.computers.assign');

    Route::delete('employees/{employee}/computers/{computer}', [EmployeeController::class, 'unassignComputer'])
        ->name('employees.computers.unassign');
});
