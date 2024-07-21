<?php

namespace App\Console\Commands;

use App\Http\Controllers\OdooPOS;
use App\Http\Controllers\WooCategory;
use App\Http\Controllers\WooProduct;
use Illuminate\Console\Command;
use Codexshaper\WooCommerce\Facades\Category;
use Codexshaper\WooCommerce\Facades\Product;
use OdooClient\Client;
use Codexshaper\WooCommerce\Facades\Variation;
use Codexshaper\WooCommerce\Facades\Attribute;
use App\Http\Controllers\WooAttribute;
use Codexshaper\WooCommerce\Facades\Term;
use Illuminate\Support\Facades\Mail;
use App\Http\Controllers\OdooProduct;
use App\Http\Controllers\OdooCategory;
use App\Http\Controllers\Controller;

class Test extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'woo:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Command';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $controller = new Controller();
        $dateTime = strtotime(date("Y-m-d h:m:s")."- 72 hour");
        $formattedDateTime = date("Y-m-d h:m:s", $dateTime);

        // Get the products from Odoo.
        $OdooProduct = new OdooProduct();
        $OdooProducts = $OdooProduct->getProducts(true, $formattedDateTime);
        $this->info('Odoo Simple Products Fetched: ' . count($OdooProducts));

        // Get the products from WooCommerce.
        $WooProduct = new WooProduct();
        $WooProducts = $WooProduct->getProducts();
        $this->info('Woo Simple Products Fetched: ' . count($WooProducts));

        $UpdateProducts = [];

        // Find products to create or update
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

        if (count($UpdateProducts) > 0) {
            $j = 0;
            $this->info('Product Update Job Initiated');
            foreach ($UpdateProducts as $UpdateProduct) {
                $BatchUpdate[$j] = [
                    'id' => $UpdateProduct['woo_id'],
                    'regular_price' => (string) $UpdateProduct['price'],
                    'manage_stock' => true,
                    'stock_quantity' => $UpdateProduct['qty'] > 0 ? $UpdateProduct['qty'] : 0,
                    'stock_status' => $UpdateProduct['qty'] > 0 ? 'instock' : 'outofstock'
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
        
    }

}
