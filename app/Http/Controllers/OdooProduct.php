<?php

namespace App\Http\Controllers;

use OdooClient\Client;

class OdooProduct extends Controller
{
    protected $client;

    public function __construct()
    {
        $url = config('app.odoo_url');
        $database = config('app.odoo_db', '');
        $user = config('app.odoo_username');
        $password = config('app.odoo_password');
        $this->client = new Client($url, $database, $user, $password);
    }

    public function getProducts($limit = 2000)
    {

        sleep($this->odooSleepSeconds());

        $fields = array(
            'id',
            'default_code',
            'name',
            'list_price',
            'x_brand',
            'qty_available',
            'categ_id',
            'has_configurable_attributes',
            'product_variant_ids',
            'x_ingredients',
            'x_directions',
            'description_sale',
            'write_date',
            'x_image_last_updated_on',
            'barcode'
        );

        $criteria = array(
            array('x_brand', '!=', false),
            array('image_1920', '!=', false),
            array('default_code', '!=', ''),
            array('available_in_pos', '=', true),
            array('has_configurable_attributes', '=', false)
        );

        try {
            $products = $this->client->search_read('product.template', $criteria, $fields, $limit);
        } catch (\Throwable $th) {
            throw $th;
        }

        if (!empty($products)) {
            foreach ($products as $product) {
                $payload[] = array(
                    'id' => $product['id'],
                    'x_image_last_updated_on' => $product['x_image_last_updated_on'],
                    'name' => $product['name'],
                    'sku' => $product['default_code'],
                    'price' => $product['list_price'],
                    'brand' => $product['x_brand'] == true ? trim($product['x_brand']) : 'None',
                    'qty' => $product['qty_available'],
                    'cat' => $product['categ_id'],
                    'image' => env('ODOO_IMG_URL', '') . '/' . $product['id'] . '.jpg',
                    'description' => $product['description_sale'] == true ? $product['description_sale'] : '',
                    'directions' => $product['x_directions'] == true ? $product['x_directions'] : '',
                    'ingredients' => $product['x_ingredients'] == true ? $product['x_ingredients'] : '',
                    'is_variable' => $product['has_configurable_attributes'],
                    'gtin' => $product['barcode']
                );
            }
            if (count($payload) > 0) {
                return $payload;
            }
        }

        exit();
    }

    public function getVariableProducts($limit = 2000)
    {

        $fields = array(
            'id',
            'name',
            'list_price',
            'x_brand',
            'categ_id',
            'has_configurable_attributes',
            'product_variant_ids',
            'x_ingredients',
            'x_directions',
            'description_sale',
            'x_image_last_updated_on'
        );

        $criteria = array(
            array('priority', '=', 1),
            array('x_brand', '!=', false),
            array('image_1920', '!=', false),
            array('available_in_pos', '=', true),
            array('has_configurable_attributes', '=', true)
        );

        try {
            $products = $this->client->search_read('product.template', $criteria, $fields, $limit);
        } catch (\Throwable $th) {
            throw $th;
        }

        if (!empty($products)) {
            foreach ($products as $product) {
                $payload[] = array(
                    'id' => $product['id'],
                    'name' => $product['name'],
                    'brand' => $product['x_brand'] == true ? trim($product['x_brand']) : 'None',
                    'cat' => $product['categ_id'],
                    'image' => env('ODOO_IMG_URL', '') . '/' . $product['id'] . '.jpg',
                    'description' => $product['description_sale'] == true ? $product['description_sale'] : '',
                    'directions' => $product['x_directions'] == true ? $product['x_directions'] : '',
                    'ingredients' => $product['x_ingredients'] == true ? $product['x_ingredients'] : '',
                    'variants' => $this->getProductVariants($product['id']),
                    'x_image_last_updated_on' => $product['x_image_last_updated_on']
                );
            }
            if (count($payload) > 0) {
                return $payload;
            }
        }

        exit();
    }

    private function getProductVariants($id)
    {
        sleep($this->odooSleepSeconds());
        $payload = [];
        $fields = array('id', 'product_template_variant_value_ids', 'qty_available', 'list_price', 'pricelist_item_count', 'default_code', 'x_image_last_updated_on', 'barcode');
        $criteria = array(array('product_tmpl_id', '=', $id));
        try {
            $products = $this->client->search_read('product.product', $criteria, $fields);
        } catch (\Throwable $th) {
            throw $th;
        }
        foreach ($products as $product) {
            $variant = $this->getVariantAttribute($product['product_template_variant_value_ids']);
            $payload[] = array(
                'id' => $product['id'],
                'sku' => $product['default_code'],
                'image' => env('ODOO_IMG_VARIANT_URL', '') . '/' . $product['id'] . '.jpg',
                'qty' => $product['qty_available'],
                'price' => $product['pricelist_item_count'] > 0 ? $this->getVariantCustomPrice($product['id'], $product['list_price']) : $product['list_price'],
                'att_name' => $variant['name'],
                'att_value' => $variant['value'],
                'x_image_last_updated_on' => $product['x_image_last_updated_on'],
                'gtin' => $product['barcode']
            );
        }
        return $payload;
    }

    private function getVariantAttribute($id)
    {
        sleep($this->odooSleepSeconds());
        $payload = [];
        $fields = array('id', 'name', 'attribute_line_id');
        $criteria = array(array('id', '=', $id));
        try {
            $products = $this->client->search_read('product.template.attribute.value', $criteria, $fields);
        } catch (\Throwable $th) {
            throw $th;
        }
        $payload = array(
            'name' => $products[0]['attribute_line_id'][1],
            'value' => $products[0]['name'],
        );
        return $payload;
    }

    private function getVariantCustomPrice($id, $list_price)
    {
        sleep($this->odooSleepSeconds());
        $fields = array('id', 'fixed_price');
        $criteria = array(array('product_id', '=', $id), array('pricelist_id', '=', config('app.odoowoo_pricelist')));
        try {
            $products = $this->client->search_read('product.pricelist.item', $criteria, $fields);
            if (!empty($products)) {
                return $products[0]['fixed_price'];
            }
            return $list_price;
        } catch (\Throwable $th) {
            throw $th;
        }
    }
}
