<?php

namespace App\Http\Controllers;

use Codexshaper\WooCommerce\Facades\Product;
use Codexshaper\WooCommerce\Facades\Variation;

class WooProduct extends Controller
{
    public function getProduct($id) {
        try {
            $product = Product::find($id);
            return $product;
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function getProductVariation($product_id, $id) {
        try {
            $product = Variation::find($product_id, $id);
            return $product;
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    public function getProducts()
    {
        $next_page = 1;
        $products = [];
        $payload = [];
        
        do {
            $products = Product::paginate(100, $next_page,['type' => 'simple']);
            $next_page = $products['meta']['next_page'];
            foreach ($products['data'] as $value) {
                $payload[] = $value;
            }
        } while ($next_page > 0);

        if (count($payload) > 0) {
            return $payload;
        }

        return [];
    }

    public function getVariableProducts()
    {
        $next_page = 1;
        $products = [];
        $payload = [];
        
        do {
            $products = Product::paginate(100, $next_page,['type' => 'variable']);
            $next_page = $products['meta']['next_page'];
            foreach ($products['data'] as $value) {
                $payload[] = $value;
            }
        } while ($next_page > 0);

        if (count($payload) > 0) {
            return $payload;
        }

        return [];
    }

    public function getVariations($id) {

        $next_page = 1;
        $products = [];
        $payload = [];
        
        do {
            $products = Variation::paginate($id, 100, $next_page,['type' => 'variable']);
            $next_page = $products['meta']['next_page'];
            foreach ($products['data'] as $value) {
                $payload[] = $value;
            }
        } while ($next_page > 0);

        if (count($payload) > 0) {
            return $payload;
        }

        return [];

    }
}
