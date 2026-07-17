<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ResetAuthGuards;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Presence broadcasting (M7): registers /broadcasting/auth (web + auth) and
    // the admin-only private channels in routes/channels.php.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Reset resolved auth guards after each API request so token identity
        // never leaks between requests under Octane / the test harness.
        $middleware->api(append: [
            ResetAuthGuards::class,
        ]);

        // Route-level middleware aliases used by the auth/authz layer.
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'active' => EnsureUserIsActive::class,
            // Sanctum token-ability gates (used by the agent API).
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        // --- Named rate limiters (SEC-2) ---------------------------------
        // Applied via `throttle:<name>` on routes.

        // Login: strict, per email + IP to blunt credential stuffing.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()),
        ]);

        // Device registration: strict, per IP (protects the provisioning key).
        RateLimiter::for('agent-register', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Agent telemetry (login/activity/logout): generous, per device token.
        RateLimiter::for('agent', fn (Request $request) => Limit::perMinute(120)->by(
            $request->user()?->getAuthIdentifier() ? 'device:'.$request->user()->getAuthIdentifier() : 'ip:'.$request->ip()
        ));

        // Authenticated user API: per user (falls back to IP pre-auth).
        RateLimiter::for('user', fn (Request $request) => Limit::perMinute(60)->by(
            $request->user()?->getAuthIdentifier() ? 'user:'.$request->user()->getAuthIdentifier() : 'ip:'.$request->ip()
        ));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
