<?php

namespace App\Http\Controllers;

use App\Console\Commands\SyncWooProducts;
use Illuminate\Http\Request;
use Codexshaper\WooCommerce\Facades\Category;
use Illuminate\Support\Facades\Log;

class WooCategory extends Controller
{
    public function syncCategory($OdooCategories)
    {

        $Categories = array();

        if (!empty($OdooCategories)) {

            $WooCategories = Category::all(['per_page' => 100]);

            foreach ($OdooCategories as $OdooCategory) {

                $OdooCategoryExists = false;

                foreach ($WooCategories as $WooCategory) {

                    if ($OdooCategory[1] == $WooCategory->name) {
                        $OdooCategoryExists = true;
                        Log::info('Product Category Exists: ' . $OdooCategory[1]);
                    }
                }

                if ($OdooCategoryExists == false) {

                    $data = [
                        'name' => $OdooCategory[1]
                    ];

                    $create = Category::create($data);

                    Log::info('Created Product Category: ' . $OdooCategory[1]);
                }
            }

            $WooCategories = Category::all(['per_page' => 100]);

            foreach ($OdooCategories as $OdooCategory) {

                foreach ($WooCategories as $WooCategory) {

                    if ($OdooCategory[1] == $WooCategory->name) {

                        $Categories[] = array(
                            'woo_id' => $WooCategory->id,
                            'odoo_id' => $OdooCategory[0],
                            'name' => $OdooCategory[1]
                        );
                    }
                }
            }
            return $Categories;
        }
        return false;
    }

    public function getCategories()
    {
        $next_page = 1;
        $categories_ = [];
        $payload = [];

        do {
            $categories = Category::paginate(100, $next_page);
            $next_page = $categories['meta']['next_page'];
            foreach ($categories['data'] as $value) {
                $payload[] = $value;
            }
        } while ($next_page > 0);

        if (count($payload) > 0) {
            foreach ($payload as $category) {
                $categories_[] = array($category->id, $category->name);
            }
            return $categories_;
        }

        return [];
    }

    public function createCategory($name)
    {
        try {
            $data = [
                'name' => $name
            ];
            $category = Category::create($data);
            $payload = json_decode($category, true);
            return $payload['id'];
        } catch (\Throwable $th) {
            return false;
        }
    }

    public function setParentCatergory($id, $parent_id)
    {
        try {
            $data = [
                'parent' => $parent_id
            ];
            $category = Category::update($id, $data);
            return $category;
        } catch (\Throwable $th) {
            return false;
        }
    }
}
