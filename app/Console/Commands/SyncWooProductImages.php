<?php

namespace App\Console\Commands;

use App\Http\Controllers\Controller;
use Illuminate\Console\Command;
use App\Http\Controllers\OdooProduct;
use App\Http\Controllers\WooProduct;
use Codexshaper\WooCommerce\Facades\Product;

class SyncWooProductImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'woo:sync-images';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize Product Images in WooCommerce';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('OdooWoo Synchronize Images - ' . date("F j, Y, g:i a"));
        $controller = new Controller();

        // Get the products from Odoo.
        $OdooProduct = new OdooProduct();
        $OdooProducts = $OdooProduct->getProducts();
        $this->info('Odoo Simple Products Fetched: ' . count($OdooProducts));

        // Get the products from WooCommerce.
        $WooProduct = new WooProduct();
        $WooProducts = $WooProduct->getProducts();
        $this->info('Woo Simple Products Fetched: ' . count($WooProducts));

        $UpdateProducts = [];

        foreach ($OdooProducts as $OdooProduct) {
            foreach ($WooProducts as $WooProduct) {
                if ($OdooProduct['sku'] == $WooProduct->sku) {
                    $OdooProduct['woo_id'] = $WooProduct->id;
                    $UpdateProducts[] = $OdooProduct;
                    break;
                }
            }
        }

        $this->info('No. Products To Update: ' . count($UpdateProducts));

        $BatchUpdate = [];

        if (count($UpdateProducts) > 0) {
            $j = 0;
            $this->info('Product Update Job Initiated');
            foreach ($UpdateProducts as $UpdateProduct) {
                $BatchUpdate[$j] = [
                    'id' => $UpdateProduct['woo_id'],
                    'images' => [
                        [
                            'src' =>  $UpdateProduct['image']
                        ]
                    ]
                ];
                $j++;
            }
            $batchSize = $controller->wooProductsPerBatch();
            $i = 1;
            $chunks = array_chunk($BatchUpdate, $batchSize);
            foreach ($chunks as $chunk) {
                try {
                    $this->info('Batch ' . $i . ': ' . date("F j, Y, g:i a"));
                    $_batch = Product::batch(['update' => $chunk]);
                    $this->info('COMPLETED Batch ' . $i . ' @ ' . date("F j, Y, g:i a"));
                } catch (\Exception $e) {
                    $this->info('FAILED Batch ' . $i . ' - REASON: ' . $e->getMessage());
                }
                $i++;
                sleep($controller->wooSleepSeconds());
            }
            $this->info('Product Update Job Completed');
        }

        $this->info('OdooWoo Synchronization Completed. Have Fun :)');
    }
}
