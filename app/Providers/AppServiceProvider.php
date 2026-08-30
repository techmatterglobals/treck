<?php

namespace App\Providers;

use App\Contracts\CurrentOrganization;
use App\Events\PresenceChanged;
use App\Listeners\EvaluatePresenceNotifications;
use App\Models\ApplicationUsage;
use App\Models\FileDownload;
use App\Models\NotificationLog;
use App\Observers\ApplicationUsageObserver;
use App\Observers\FileDownloadObserver;
use App\Policies\NotificationPolicy;
use App\Services\Tenancy\RequestCurrentOrganization;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentOrganization::class, RequestCurrentOrganization::class);
    }

    public function boot(): void
    {
        // Notifications (Phase 9): bridge existing events/models into the engine
        // without modifying the presence (Phase 6) or app-usage (Phase 7) code.
        Event::listen(PresenceChanged::class, EvaluatePresenceNotifications::class);
        ApplicationUsage::observe(ApplicationUsageObserver::class);
        FileDownload::observe(FileDownloadObserver::class);

        // The policy name doesn't follow model-name auto-discovery, so bind it.
        Gate::policy(NotificationLog::class, NotificationPolicy::class);

        // Display-timezone directives: timestamps are stored/computed in UTC and
        // converted to treck.display_timezone only for rendering.
        //   @dt($value[, 'format'])  → absolute time in the display timezone
        //   @ago($value)             → relative "x ago" (instant-based)
        Blade::directive('dt', fn ($expr) => "<?php echo \App\Support\DisplayTime::format($expr); ?>");
        Blade::directive('ago', fn ($expr) => "<?php echo \App\Support\DisplayTime::ago($expr); ?>");

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
