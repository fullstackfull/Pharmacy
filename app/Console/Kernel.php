<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Abandoned-cart reminder emails. This command is the only sender of them, so without a
        // schedule the retention feature ships but never actually emails anyone.
        $schedule->command('cart:remind-abandoned')->everyThirtyMinutes()->withoutOverlapping();

        // Mature vendor earnings out of the return window and roll up settlements. Order earnings are
        // recorded pending with an available_at; `--release` matures a delivered order's earning to
        // available so the seller can be settled and paid. Without this run nothing ever matures and no
        // seller can be paid through the ledger. (Requires the server cron `* * * * * php artisan
        // schedule:run` to be installed — a deployment step.)
        $schedule->command('marketplace:settle --release')->dailyAt('02:00')->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
