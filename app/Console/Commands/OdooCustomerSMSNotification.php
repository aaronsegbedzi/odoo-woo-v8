<?php

namespace App\Console\Commands;

use App\Http\Controllers\OdooPOS;
use Illuminate\Console\Command;

class OdooCustomerSMSNotification extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'odoo:customer-sms-daily {--date=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send Customer POS Daily Notifications via SMS';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('OdooWoo Customer POS Daily Notifications Initiated - ' . date("F j, Y, g:i a"));
        $date = $this->option('date');
        $this->info('Date: ' . date("F j, Y", strtotime($date)));
        $OdooPOS = new OdooPOS();
        $OdooPOS->getDailyCustomers($date);
        $this->info('OdooWoo Customer POS Daily Notifications Completed.');
    }
}
