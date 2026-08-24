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

    /**
     * @param array|null $fields Optional whitelist of response fields
     *                           (WooCommerce `_fields`) — trims the response
     *                           size dramatically on catalogue-wide reads.
     *                           null keeps the full response for existing callers.
     */
    public function getProducts(?array $fields = null)
    {
        $next_page = 1;
        $products = [];
        $payload = [];

        $options = ['type' => 'simple'];
        if ($fields !== null) {
            $options['_fields'] = implode(',', $fields);
        }

        do {
            $products = Product::paginate(100, $next_page, $options);
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

    /**
     * Fetch only the products matching the given SKUs.
     *
     * The sync uses the Woo catalogue for exactly one purpose — looking up the
     * SKUs Odoo just returned — so asking for those SKUs is equivalent to
     * paging the whole catalogue and discarding everything else, at a fraction
     * of the requests. The REST API accepts a comma-separated `sku` list; it is
     * chunked here because a page can still only return 100 rows.
     *
     * @param array      $skus   SKUs to look up; blanks and duplicates are dropped
     * @param array|null $fields Optional `_fields` whitelist — see getProducts()
     * @return array Products, in no guaranteed order (callers index by SKU)
     */
    public function getProductsBySkus(array $skus, ?array $fields = null, int $chunkSize = 100)
    {
        $skus = array_values(array_unique(array_filter(
            array_map(fn($s) => is_string($s) ? trim($s) : '', $skus),
            fn($s) => $s !== ''
        )));

        if (empty($skus)) {
            return [];
        }

        $payload = [];

        foreach (array_chunk($skus, max(1, $chunkSize)) as $chunk) {
            $options = [
                'sku'      => implode(',', $chunk),
                'type'     => 'simple',
                'per_page' => 100,
            ];
            if ($fields !== null) {
                $options['_fields'] = implode(',', $fields);
            }

            foreach (Product::all($options) as $value) {
                $payload[] = $value;
            }
        }

        return $payload;
    }

    /**
     * Fetch the handful of products matching a SKU.
     *
     * Simple products are matched to Odoo by SKU, and the Woo REST API can
     * filter on it server-side — so a single-product sync has no reason to
     * page through the whole catalogue.
     */
    public function getProductsBySku($sku)
    {
        if ($sku === '' || $sku === null) {
            return [];
        }

        $payload = [];

        foreach (Product::all(['sku' => $sku, 'type' => 'simple', 'per_page' => 100]) as $value) {
            $payload[] = $value;
        }

        return $payload;
    }

    /**
     * @param array|null $fields Optional `_fields` whitelist — see getProducts().
     */
    public function getVariableProducts(?array $fields = null)
    {
        $next_page = 1;
        $products = [];
        $payload = [];

        $options = ['type' => 'variable'];
        if ($fields !== null) {
            $options['_fields'] = implode(',', $fields);
        }

        do {
            $products = Product::paginate(100, $next_page, $options);
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

    /**
     * @param array|null $fields Optional `_fields` whitelist — see getProducts().
     */
    public function getVariations($id, ?array $fields = null) {

        $next_page = 1;
        $products = [];
        $payload = [];

        $options = ['type' => 'variable'];
        if ($fields !== null) {
            $options['_fields'] = implode(',', $fields);
        }

        do {
            $products = Variation::paginate($id, 100, $next_page, $options);
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
