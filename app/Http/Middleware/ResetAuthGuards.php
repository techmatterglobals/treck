<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resets resolved auth guards after each request so the next request starts from
 * a clean authentication state.
 *
 * Guards (including Sanctum's token `RequestGuard`) memoize the user they
 * resolve. Under a classic one-process-per-request runtime this is harmless —
 * the process ends after the response. But in long-lived runtimes (Laravel
 * Octane) and inside the test harness the application instance is reused across
 * requests, so a memoized user can leak into the next request. For the token
 * API that means a second request presenting a *different* device token could be
 * served as the previously resolved device.
 *
 * Clearing guards in `terminate` (after the response is sent) guarantees each
 * request re-resolves its own identity, with no effect on the request in flight.
 */
class ResetAuthGuards
{
    public function __construct(private readonly AuthManager $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->auth->forgetGuards();
    }
}
