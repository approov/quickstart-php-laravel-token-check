<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Defines scheduled console commands for the application.
     *
     * This quickstart does not register any scheduled tasks.
     *
     * @param  Schedule  $schedule  The scheduler instance used to register tasks.
     * @return void
     */
    protected function schedule(Schedule $schedule): void
    {
        //
    }

    /**
     * Loads Artisan command classes and console route definitions.
     *
     * @return void
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}
