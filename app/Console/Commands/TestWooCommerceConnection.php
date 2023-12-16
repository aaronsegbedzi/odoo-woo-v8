<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\WooConnection;

class TestWooCommerceConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'woo:connection';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test WooCommerce Connection is Established';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $WooConnection = new WooConnection();
        $result = $WooConnection->connection();
        $this->info($result);
    }
}
