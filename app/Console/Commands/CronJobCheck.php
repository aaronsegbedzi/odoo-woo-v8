<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CronJobCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'woo:cron';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check if Cron Job is Working';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Log::info('Cron Job Executed');
    }
}
