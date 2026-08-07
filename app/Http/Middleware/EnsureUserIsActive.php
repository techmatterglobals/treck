<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks users whose account has been deactivated (`is_active = false`),
 * even if they still hold a valid session or API token. Register with the
 * alias `active` (see bootstrap/app.php).
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            // Token clients get a clean 403.
            if ($request->expectsJson()) {
                abort(Response::HTTP_FORBIDDEN, 'Your account has been deactivated.');
            }

            // Session clients are logged out and bounced to login.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Your account has been deactivated.']);
        }

        return $next($request);
    }
}
