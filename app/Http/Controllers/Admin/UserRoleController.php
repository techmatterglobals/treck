<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin-only management of a user's role. Protected by the
 * `permission:manage users` middleware on the route.
 */
class UserRoleController extends Controller
{
    /** PATCH /admin/users/{user}/role */
    public function update(Request $request, User $user): RedirectResponse
    {
        // Defense in depth: the route middleware already enforces this, but we
        // authorize here too so the controller is safe if reused. Spatie
        // registers each permission as a Gate ability.
        $this->authorize('manage users');

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(UserRole::values())],
        ]);

        // A user holds exactly one role in this system.
        $user->syncRoles([$validated['role']]);

        return back()->with('status', "Role updated to {$validated['role']}.");
    }
}
