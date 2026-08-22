<?php

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\APIGuestMiddleware;
use App\Http\Middleware\APILocalizationMiddleware;
use App\Http\Middleware\CustomerMiddleware;
use App\Http\Middleware\DatabaseRefreshMiddleware;
use App\Http\Middleware\DeliveryManAuth;
use App\Http\Middleware\GuestMiddleware;
use App\Http\Middleware\InstallationMiddleware;
use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Http\Middleware\ModulePermissionMiddleware;
use App\Http\Middleware\SellerApiAuthMiddleware;
use App\Http\Middleware\SellerMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\CheckForMaintenanceMode;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Routing\Middleware\SubstituteBindings;

/*
| Asset-path mode for CLI / queue context.
|
| The web entry scripts (public/index.php, index.php) define DOMAIN_POINTED_DIRECTORY
| per deployment *before* requiring this file. Console processes (queue:work,
| schedule:run) never load those entry scripts, so the constant was undefined in
| CLI — and any asset-URL generation off the queue (e.g. a queued notification
| building a product thumbnail URL via dynamicStorage()) fatally errored. Define
| the conventional default here when a web entry hasn't already set it, so CLI
| matches web. Deployments that point the domain at the project root can override
| this by exporting DOMAIN_POINTED_DIRECTORY in the worker environment.
*/
if (!defined('DOMAIN_POINTED_DIRECTORY')) {
    define('DOMAIN_POINTED_DIRECTORY', env('DOMAIN_POINTED_DIRECTORY', 'public'));
}

return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->use([
            TrustProxies::class,
            CheckForMaintenanceMode::class,
            ValidatePostSize::class,
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
            DatabaseRefreshMiddleware::class,
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
        $middleware->group('web', [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\Localization::class,
            \App\Http\Middleware\DetectMobile::class,
            \App\Http\Middleware\ApplySeoRedirects::class,
            \App\Http\Middleware\RecordHttpTelemetry::class,
            // Operational monitoring. Separate from RecordHttpTelemetry on purpose: that one keeps
            // a row per request for visits and sources, this one only ever increments counters in
            // the current minute and never writes a row on the request path.
            \App\Http\Middleware\MonitorRequest::class,
            // Behavioural analytics. The request log above knows how much traffic there was and
            // how fast it was served; this knows what a person did, on which visit, and whether it
            // led to a sale. Both writes happen after the response has been sent.
            \App\Http\Middleware\RecordAnalytics::class,
        ]);
        $middleware->group('api', [
            'throttle:3000,1',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\RecordHttpTelemetry::class,
            \App\Http\Middleware\MonitorRequest::class,
            \App\Http\Middleware\RecordAnalytics::class,
        ]);
        /*
        |--------------------------------------------------------------------------
        | Route Middleware (Aliases)
        |--------------------------------------------------------------------------
        */
        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
            'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
            'bindings' => SubstituteBindings::class,
            'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
            'can' => \Illuminate\Auth\Middleware\Authorize::class,
            'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
            'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
            'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
            'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
            'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            'admin' => AdminMiddleware::class,
            'seller' => SellerMiddleware::class,
            'seller_staff_access' => \App\Http\Middleware\SellerStaffAccessMiddleware::class,
            'customer' => CustomerMiddleware::class,
            'module' => ModulePermissionMiddleware::class,
            'installation-check' => InstallationMiddleware::class,
            'actch' => ActivationCheckMiddleware::class,
            'api_lang' => APILocalizationMiddleware::class,
            'maintenance_mode' => MaintenanceModeMiddleware::class,
            'delivery_man_auth' => DeliveryManAuth::class,
            'seller_api_auth' => SellerApiAuthMiddleware::class,
            'deliverysyria_auth' => \App\Http\Middleware\DeliverySyriaWebhookAuthMiddleware::class,
            'guestCheck' => GuestMiddleware::class,
            'apiGuestCheck' => APIGuestMiddleware::class,
            'logUserBrowsingNavigation' => \App\Http\Middleware\LogUserBrowsingNavigationMiddleware::class,
            'detectMobile' => \App\Http\Middleware\DetectMobile::class,
        ]);
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        // Laravel 12 reads the schedule here, not from app/Console/Kernel.php.

        // Abandoned-cart reminder emails. This command is the only sender of them, so without a
        // schedule the retention feature ships but never actually emails anyone.
        $schedule->command('cart:remind-abandoned')->everyThirtyMinutes()->withoutOverlapping();

        // Mature vendor earnings out of the return window and roll up settlements. Order earnings are
        // recorded pending with an available_at; `--release` matures a delivered order's earning to
        // available so the seller can be settled and paid. Without this run nothing ever matures and no
        // seller can be paid through the ledger. (Requires the server cron `* * * * * php artisan
        // schedule:run` to be installed — a deployment step.)
        $schedule->command('marketplace:settle --release')->dailyAt('02:00')->withoutOverlapping();

        // Second-touch abandoned-cart reminder. The command supports staged drips but only stage 1 was
        // scheduled, so a second reminder never sent. Runs daily; still gated by the admin toggle.
        $schedule->command('cart:remind-abandoned --stage=2')->dailyAt('10:00')->withoutOverlapping();

        // Evaluate seller SLA thresholds into the breach ledger. Previously only the admin button ran
        // this, so breaches went stale between manual clicks.
        $schedule->command('marketplace:evaluate-sla')->dailyAt('03:00')->withoutOverlapping();

        // Reconcile the storefront search index against the catalogue in case a bulk import bypassed the
        // model observer that keeps it fresh in realtime.
        $schedule->command('search:reindex-products')->weekly()->sundays()->at('04:00')->withoutOverlapping();

        // Heartbeat: record the last scheduler run in the DB (not cache, which optimize:clear wipes) so
        // the admin dashboard can warn when the server cron `schedule:run` has stopped firing — the
        // failure mode where settlements silently never mature.
        $schedule->call(function () {
            \App\Models\BusinessSetting::updateOrCreate(
                ['type' => 'scheduler_last_run_at'],
                ['value' => now()->toDateTimeString()]
            );
        })->everyFiveMinutes()->name('scheduler-heartbeat')->withoutOverlapping();

        /*
        | Monitoring.
        |
        | The one-minute flush is the collection heartbeat: it drains the request counters that web
        | requests have been incrementing in Redis and takes a reading of every gauge. If it stops,
        | the dashboard reports that monitoring itself has gone blind rather than showing the last
        | numbers it saw as if they were current.
        */
        $schedule->command('monitoring:flush')->everyMinute()->withoutOverlapping()->runInBackground();
        // Health and synthetic checks: probes of the things a request does not touch.
        $schedule->command('monitoring:check')->everyFiveMinutes()->withoutOverlapping();
        // Evaluate alert rules against the series the flush just wrote.
        $schedule->command('monitoring:evaluate')->everyMinute()->withoutOverlapping();
        // Minutes into hours into days, and prune past each resolution's retention.
        $schedule->command('monitoring:rollup')->hourlyAt(3)->withoutOverlapping();
        $schedule->command('monitoring:rollup --prune')->dailyAt('01:45')->withoutOverlapping();

        // Compress raw request telemetry into daily rollups for Analytics; the
        // nightly run also prunes raw rows past the retention window.
        $schedule->command('telemetry:rollup')->hourlyAt(7)->withoutOverlapping();
        $schedule->command('telemetry:rollup --prune')->dailyAt('01:30')->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // You can customize exception handling here if needed
    })
    ->create();
