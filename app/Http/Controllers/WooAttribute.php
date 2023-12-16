<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Codexshaper\WooCommerce\Facades\Attribute;
use Codexshaper\WooCommerce\Facades\Term;
use Illuminate\Support\Facades\Log;

class WooAttribute extends Controller
{
    public function getAttributes()
    {
        $next_page = 1;
        $attributes_ = [];
        $payload = [];

        do {
            $attributes = Attribute::paginate(100, $next_page);
            $next_page = $attributes['meta']['next_page'];
            foreach ($attributes['data'] as $value) {
                $payload[] = $value;
            }
        } while ($next_page > 0);

        if (count($payload) > 0) {
            foreach ($payload as $attribute) {
                $attributes_[] = array($attribute->id, $attribute->name);
            }
            return $attributes_;
        }

        return [];
    }

    public function getAttributeTerms($id)
    {

        $next_page = 1;
        $attributes_ = [];
        $payload = [];

        do {
            $attributes = Term::paginate($id, 100, $next_page);
            $next_page = $attributes['meta']['next_page'];
            foreach ($attributes['data'] as $value) {
                $payload[] = $value;
            }
            Log::info('Count: '.count($payload));
        } while ($next_page > 0);

        if (count($payload) > 0) {
            foreach ($payload as $attribute) {
                $attributes_[] = array($attribute->id, html_entity_decode(trim($attribute->name)));
            }
            return $attributes_;
        }

        return [];
    }

    public function createAttribute($name)
    {
        try {
            $data = [
                'name' => $name,
                'slug' => 'pa_' . strtolower($name),
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => true
            ];
            $attribute = Attribute::create($data);
            return true;
        } catch (\Throwable $th) {
            return false;
        }
    }

    public function createAttributeTerm($id, $name)
    {

        try {
            $data = [
                'name' => $name,
            ];
            $term = Term::create($id, $data);
            return true;
        } catch (\Throwable $th) {
            return false;
        }
    }
}
