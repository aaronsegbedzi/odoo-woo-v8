<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\Controller;
use App\Http\Controllers\OdooProduct;
use App\Http\Controllers\WooProduct;
use Codexshaper\WooCommerce\Facades\Product;
use Codexshaper\WooCommerce\Facades\Variation;
use App\Http\Controllers\WooAttribute;

class SyncWooProductVariantImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'woo:sync-variant-images';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize Products in WooCommerce';

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
        $this->info('OdooWoo Variable Products Synchronization Job - ' . date("F j, Y, g:i a"));
        $controller = new Controller();

        // Get the products from Odoo.
        $OdooProduct = new OdooProduct();
        $OdooProducts = $OdooProduct->getVariableProducts();
        $this->info('Odoo Variable Products Fetched: ' . count($OdooProducts));

        // Get the products from WooCommerce.
        $WooProduct = new WooProduct();
        $WooProducts = $WooProduct->getVariableProducts();
        $this->info('Woo Variable Products Fetched: ' . count($WooProducts));

        //ATTRIBUTES//////////////////////////////////////////////////////////////////////////////////////////////////
        // Get the attributes from Odoo.
        $OdooAttributes = [];
        foreach ($OdooProducts as $OdooProduct) {
            foreach ($OdooProduct['variants'] as $value) {
                $OdooAttributes[] = array($value['att_name']);
            }
        }
        $OdooAttributes = array_values(array_map("unserialize", array_unique(array_map("serialize", $OdooAttributes))));
        $this->info('Odoo Attributes Fetched: ' . count($OdooAttributes));

        // Get the attributes from WooCommerce.
        $WooAttribute = new WooAttribute();
        $WooAttributes = $WooAttribute->getAttributes();
        $this->info('Woo Attributes Fetched: ' . count($WooAttributes));

        // Merge Odoo and WooCommerce attributes.
        $Attributes = $WooAttributes;
        //ATTRIBUTES//////////////////////////////////////////////////////////////////////////////////////////////////

        $UpdateProducts = [];

        foreach ($OdooProducts as $OdooProduct) {
            foreach ($WooProducts as $WooProduct) {
                if ($OdooProduct['id'] == $this->getMetaValue($WooProduct->meta_data)) {
                    $OdooProduct['woo_id'] = $WooProduct->id;
                    $UpdateProducts[] = $OdooProduct;
                    break;
                }
            }
        }

        $this->info('No. Product Variables To Update: ' . count($UpdateProducts));

        if (count($UpdateProducts) > 0) {
            $total = count($UpdateProducts);
            $i = 1;
            $this->info('Product Update Job Initiated');
            foreach ($UpdateProducts as $Product) {

                $searchValue = $Product['variants'][0]['att_name'];
                $index1 = null;
                foreach ($Attributes as $key => $element) {
                    if ($element[1] === $searchValue) {
                        $index1 = $key;
                        break;
                    }
                }

                $data = [
                    'name' => $Product['name'],
                    'images' => [
                        [
                            'src' => $Product['image']
                        ],
                    ]
                ];

                try {
                    $product = Product::update($Product['woo_id'], $data);
                } catch (\Exception $e) {
                    $this->info('FAILED to UPDATE: ' . $Product['name'] . ' REASON: ' . $e->getMessage());
                    break;
                }

                sleep($controller->wooSleepSeconds());

                $WooProduct = new WooProduct();
                $WooVariations = $WooProduct->getVariations($Product['woo_id']);

                // Compare data sets for create/update with SKU.
                $UpdateVariants = [];

                foreach ($Product['variants'] as $OdooVariant) {
                    foreach ($WooVariations as $WooVariant) {
                        if ($OdooVariant['id'] == $this->getMetaValue($WooVariant->meta_data)) {
                            $OdooVariant['woo_id'] = $WooVariant->id;
                            $UpdateVariants[] = $OdooVariant;
                        }
                    }
                }

                $BatchUpdateVariants = [];

                if (count($UpdateVariants) > 0) {
                    $j = 0;
                    foreach ($UpdateVariants as $Variant) {
                        $BatchUpdateVariants[$j] = [
                            'id' => $Variant['woo_id'],
                            'image' => [
                                'src' => $Variant['image']
                            ]
                        ];
                        $j++;
                    }
                }

                try {
                    $_batch = Variation::batch($Product['woo_id'], ['update' => $BatchUpdateVariants]);
                } catch (\Exception $e) {
                    $this->info('Failed to UPDATE VARIANT: ' . $Product['name'] . ' REASON: ' . $e->getMessage());
                    break;
                }

                sleep($controller->wooSleepSeconds());

                $this->info('Updated Product ' . $i . '/' . $total);

                $i++;
            }

            $this->info('Product Update Job Completed');
        }

        $this->info('OdooWoo Synchronization Completed. Have Fun :)');
    }

    private function getMetaValue($array)
    {
        $id = null;
        if (isset($array) && !empty($array)) {
            foreach ($array as $value) {
                if ($value->key == 'odoo_woo_id') {
                    $id = $value->value;
                }
            }
        }
        return $id;
    }
}
