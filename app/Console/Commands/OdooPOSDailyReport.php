<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\OdooPOS;

class OdooPOSDailyReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'odoo:pos-daily-report {--recipients=} {--date=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send Odoo POS Daily Report via SMS';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('OdooWoo POS Daily Report Notification Initiated - ' . date("F j, Y, g:i a"));
        $recipients = $this->option('recipients');
        $this->info('Recipients: ' . $recipients);
        $recipients = explode(',', $recipients);
        $date = $this->option('date');
        $this->info('Date: ' . date("F j, Y", strtotime($date)));
        $OdooPOS = new OdooPOS();
        $OdooPOS->getDailySalesReport($recipients, $date);
        $this->info('OdooWoo POS Daily Report Notification Completed.');
    }
}
