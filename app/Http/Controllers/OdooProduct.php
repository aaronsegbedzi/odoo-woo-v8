<?php

namespace App\Http\Controllers;

use App\Services\OdooClient;
use Illuminate\Support\Facades\Log;

class OdooProduct extends Controller
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

    /**
     * Image URLs point at a proxy that reads the record live from Odoo. Its
     * `model` parameter is not optional in practice: product.template ids and
     * product.product ids are separate Odoo sequences that overlap, and with no
     * `model` the proxy resolves product.template first — so a bare
     * `3844.jpg` serves template 3844's image even when variant 3844 exists and
     * has its own. Always state which model the id belongs to.
     */
    private function templateImageUrl($id): string
    {
        return rtrim((string) env('ODOO_IMG_URL', ''), '/') . '/' . (int) $id . '.jpg?model=template';
    }

    /** @see templateImageUrl() for why `model` is always sent. */
    private function variantImageUrl($id): string
    {
        return rtrim((string) env('ODOO_IMG_VARIANT_URL', ''), '/') . '/' . (int) $id . '.jpg?model=variant';
    }

    /**
     * Paged, not capped. A single search_read with a fixed limit silently
     * truncates a large window — and because the caller advances its watermark
     * on success, the rows it never saw fall outside every future window. Any
     * bulk edit in Odoo (a catalogue-wide price change) is enough to exceed a
     * fixed cap, so this pages until Odoo runs out of rows.
     */
    public function getProducts($range = false, $lastUpdatedAt = '', $single = false, $id = 0)
    {
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
            array('type', '=', 'consu'),
            array('x_brand', '!=', false),
            array('image_1920', '!=', false),
            array('default_code', '!=', ''),
            array('available_in_pos', '=', true),
            array('has_configurable_attributes', '=', false)
        );

        if ($range && $lastUpdatedAt != '') {
            $criteria[] = array('write_date', '>=', $lastUpdatedAt);
        }

        if ($single) {
            $criteria[] = array('id', '=', $id);
        }

        $products = $this->searchReadAll('product.template', $criteria, $fields);
        $payload  = [];

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
                    'image' => $this->templateImageUrl($product['id']),
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

        return [];
    }

    /** @see getProducts() for why this pages rather than taking a fixed limit. */
    public function getVariableProducts($with_variants = true, $lastUpdatedAt = '', $single = false, $id = 0)
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
            'x_image_last_updated_on',
            'pricelist_rule_ids'
        );

        $criteria = array(
            array('type', '=', 'consu'),
            array('is_favorite', '=', 1),
            array('x_brand', '!=', false),
            array('image_1920', '!=', false),
            array('available_in_pos', '=', true),
            array('has_configurable_attributes', '=', true)
        );

        if($lastUpdatedAt != ''){
            $criteria[] = array('write_date', '>=', $lastUpdatedAt);
        }

        if ($single){
            $criteria[] = array('id', '=', $id);
        }

        $products = $this->searchReadAll('product.template', $criteria, $fields);
        $payload  = [];

        if (!empty($products)) {
            $variantsByTemplate = $with_variants ? $this->getVariantsForTemplates($products) : [];

            foreach ($products as $product) {
                $payload[] = array(
                    'id' => $product['id'],
                    'name' => $product['name'],
                    'brand' => $product['x_brand'] == true ? trim($product['x_brand']) : 'None',
                    'cat' => $product['categ_id'],
                    'image' => $this->templateImageUrl($product['id']),
                    'description' => $product['description_sale'] == true ? $product['description_sale'] : '',
                    'directions' => $product['x_directions'] == true ? $product['x_directions'] : '',
                    'ingredients' => $product['x_ingredients'] == true ? $product['x_ingredients'] : '',
                    'variants' => $with_variants ? ($variantsByTemplate[$product['id']] ?? []) : '',
                    'x_image_last_updated_on' => $product['x_image_last_updated_on']
                );
            }
            if (count($payload) > 0) {
                return $payload;
            }
        }

        return [];
    }

    /**
     * Resolve the variants of every fetched template in three batched reads
     * (variants, attribute values, pricelist prices) instead of 1–2 RPC calls
     * per variant. Output per variant is identical to the old per-variant
     * resolution; variants are grouped by template id.
     */
    private function getVariantsForTemplates(array $templates): array
    {
        $templateIds = array_column($templates, 'id');

        // Templates with pricelist rules — only their variants get a price lookup.
        $pricelistTemplateIds = [];
        foreach ($templates as $t) {
            if (!empty($t['pricelist_rule_ids'])) {
                $pricelistTemplateIds[$t['id']] = true;
            }
        }

        $variants = $this->searchReadAll(
            'product.product',
            array(array('product_tmpl_id', 'in', $templateIds)),
            array('id', 'product_tmpl_id', 'product_template_variant_value_ids', 'qty_available', 'list_price', 'default_code', 'x_image_last_updated_on', 'barcode')
        );

        // Which variants carry an image of their OWN?
        //
        // `image_variant_1920` is populated only when a variant has a distinct
        // image; it is false when the variant simply inherits the template's
        // `image_1920`. That makes it the only authoritative answer to "is this
        // the variant's image or the parent's?" — the image host cannot answer
        // it, because product.template and product.product ids come from
        // separate sequences and collide (template 3844 and variant 3844 both
        // exist), so a file existing at <id>.jpg proves nothing about ownership.
        //
        // Only ids are read back, so the binaries are never transferred.
        $ownImageVariantIds = [];
        try {
            $rows = $this->searchReadAll(
                'product.product',
                array(
                    array('product_tmpl_id', 'in', $templateIds),
                    array('image_variant_1920', '!=', false),
                ),
                array('id')
            );
            foreach ($rows as $row) {
                $ownImageVariantIds[$row['id']] = true;
            }
        } catch (\Throwable $e) {
            // Guessing here means pushing wrong images to live variants — abort
            // instead, the caller logs and fails the run.
            Log::channel('sync')->error('Could not determine variant image ownership', ['error' => $e->getMessage()]);
            throw new \RuntimeException(
                'Failed to read image_variant_1920 ownership from Odoo: ' . $e->getMessage(),
                0,
                $e
            );
        }

        // All attribute values referenced by any variant, in one read.
        $valueIds = [];
        foreach ($variants as $v) {
            foreach ($v['product_template_variant_value_ids'] as $vid) {
                $valueIds[$vid] = true;
            }
        }

        $attributeValues = [];
        if (!empty($valueIds)) {
            $rows = $this->searchReadAll(
                'product.template.attribute.value',
                array(array('id', 'in', array_keys($valueIds))),
                array('id', 'name', 'attribute_line_id')
            );
            foreach ($rows as $row) {
                $attributeValues[$row['id']] = array(
                    'name' => $row['attribute_line_id'][1],
                    'value' => $row['name'],
                );
            }
        }

        // Fixed prices from the configured pricelist, in one read.
        $pricelistVariantIds = [];
        foreach ($variants as $v) {
            $templateId = is_array($v['product_tmpl_id']) ? $v['product_tmpl_id'][0] : $v['product_tmpl_id'];
            if (isset($pricelistTemplateIds[$templateId])) {
                $pricelistVariantIds[] = $v['id'];
            }
        }

        $fixedPrices = [];
        if (!empty($pricelistVariantIds)) {
            $rows = $this->searchReadAll(
                'product.pricelist.item',
                array(
                    array('product_id', 'in', $pricelistVariantIds),
                    array('pricelist_id', '=', config('app.odoowoo_pricelist')),
                ),
                array('id', 'fixed_price', 'product_id')
            );
            foreach ($rows as $row) {
                $variantId = is_array($row['product_id']) ? $row['product_id'][0] : $row['product_id'];
                // First matching rule wins — same as the old per-variant lookup.
                if (!array_key_exists($variantId, $fixedPrices)) {
                    $fixedPrices[$variantId] = $row['fixed_price'];
                }
            }
        }

        $byTemplate = [];
        foreach ($variants as $v) {
            $templateId   = is_array($v['product_tmpl_id']) ? $v['product_tmpl_id'][0] : $v['product_tmpl_id'];
            $firstValueId = $v['product_template_variant_value_ids'][0] ?? null;
            $attr         = $firstValueId !== null ? ($attributeValues[$firstValueId] ?? null) : null;

            if ($attr === null) {
                Log::channel('sync')->warning('Variant attribute could not be resolved', [
                    'variant_id'  => $v['id'],
                    'template_id' => $templateId,
                    'sku'         => $v['default_code'],
                ]);
            }

            // A variant that inherits the template image gets no variant URL at
            // all — the sync falls back to the parent image for those. Emitting
            // a URL here would send the caller looking for <variant_id>.jpg,
            // which may exist but belong to an unrelated product.template.
            $hasOwnImage = isset($ownImageVariantIds[$v['id']]);

            $byTemplate[$templateId][] = array(
                'id' => $v['id'],
                'sku' => $v['default_code'],
                'image' => $hasOwnImage ? $this->variantImageUrl($v['id']) : null,
                'has_own_image' => $hasOwnImage,
                'qty' => $v['qty_available'],
                'price' => array_key_exists($v['id'], $fixedPrices) ? $fixedPrices[$v['id']] : $v['list_price'],
                'att_name' => $attr['name'] ?? '',
                'att_value' => $attr['value'] ?? '',
                'x_image_last_updated_on' => $v['x_image_last_updated_on'],
                'gtin' => $v['barcode']
            );
        }

        return $byTemplate;
    }

}
