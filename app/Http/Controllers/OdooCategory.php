<?php

namespace App\Http\Controllers;

use App\Services\OdooClient;
use Illuminate\Support\Facades\Log;

class OdooCategory extends Controller
{
    protected OdooClient $client;

    public function __construct()
    {
        $this->client = new OdooClient(
            config('app.odoo_url'),
            config('app.odoo_db', ''),
            config('app.odoo_username'),
            config('app.odoo_password')
        );
    }

    public function getCategories(): array
    {
        $fields = ['id', 'name', 'complete_name', 'parent_id', 'child_id', 'product_count'];

        try {
            // Fetch all categories (no domain filter — we exclude specific names below)
            $categories = $this->client->search_read('product.category', [], $fields, 200);
        } catch (\Throwable $th) {
            Log::channel('sync')->error('Failed to fetch Odoo categories', ['error' => $th->getMessage()]);
            throw $th;
        }

        $payload  = [];
        $excluded = ['PoS', 'All', 'Expenses', 'Saleable'];

        foreach ($categories as $category) {
            if (!in_array($category['name'], $excluded)) {
                $payload[] = [
                    $category['id'],
                    $category['name'],
                    $category['parent_id'] ? true : false,
                    count($category['child_id']) > 0 ? true : false,
                    $category['parent_id'] ? $category['parent_id'][0] : false,
                ];
            }
        }

        return $payload;
    }
}
