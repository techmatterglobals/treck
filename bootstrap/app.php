<?php

use App\Http\Middleware\EnsureCurrentOrganization;
use App\Http\Middleware\EnsureOrganizationRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ResetAuthGuards;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
    // Presence broadcasting (M7): registers /broadcasting/auth (web + auth)
    // and the admin-only private channels in routes/channels.php.
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

        // Route-level middleware aliases used by auth/authz layer.
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'active' => EnsureUserIsActive::class,
            'organization' => EnsureCurrentOrganization::class,
            'organization.role' => EnsureOrganizationRole::class,

            // Sanctum token ability gates.
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // API requests must always get JSON errors, never an HTML redirect.
        // Without this, a failed FormRequest/authorization on an /api/* route
        // that did not send `Accept: application/json` falls back to Laravel's
        // web behavior: a 302 back to the previous URL (the site root), which
        // chains root -> dashboard -> /login. A client following redirects then
        // receives the login HTML with a 200 instead of the real 422/403/401.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson(),
        );
    })
    ->create();
