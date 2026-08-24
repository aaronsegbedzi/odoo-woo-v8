<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Get the timezone that should be used by default for scheduled events.
     */
    protected function scheduleTimezone()
    {
        return config('app.timezone');
    }


    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule)
    {
        // Scheduler heartbeat. Overwrites one file in storage/app; nothing is
        // logged per tick. Read it back with `php artisan woo:cron --check`,
        // which is the half that can fail — see deploy/SCHEDULER.md.
        //
        // Inline, not runInBackground(): forking a PHP process a minute to
        // write four fields costs more than the check itself.
        if (config('app.odoowoo_heartbeat')) {
            $schedule->command('woo:cron')->everyMinute();
        }

        // One entry, not two. Two entries on the same expression start at the
        // same instant and run in parallel — withoutOverlapping() keys its mutex
        // on sha1($expression . $command), so it only ever blocks a command
        // against itself, never against the other one. woo:sync-all chains the
        // two legs in a single process under a single lock; the per-leg
        // ODOOWOO_SYNC_*_ENABLED flags are honoured inside that command.
        //
        // The 360-minute expiry is not a typo for the 30-minute interval: it is
        // the point at which a run is presumed hung and its lock force-released.
        // Setting it near the interval re-introduces the concurrent-copy bug,
        // because an over-running job drops its lock exactly as a tick fires.
        //
        // A skipped tick loses nothing — each command syncs from its last
        // successful run (App\Services\SyncWatermark), not a fixed window, so
        // the next run widens to cover whatever the skipped ones missed.
        $schedule->command('woo:sync-all')
            ->everyThirtyMinutes()
            ->withoutOverlapping(360)
            ->runInBackground();
            // ->emailOutputTo(config('app.odoowoo_admin_email'));

        // if (config('app.odoowoo_pos_sms')) {
        //     $schedule->command('odoo:pos-daily-report --recipients=' . config('app.odoowoo_pos_sms_recipients') . ' --date=' . date("Y-m-d"))
        //         ->dailyAt(config('app.odoowoo_pos_sms_time'))
        //         ->runInBackground();
        //         // ->emailOutputOnFailure(config('app.odoowoo_admin_email'));
        // }

        // No --date argument: the command defaults to yesterday itself. Passing
        // a pre-rendered string baked the date in at schedule-registration time
        // and left an omitted option comparing Odoo dates against " 00:00:00".
        //
        // withoutOverlapping() matters more here than on the sync jobs: this one
        // sends messages that cost money and cannot be recalled, and it loops
        // once per customer. The 120-minute expiry is the point at which a run
        // is presumed hung, not the interval — see the note on woo:sync-all.
        if (config('app.odoowoo_customer_sms')) {
            $schedule->command('odoo:customer-sms-daily')
                ->dailyAt(config('app.odoowoo_customer_sms_time'))
                ->withoutOverlapping(120)
                ->runInBackground();
                // ->emailOutputOnFailure(config('app.odoowoo_admin_email'));
        }
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
