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
            // Global, not group-scoped: a request that matches no route never enters the web or api
            // group, so every 404 — a broken link, a scanner sweeping the site — was invisible to
            // monitoring, and the __unmatched__ series the recorder documents could not be reached.
            // Out here it also measures the whole pipeline rather than the part inside the group.
            // It binds a context on first pass and skips on the second, so the group entries below
            // cost a container lookup and record nothing twice.
            \App\Http\Middleware\MonitorRequest::class,
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
            // A previewed draft is a real storefront response with an unpublished page in it. This
            // keeps that page out of search results and shared caches for as long as the token
            // that summoned it is alive.
            \App\Http\Middleware\NoIndexThemePreview::class,
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
            // What each endpoint answers with, learned from what it answers with. The API returns
            // JSON directly rather than through Resource classes, so there is no type to reflect —
            // this is the only way the portal can describe a response without somebody typing it.
            \App\Http\Middleware\RecordApiResponseShape::class,
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
            // Puts the web session's seller principal on the request, so the API's permission gate
            // and audit actor serve the panel too rather than a second authorization system.
            'seller_center' => \App\Http\Middleware\SellerCenterContext::class,
            'customer' => CustomerMiddleware::class,
            'module' => ModulePermissionMiddleware::class,
            'installation-check' => InstallationMiddleware::class,
            'actch' => ActivationCheckMiddleware::class,
            'api_lang' => APILocalizationMiddleware::class,
            'maintenance_mode' => MaintenanceModeMiddleware::class,
            'delivery_man_auth' => DeliveryManAuth::class,
            'seller_api_auth' => SellerApiAuthMiddleware::class,
            // Enforced on the route, never by hiding a menu item.
            'seller_can' => \App\Http\Middleware\EnsureSellerPermission::class,
            'seller_owner' => \App\Http\Middleware\EnsureSellerIsOwner::class,
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

        // Recompute what each seller should be looking at. Hourly rather than daily because the
        // things it raises — stock about to run out, an order approaching its deadline — stop being
        // useful the moment they are stale.
        $schedule->command('seller:refresh-insights')->hourly()->withoutOverlapping();

        // Publish the theme versions a merchant scheduled. Five minutes is the resolution the
        // builder promises, and matches the heartbeat that tells the dashboard the cron is alive —
        // a scheduled publish is only as trustworthy as the run that fires it.
        $schedule->command('theme:publish-due')->everyFiveMinutes()->withoutOverlapping();

        // Promote issues nobody has answered. Every four hours rather than hourly: escalation asks
        // how long something has stood, and that answer changes slowly. Running it as often as
        // detection would spend a sweep to learn nothing almost every time.
        $schedule->command('seller:escalate-issues')->everyFourHours()->withoutOverlapping();

        // Seller rules. Every fifteen minutes is the floor a rule's own cooldown is measured
        // against — a rule set to run hourly still runs hourly; this only decides how often the
        // sweep asks. Non-overlapping, because two sweeps evaluating the same rule would each see
        // the pre-run state and could both act on the same listing.
        $schedule->command('seller:run-automation')->everyFifteenMinutes()->withoutOverlapping();

        // Webhook retries. The schedule lives on the delivery row rather than in the queue's own
        // delayed jobs, so that a seller can read "next attempt in eight minutes" on their screen —
        // and so nothing is lost to a worker restart. This sweep is what turns the schedule into
        // work.
        $schedule->command('seller:retry-webhooks')->everyFiveMinutes()->withoutOverlapping();

        // Bulk price and stock changes are queued, which makes them depend on a worker running. A
        // deployment without one would leave every bulk job at `queued` for ever while the app shows
        // the seller a change that is never going to happen — the exact failure the receipt exists to
        // prevent. Where a worker does exist this sweep finds nothing.
        $schedule->command('seller:run-stuck-bulk-jobs')->everyMinute()->withoutOverlapping();

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

        /*
        | Analytics.
        |
        | Hourly so today's charts are never more than an hour behind, and a nightly pass that
        | rebuilds yesterday (late events, an order paid after midnight) and prunes raw rows past
        | their retention. Rollups are a rebuild of the day, so running them repeatedly is safe.
        */
        $schedule->command('analytics:rollup')->hourlyAt(12)->withoutOverlapping();
        $schedule->command('analytics:rollup --days=2 --prune')->dailyAt('02:15')->withoutOverlapping();

        // Rebuild the per-product engagement summary dynamic collections rank by. After the
        // rollup on purpose: views_30d and carted_30d read analytics_daily, so running first
        // would rank against yesterday twice.
        $schedule->command('commerce:metrics-refresh')->hourlyAt(22)->withoutOverlapping();

        // Campaign lifecycle: the serve path already obeys the window, so this only tidies
        // statuses and flushes caches at the exact transition (§32–33).
        $schedule->command('commerce:campaigns-tick')->everyFiveMinutes()->withoutOverlapping();

        // Compress raw request telemetry into daily rollups for Analytics; the
        // nightly run also prunes raw rows past the retention window.
        $schedule->command('telemetry:rollup')->hourlyAt(7)->withoutOverlapping();
        // Yesterday, once it is over. The hourly run only ever covers the current day, so its last
        // pass at 23:07 left 23:07 to midnight in no daily row at all — fifty-three minutes of
        // every day missing, permanently, once the raw rows aged out.
        $schedule->command('telemetry:rollup --date=yesterday')->dailyAt('00:20')->withoutOverlapping();
        $schedule->command('telemetry:rollup --prune')->dailyAt('01:30')->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // You can customize exception handling here if needed
    })
    ->create();
