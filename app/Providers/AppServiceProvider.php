<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Login: strict, per email + IP to reduce credential stuffing.
        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(5)->by(
                    Str::lower((string) $request->input('email')).'|'.$request->ip()
                ),
            ];
        });

        // Device registration: strict, per IP.
        RateLimiter::for('agent-register', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Agent telemetry.
        RateLimiter::for('agent', function (Request $request) {
            return Limit::perMinute(120)->by(
                $request->user()?->getAuthIdentifier()
                    ? 'device:'.$request->user()->getAuthIdentifier()
                    : 'ip:'.$request->ip()
            );
        });

        // Authenticated user API.
        RateLimiter::for('user', function (Request $request) {
            return Limit::perMinute(60)->by(
                $request->user()?->getAuthIdentifier()
                    ? 'user:'.$request->user()->getAuthIdentifier()
                    : 'ip:'.$request->ip()
            );
        });
    }
}