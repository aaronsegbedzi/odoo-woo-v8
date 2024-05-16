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
        $product = Product::find(333695);
        dd($product);
        // $OdooCategory = new OdooCategory();
        // $OdooCategories = $OdooCategory->getCategories();
        // $this->info('Odoo Categories Fetched: ' . count($OdooCategories));

        // $WooCategories = new WooCategory();
        // $WooCategoriy = $WooCategories->syncCategory($OdooCategories);

        // $WooCategory = new WooCategory();
        // $WooCategories = $WooCategory->getCategories();
        // $this->info('Woo Categories Fetched: ' . count($WooCategories));

        // $Categories = array_map(function ($item1) use ($WooCategories) {
        //     $matchingItems = array_filter($WooCategories, function ($item2) use ($item1) {
        //         return $item2[1] === $item1[1];
        //     });
        //     return array_merge($item1, ...$matchingItems);
        // }, $OdooCategories);

        // foreach ($Categories as $Category) {
        //     if ($Category[2] == true) {
        //         $this->info('Cat: '. $Category[1]);
        //         $this->info('Woo ID: '. $Category[5]);
        //         $parent_id = $this->searchArray(0, $Category[4], 5, $Categories);
        //         $this->info('Woo Parent ID: '. $parent_id);
        //         $test = $WooCategory->setParentCatergory($Category[5], $parent_id);
        //         $this->info($test);
        //     }
        // }
        
    }

    private function searchArray($searchKey, $searchValue, $returnKey, $array)
    {
        $result = null;
        foreach ($array as $item) {
            if ($item[$searchKey] == $searchValue) {
                $result = $item[$returnKey];
                break;
            }
        }
        return $result;
    }
}
