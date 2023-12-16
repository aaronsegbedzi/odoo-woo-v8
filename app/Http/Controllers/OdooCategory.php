<?php

namespace App\Http\Controllers;

use OdooClient\Client;

class OdooCategory extends Controller
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

    public function getCategories()
    {

        $fields = array(
            'id',
            'name',
            'complete_name',
            'parent_id',
            'child_id',
            'product_count'
        );

        $criteria = array(
            array('product_count', '>', 0),
        );

        try {
            $categories = $this->client->search_read('product.category', null, null, 100);
            foreach ($categories as $key => $category) {
                if (!in_array($category['name'], array('PoS', 'All', 'Expenses', 'Saleable'))) {
                    $payload[] = array(
                        $category['id'],
                        $category['name'],
                        $category['parent_id'] ? true : false,
                        count($category['child_id']) > 0 ? true : false,
                        $category['parent_id'] ? $category['parent_id'][0] : false
                    );
                }
            }
            return $payload;
        } catch (\Throwable $th) {
            throw $th;
        }
    }
}
