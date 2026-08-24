<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Codexshaper\WooCommerce\Facades\Tag;

class WooConnection extends Controller
{
    /**
     * Prove the WooCommerce credentials work by round-tripping the 'odoo' tag.
     *
     * The tag is looked up by slug rather than by scanning the tag list:
     * Tag::all() returns only the first page — 10 of several hundred tags —
     * so an 'odoo' tag sorting past page 1 was never found, and every run
     * retried the create and failed with `term_exists`.
     *
     * A read that reaches WooCommerce at all already proves the connection;
     * the create only runs on a store that has never been synced.
     */
    public function connection()
    {
        foreach (Tag::all(['slug' => 'odoo']) as $tag) {
            if ($tag->slug === 'odoo') {
                return 'Connection Established';
            }
        }

        Tag::create(['name' => 'odoo']);

        return 'Connection Established';
    }
}
