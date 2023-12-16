<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Codexshaper\WooCommerce\Facades\Tag;

class WooConnection extends Controller
{
    public function connection()
    {
        // Retrieve all tags.
        $tags = Tag::all();

        $tagExists = false;

        // Check all tags for odoo slug.
        foreach ($tags as $tag) {
            if ($tag->slug == 'odoo') {
                return 'Connection Established';
            }
        }

        // Check if the tag exists. If not create the odoo tag.
        if ($tagExists == false) {

            $data = [
                'name' => 'odoo'
            ];

            $tag = Tag::create($data);

            return 'Connection Established';

        }

        return 'Connection Failed';
    }
}
