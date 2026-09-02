<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDesktopUserToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof User) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'code' => 'unauthenticated',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
