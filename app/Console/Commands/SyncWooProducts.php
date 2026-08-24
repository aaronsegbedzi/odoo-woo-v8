<?php

namespace App\Console\Commands;

use App\Http\Controllers\Controller;
use Illuminate\Console\Command;
use App\Http\Controllers\OdooProduct;
use App\Http\Controllers\WooCategory;
use App\Http\Controllers\WooAttribute;
use App\Http\Controllers\WooProduct;
use Codexshaper\WooCommerce\Facades\Product;
use App\Http\Controllers\OdooCategory;
use App\Services\SyncWatermark;
use Illuminate\Support\Facades\Log;
use DateTime;

class SyncWooProducts extends Command
{
    protected $signature = 'woo:sync {id? : Odoo product template ID to sync on its own} {--images : Force image push for every product} {--since= : Override the lookback start, "Y-m-d H:i:s" (UTC). Does not advance the watermark.}';
    protected $description = 'Synchronize simple products from Odoo to WooCommerce';

    /** Watermark key for this command — see App\Services\SyncWatermark. */
    private const WATERMARK = 'simple';

    /** Nominal schedule interval, used to flag windows that reveal skipped ticks. */
    private const NOMINAL_INTERVAL_MINUTES = 30;

    private bool $forceImages = false;
    private int $wooErrors = 0;
    private int $skippedUnchanged = 0;

    /** Memoised results of remote image existence checks, keyed by URL. */
    private array $imageCache = [];
    private ?\GuzzleHttp\Client $imageHttp = null;

    public function handle(): int
    {
        $startTime = microtime(true);

        // Artisan resolves a command to a single registered instance, so state
        // left behind by a previous handle() in the same process would leak
        // into this run — a stale non-zero wooErrors would block the watermark
        // advance for every subsequent run in that process.
        $this->wooErrors        = 0;
        $this->skippedUnchanged = 0;
        $this->imageCache       = [];
        $this->imageHttp        = null;

        // Captured before any work so records modified *during* this run fall
        // inside the next run's window rather than into the gap between them.
        $runStartedAt = date('Y-m-d H:i:s');

        $this->info('OdooWoo Simple Products Sync — ' . $runStartedAt);

        // ── 0. Single-product mode ────────────────────────────────────────────
        $singleId = $this->argument('id');

        if ($singleId !== null) {
            if (!ctype_digit((string) $singleId) || (int) $singleId < 1) {
                $this->error("Invalid product ID: {$singleId}. Expected a positive Odoo product template ID.");
                return self::FAILURE;
            }
            $singleId = (int) $singleId;
        }

        // Images are forced in single-product mode, or with --images in batch mode.
        $this->forceImages = $singleId !== null || (bool) $this->option('images');

        Log::channel('sync')->info('Simple product sync started', [
            'odoo_id'      => $singleId,
            'force_images' => $this->forceImages,
        ]);

        $controller = new Controller();
        $since      = '';

        if ($singleId) {
            $this->info("Single-product mode — Odoo product template ID: {$singleId} (write_date filter disabled, images forced)");
        } else {
            try {
                $window = SyncWatermark::resolveSince(self::WATERMARK, $this->option('since'));
            } catch (\InvalidArgumentException $e) {
                $this->error($e->getMessage());
                return self::FAILURE;
            }

            $since = $window['since'];

            $this->info("Fetching products modified since: {$since} ({$window['window_minutes']}m window, source: {$window['source']})");
            Log::channel('sync')->info('Simple sync window resolved', $window);

            $this->reportWindow($window, $since);
        }

        // ── 1. Fetch Odoo products ────────────────────────────────────────────
        try {
            $OdooProduct  = new OdooProduct();
            $OdooProducts = $OdooProduct->getProducts(true, $since, (bool) $singleId, (int) $singleId);
        } catch (\Throwable $e) {
            $this->error('Failed to fetch Odoo products: ' . $e->getMessage());
            Log::channel('sync')->error('Failed to fetch Odoo products', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        if (empty($OdooProducts)) {
            if ($singleId) {
                $this->error("No Odoo simple product found with ID {$singleId}.");
                $this->line('It must satisfy all of: type=consu, x_brand set, image_1920 set, default_code set, available_in_pos=true, has_configurable_attributes=false.');
                Log::channel('sync')->warning('Single simple sync — product not found', ['odoo_id' => $singleId]);
                return self::FAILURE;
            }

            $this->info('No Odoo products modified since ' . $since . '. Nothing to do.');
            Log::channel('sync')->info('Simple sync skipped — no Odoo products modified since', ['since' => $since]);

            // A quiet window is still a covered window. Skipping this would let
            // the window grow without bound across an idle night.
            $this->advanceWatermark($singleId, $runStartedAt);
            return self::SUCCESS;
        }

        $this->info('Odoo simple products fetched: ' . count($OdooProducts));
        Log::channel('sync')->info('Odoo simple products fetched', ['count' => count($OdooProducts), 'since' => $since]);

        // ── 2. Fetch WooCommerce products ─────────────────────────────────────
        // The catalogue is used for exactly one thing — looking up the SKUs Odoo
        // just returned — so only those SKUs are requested. Paging the whole
        // catalogue produces the same lookups at ~35 requests instead of one per
        // 100 SKUs, and is kept as a fallback.
        //
        // Only the fields the diff and change-detection need are requested;
        // everything the comparator reads must stay in this list.
        $wooFields = [
            'id', 'sku', 'name', 'status', 'regular_price',
            'stock_quantity', 'stock_status', 'manage_stock', 'categories',
            'attributes', 'description', 'short_description', 'meta_data',
        ];

        try {
            $WooProduct = new WooProduct();

            if ($singleId) {
                $sku = $OdooProducts[0]['sku'] ?? '';
                $this->info("Looking up WooCommerce product by SKU: {$sku}");
                $WooProducts = $WooProduct->getProductsBySku($sku);
            } elseif (config('app.odoowoo_sku_lookup', true)) {
                $odooSkus = array_filter(array_map(fn($p) => $p['sku'] ?? '', $OdooProducts));
                $requests = (int) ceil(count(array_unique($odooSkus)) / 100);
                $this->info("Looking up {$requests} batch(es) of WooCommerce products by SKU…");
                $WooProducts = $WooProduct->getProductsBySkus($odooSkus, $wooFields);
                Log::channel('sync')->info('Woo catalogue read by SKU batches', [
                    'skus'     => count(array_unique($odooSkus)),
                    'requests' => $requests,
                ]);
            } else {
                $this->info('Fetching the full WooCommerce simple catalogue (100 per request — this takes a few minutes)…');
                $WooProducts = $WooProduct->getProducts($wooFields);
            }
        } catch (\Throwable $e) {
            $this->error('Failed to fetch WooCommerce products: ' . $e->getMessage());
            Log::channel('sync')->error('Failed to fetch WooCommerce products', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        $this->info('WooCommerce simple products fetched: ' . count($WooProducts));
        Log::channel('sync')->info('WooCommerce simple products fetched', ['count' => count($WooProducts)]);

        // ── 3. Categories ─────────────────────────────────────────────────────
        $Categories = $this->syncCategories();

        // ── 4. Brands ─────────────────────────────────────────────────────────
        $WooAttributeTerms = $this->syncBrands($OdooProducts);

        // ── 5. Classify products — O(n) via SKU index ─────────────────────────
        $wooSkuIndex = [];
        foreach ($WooProducts as $woo) {
            if (!empty($woo->sku)) {
                $wooSkuIndex[$woo->sku] = $woo;
            }
        }

        $CreateProducts = [];
        $UpdateProducts = [];

        foreach ($OdooProducts as $odooProduct) {
            $sku = $odooProduct['sku'] ?? '';
            if ($sku && isset($wooSkuIndex[$sku])) {
                $odooProduct['woo_id'] = $wooSkuIndex[$sku]->id;
                // Carried along so the update step can skip products whose
                // payload would change nothing in WooCommerce.
                $odooProduct['woo'] = $wooSkuIndex[$sku];
                $UpdateProducts[]   = $odooProduct;
            } else {
                $CreateProducts[] = $odooProduct;
            }
        }

        $this->info('To create: ' . count($CreateProducts) . ' | To update: ' . count($UpdateProducts));
        Log::channel('sync')->info('Product diff computed', [
            'create' => count($CreateProducts),
            'update' => count($UpdateProducts),
        ]);

        // Build O(1) lookup indexes for categories and brands
        $categoryIndex = [];
        foreach ($Categories as $cat) {
            $categoryIndex[$cat[0]] = $cat; // keyed by Odoo category ID
        }
        $brandIndex = [];
        foreach ($WooAttributeTerms as $term) {
            $brandIndex[$term[1]] = $term; // keyed by brand name (upper)
        }

        // ── 6. Batch create ───────────────────────────────────────────────────
        if (!empty($CreateProducts)) {
            $this->runBatchCreate($CreateProducts, $categoryIndex, $brandIndex, $controller);
        }

        // ── 7. Batch update ───────────────────────────────────────────────────
        if (!empty($UpdateProducts)) {
            $this->runBatchUpdate($UpdateProducts, $categoryIndex, $brandIndex, $controller);
        }

        $elapsed = round(microtime(true) - $startTime, 1);
        if ($this->wooErrors > 0) {
            $this->error("Sync completed in {$elapsed}s with {$this->wooErrors} WooCommerce error(s) — see sync log for details.");
        } else {
            $this->info("Sync completed in {$elapsed}s.");
        }
        Log::channel('sync')->info('Simple product sync completed', [
            'elapsed_s'         => $elapsed,
            'created'           => count($CreateProducts),
            'updated'           => count($UpdateProducts) - $this->skippedUnchanged,
            'skipped_unchanged' => $this->skippedUnchanged,
            'errors'            => $this->wooErrors,
            'odoo_id'           => $singleId,
        ]);

        $this->advanceWatermark($singleId, $runStartedAt);

        return $this->wooErrors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Say out loud what the resolved window means.
     *
     * Two distinct conditions, and the severe one is NOT the wide window:
     *
     * - 'clamped' means the watermark was older than the lookback ceiling, so
     *   a range was dropped from this window — and because the run goes on to
     *   advance the watermark, that range will never be looked at again. This
     *   is the only place data is knowingly discarded, so it is reported at
     *   error level with the exact command needed to recover it.
     * - A merely wide window means ticks were skipped. The watermark still
     *   guarantees no data is lost; only the cadence slipped.
     */
    private function reportWindow(array $window, string $since): void
    {
        if ($window['clamped_from'] !== null) {
            $this->error("Lookback ceiling hit — records modified between {$window['clamped_from']} and {$since} are NOT in this window and will be marked covered.");
            $this->error("  Recover them with: php artisan {$this->getName()} --since=\"{$window['clamped_from']}\"");

            Log::channel('sync')->error('Sync window clamped — an uncovered range was discarded', [
                'discarded_from'     => $window['clamped_from'],
                'discarded_to'       => $since,
                'max_lookback_hours' => max(1, (int) config('app.odoowoo_sync_max_lookback_hours', 168)),
                'recover_with'       => "php artisan {$this->getName()} --since=\"{$window['clamped_from']}\"",
            ]);

            return;
        }

        if ($window['source'] === 'watermark'
            && $window['window_minutes'] > self::NOMINAL_INTERVAL_MINUTES * 2) {
            $this->warn("Window is {$window['window_minutes']}m — earlier scheduled runs were skipped.");
            Log::channel('sync')->warning('Sync window wider than nominal interval — ticks are being skipped', [
                'window_minutes'  => $window['window_minutes'],
                'nominal_minutes' => self::NOMINAL_INTERVAL_MINUTES,
            ]);
        }
    }

    /**
     * Mark this run's window as covered, so the next run starts where it ended.
     *
     * Deliberately a no-op in three cases: single-product runs and --since
     * backfills cover an arbitrary window and must not be mistaken for scheduled
     * progress, and a run with Woo errors has not actually shipped its window —
     * leaving the watermark put lets the next run retry the failures.
     */
    private function advanceWatermark(?int $singleId, string $runStartedAt): void
    {
        if ($singleId !== null || $this->option('since') || $this->wooErrors > 0) {
            return;
        }

        SyncWatermark::set(self::WATERMARK, $runStartedAt);
    }

    // ─── Category sync ────────────────────────────────────────────────────────

    private function syncCategories(): array
    {
        $OdooCategory   = new OdooCategory();
        $OdooCategories = $OdooCategory->getCategories();
        $this->info('Odoo categories fetched: ' . count($OdooCategories));

        $WooCategory    = new WooCategory();
        $WooCategories  = $WooCategory->getCategories();
        $this->info('WooCommerce categories fetched: ' . count($WooCategories));

        $wooNames        = array_flip(array_column($WooCategories, 1)); // name => index
        $CreateCategories = array_filter($OdooCategories, fn($c) => !isset($wooNames[$c[1]]));

        if (!empty($CreateCategories)) {
            $this->info('Creating ' . count($CreateCategories) . ' missing categories in WooCommerce.');
            foreach ($CreateCategories as $cat) {
                // createCategory() swallows every throwable and returns false.
                // Left unchecked, the category stays missing and every product
                // in it is published under the fallback id — counting it as an
                // error holds the watermark so the next run re-pushes them.
                $createdId = $WooCategory->createCategory($cat[1]);

                if (empty($createdId)) {
                    $this->wooErrors++;
                    $this->error("  FAILED to create category: {$cat[1]}");
                    Log::channel('sync')->error('Category creation failed', ['name' => $cat[1]]);
                    continue;
                }

                $this->info("  Created category: {$cat[1]}");
                Log::channel('sync')->info('Category created', ['name' => $cat[1]]);
            }
            $WooCategories = (new WooCategory())->getCategories();
        }

        // Merge Odoo + Woo category data
        $wooByName  = [];
        foreach ($WooCategories as $wc) {
            $wooByName[$wc[1]] = $wc;
        }
        $Categories = array_map(fn($oc) => array_merge($oc, $wooByName[$oc[1]] ?? []), $OdooCategories);

        if (!empty($CreateCategories)) {
            $this->info('Setting parent structure for new categories.');
            $catByOdooId = [];
            $catByName   = [];
            foreach ($Categories as $cat) {
                $catByOdooId[$cat[0]] = $cat;
                $catByName[$cat[1]]   = $cat;
            }
            foreach ($CreateCategories as $cat) {
                if ($cat[3]) { // has children / has parent
                    $wooCatId  = $catByName[$cat[1]][5] ?? null;
                    $parentOdooId = $catByName[$cat[1]][4] ?? null;
                    $parentWooId  = isset($catByOdooId[$parentOdooId]) ? ($catByOdooId[$parentOdooId][5] ?? null) : null;
                    if ($wooCatId && $parentWooId) {
                        (new WooCategory())->setParentCatergory($wooCatId, $parentWooId);
                    }
                }
            }
            $WooCategories = (new WooCategory())->getCategories();
            foreach ($WooCategories as $wc) {
                $wooByName[$wc[1]] = $wc;
            }
            $Categories = array_map(fn($oc) => array_merge($oc, $wooByName[$oc[1]] ?? []), $OdooCategories);
        }

        return $Categories;
    }

    // ─── Brand sync ───────────────────────────────────────────────────────────

    private function syncBrands(array $OdooProducts): array
    {
        $brandId = config('app.woo_brand_attribute_id');

        $OdooBrands = [];
        foreach ($OdooProducts as $p) {
            if (!empty($p['brand'])) {
                $OdooBrands[strtoupper($this->cleanEncoding($p['brand']))] = true;
            }
        }
        $OdooBrands = array_keys($OdooBrands);
        $this->info('Odoo brands: ' . count($OdooBrands));

        $WooAttribute     = new WooAttribute();
        $WooAttributeTerms = $WooAttribute->getAttributeTerms($brandId);
        $this->info('WooCommerce brands: ' . count($WooAttributeTerms));

        $wooTermNames = array_flip(array_column($WooAttributeTerms, 1));
        $CreateTerms  = array_filter($OdooBrands, fn($b) => !isset($wooTermNames[$b]));

        if (!empty($CreateTerms)) {
            $this->info('Creating ' . count($CreateTerms) . ' missing brands.');
            foreach ($CreateTerms as $term) {
                // As with categories: a swallowed failure here means the term
                // stays missing and every product of that brand is pushed with
                // an empty brand option, overwriting whatever Woo already had.
                if (!$WooAttribute->createAttributeTerm($brandId, $term)) {
                    $this->wooErrors++;
                    $this->error("  FAILED to create brand: {$term}");
                    Log::channel('sync')->error('Brand creation failed', ['name' => $term]);
                    continue;
                }

                $this->info("  Created brand: {$term}");
                Log::channel('sync')->info('Brand created', ['name' => $term]);
            }
            $WooAttributeTerms = (new WooAttribute())->getAttributeTerms($brandId);
        }

        return $WooAttributeTerms;
    }

    // ─── Batch create ─────────────────────────────────────────────────────────

    private function runBatchCreate(array $products, array $categoryIndex, array $brandIndex, Controller $controller): void
    {
        $this->info('Starting create job (' . count($products) . ' products).');
        Log::channel('sync')->info('Batch create started', ['count' => count($products)]);

        $batch     = [];
        $brandId   = config('app.woo_brand_attribute_id');

        foreach ($products as $p) {
            $cat   = $categoryIndex[$p['cat'][0] ?? 0] ?? null;
            $brand = $brandIndex[strtoupper($this->cleanEncoding($p['brand'] ?? ''))] ?? null;
            $this->warnRelationalMisses($p, $cat, $brand);

            $entry = [
                'name'              => $p['name'],
                'status'            => 'publish',
                'type'              => 'simple',
                'regular_price'     => (string) $p['price'],
                'sku'               => $p['sku'],
                'manage_stock'      => true,
                'stock_quantity'    => max(0, (int) $p['qty']),
                'stock_status'      => $p['qty'] > 0 ? 'instock' : 'outofstock',
                'description'       => $controller->formatDescription($p['description'], $p['directions'], $p['ingredients'], $p['name']),
                'short_description' => $controller->truncateString($p['description']),
                'categories'        => [['id' => $cat[5] ?? 15]],
                'attributes'        => [[
                    'id'        => $brandId,
                    'name'      => 'Brand',
                    'visible'   => true,
                    'variation' => false,
                    'options'   => [$brand[1] ?? ''],
                ]],
                'meta_data' => [
                    ['key' => 'odoo_woo_id',   'value' => (string) $p['id']],
                    ['key' => '_woosea_gtin',  'value' => (string) $p['gtin']],
                ],
            ];

            // Verified before sending — a 404 image fails the whole product row.
            if ($this->imageUsable($p['image'] ?? null)) {
                $entry['images'] = [['src' => $p['image']]];
            } else {
                $this->warn("  Image missing for {$p['name']} — creating without it.");
                Log::channel('sync')->warning('Product image missing', ['product' => $p['name'], 'url' => $p['image'] ?? null]);
            }

            $batch[] = $entry;
        }

        $batchSize = $controller->wooProductsPerBatch();
        $chunks    = array_chunk($batch, $batchSize);
        $total     = count($chunks);

        foreach ($chunks as $i => $chunk) {
            $num = $i + 1;
            try {
                $this->info("Create batch {$num}/{$total}…");
                $response = Product::batch(['create' => $chunk]);
                $result   = $this->reportBatchErrors($response, 'create', $chunk);
                $this->info("  Batch {$num}/{$total} done.");
                Log::channel('sync')->info('Create batch completed', [
                    'batch'   => $num,
                    'of'      => $total,
                    'size'    => count($chunk),
                    'failed'  => $result['failed'],
                    'shipped' => $result['shipped'],
                ]);
            } catch (\Throwable $e) {
                $this->wooErrors++;
                $this->error("  Batch {$num}/{$total} FAILED: " . $e->getMessage());
                Log::channel('sync')->error('Create batch failed', [
                    'batch' => $num,
                    'of'    => $total,
                    'error' => $e->getMessage(),
                ]);
            }
            if ($num < $total) {
                sleep($controller->wooSleepSeconds());
            }
        }

        $this->info('Create job completed.');
    }

    // ─── Batch update ─────────────────────────────────────────────────────────

    private function runBatchUpdate(array $products, array $categoryIndex, array $brandIndex, Controller $controller): void
    {
        $this->info('Starting update job (' . count($products) . ' products).');
        Log::channel('sync')->info('Batch update started', ['count' => count($products)]);

        $batch         = [];
        $brandId       = config('app.woo_brand_attribute_id');
        $now           = new DateTime();
        $skipUnchanged = (bool) config('app.odoowoo_skip_unchanged', true);
        $skipped       = 0;

        foreach ($products as $p) {
            $cat   = $categoryIndex[$p['cat'][0] ?? 0] ?? null;
            $brand = $brandIndex[strtoupper($this->cleanEncoding($p['brand'] ?? ''))] ?? null;
            $this->warnRelationalMisses($p, $cat, $brand);

            $entry = [
                'id'                => $p['woo_id'],
                'name'              => $p['name'],
                'status'            => 'publish',
                'type'              => 'simple',
                'regular_price'     => (string) $p['price'],
                'manage_stock'      => true,
                'stock_quantity'    => max(0, (int) $p['qty']),
                'stock_status'      => $p['qty'] > 0 ? 'instock' : 'outofstock',
                'description'       => $controller->formatDescription($p['description'], $p['directions'], $p['ingredients'], $p['name']),
                'short_description' => $controller->truncateString($p['description']),
                'categories'        => [['id' => $cat[5] ?? 15]],
                'attributes'        => [[
                    'id'        => $brandId,
                    'name'      => 'Brand',
                    'visible'   => true,
                    'variation' => false,
                    'options'   => [$brand[1] ?? ''],
                ]],
                'meta_data' => [
                    ['key' => 'odoo_woo_id',   'value' => (string) $p['id']],
                    ['key' => '_woosea_gtin',  'value' => (string) $p['gtin']],
                ],
            ];

            // Push image only if updated within the last hour — unless forced (single-product mode)
            $pushImage = $this->forceImages;
            if (!$pushImage && !empty($p['x_image_last_updated_on'])) {
                $diff      = $now->diff(new DateTime($p['x_image_last_updated_on']));
                $pushImage = $diff->days === 0 && $diff->h < 1;
            }

            if ($pushImage) {
                if ($this->imageUsable($p['image'] ?? null)) {
                    $entry['images'] = [['src' => $p['image']]];
                } else {
                    $this->warn("  Image missing for {$p['name']} — leaving the existing Woo image alone.");
                    Log::channel('sync')->warning('Product image missing', ['product' => $p['name'], 'url' => $p['image'] ?? null]);
                }
            }

            // Nothing in the payload differs from what WooCommerce already has —
            // sending it would only burn API time. Never skipped when an image
            // push is due, and any doubt in the comparison means "update".
            if (!$pushImage && $skipUnchanged && isset($p['woo']) && $this->entryMatchesWoo($entry, $p['woo'])) {
                $skipped++;
                continue;
            }

            $batch[] = $entry;
        }

        if ($skipped > 0) {
            $this->info("  {$skipped} of " . count($products) . ' products unchanged — skipped.');
            Log::channel('sync')->info('Unchanged products skipped', [
                'skipped' => $skipped,
                'of'      => count($products),
            ]);
        }
        $this->skippedUnchanged = $skipped;

        if (empty($batch)) {
            $this->info('Update job completed — nothing to push.');
            return;
        }

        $batchSize = $controller->wooProductsPerBatch();
        $chunks    = array_chunk($batch, $batchSize);
        $total     = count($chunks);

        foreach ($chunks as $i => $chunk) {
            $num = $i + 1;
            try {
                $this->info("Update batch {$num}/{$total}…");
                $response = Product::batch(['update' => $chunk]);
                $result   = $this->reportBatchErrors($response, 'update', $chunk);
                $this->info("  Batch {$num}/{$total} done.");
                Log::channel('sync')->info('Update batch completed', [
                    'batch'   => $num,
                    'of'      => $total,
                    'size'    => count($chunk),
                    'failed'  => $result['failed'],
                    'shipped' => $result['shipped'],
                ]);
            } catch (\Throwable $e) {
                $this->wooErrors++;
                $this->error("  Batch {$num}/{$total} FAILED: " . $e->getMessage());
                Log::channel('sync')->error('Update batch failed', [
                    'batch' => $num,
                    'of'    => $total,
                    'error' => $e->getMessage(),
                ]);
            }
            if ($num < $total) {
                sleep($controller->wooSleepSeconds());
            }
        }

        $this->info('Update job completed.');
    }

    // ─── Image resolution ─────────────────────────────────────────────────────

    /**
     * Does this image actually exist on the Odoo image host? WooCommerce
     * side-loads images by URL, so a 404 fails the whole product row with
     * `woocommerce_product_image_upload_error`. Checking first is cheaper
     * than losing the row.
     *
     * Returns true if it exists, false only when the host definitively says it
     * does not (404/410), and null when the check was inconclusive — a network
     * blip, SSL failure or 5xx. Inconclusive must NOT be treated as missing, or
     * one bad network moment strips images from the whole catalogue.
     */
    private function imageStatus(?string $url): ?bool
    {
        if (empty($url)) {
            return false;
        }
        if (array_key_exists($url, $this->imageCache)) {
            return $this->imageCache[$url];
        }

        if ($this->imageHttp === null) {
            $this->imageHttp = new \GuzzleHttp\Client([
                'timeout'         => 15,
                'connect_timeout' => 10,
                'http_errors'     => false,
                'verify'          => config('app.odoo_verify_ssl', true),
            ]);
        }

        try {
            $status = $this->imageHttp->head($url)->getStatusCode();
            // Some hosts reject HEAD outright — fall back to a ranged GET.
            if (in_array($status, [403, 405, 501], true)) {
                $status = $this->imageHttp->get($url, ['headers' => ['Range' => 'bytes=0-0']])->getStatusCode();
            }

            if ($status >= 200 && $status < 300) {
                $result = true;
            } elseif (in_array($status, [404, 410], true)) {
                $result = false;
            } else {
                $result = null; // 5xx and friends — inconclusive
                Log::channel('sync')->warning('Image check inconclusive', ['url' => $url, 'status' => $status]);
            }
        } catch (\Throwable $e) {
            Log::channel('sync')->warning('Image check failed, assuming image is usable', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);
            $result = null;
        }

        return $this->imageCache[$url] = $result;
    }

    /** True unless the host definitively told us the image is gone. */
    private function imageUsable(?string $url): bool
    {
        return $this->imageStatus($url) !== false;
    }

    // ─── Relational-miss reporting ────────────────────────────────────────────

    /**
     * A relational lookup that misses falls back silently (default category,
     * empty brand) — surface every miss so it can be fixed on the Odoo or Woo
     * side instead of going unnoticed.
     */
    private function warnRelationalMisses(array $p, ?array $cat, ?array $brand): void
    {
        if ($cat === null) {
            $this->warn("  Category not matched for {$p['name']} — using default category.");
            Log::channel('sync')->warning('Category not matched, default used', [
                'product'          => $p['name'],
                'sku'              => $p['sku'] ?? null,
                'odoo_category_id' => $p['cat'][0] ?? null,
                'odoo_category'    => $p['cat'][1] ?? null,
                'fallback_woo_id'  => 15,
            ]);
        }
        if ($brand === null) {
            $this->warn("  Brand term not matched for {$p['name']} — pushing empty brand.");
            Log::channel('sync')->warning('Brand term not matched, empty brand pushed', [
                'product' => $p['name'],
                'sku'     => $p['sku'] ?? null,
                'brand'   => $p['brand'] ?? null,
            ]);
        }
    }

    // ─── Change detection ─────────────────────────────────────────────────────

    /**
     * Would this update payload change anything in WooCommerce? Compared
     * against the product data already fetched from the catalogue.
     *
     * Deliberately strict: the sync replaces the whole category and attribute
     * lists on every update, so a product carrying anything beyond the exact
     * lists it would push counts as "changed" (the push would strip them — as
     * it always has). Any doubt, missing field or comparison error returns
     * false, which means the product is updated exactly as before.
     */
    private function entryMatchesWoo(array $entry, $woo): bool
    {
        try {
            if (!is_object($woo)) {
                return false;
            }

            if (($woo->status ?? null) !== $entry['status']) {
                return false;
            }
            if (($woo->manage_stock ?? null) !== true) {
                return false;
            }
            if ($this->normalizeText($woo->name ?? '') !== $this->normalizeText($entry['name'])) {
                return false;
            }

            // '' (no price) and '0' (free) are different states in Woo even
            // though both cast to 0.0.
            $wooPrice = (string) ($woo->regular_price ?? '');
            if (($wooPrice === '') !== ($entry['regular_price'] === '')) {
                return false;
            }
            if ((float) $wooPrice !== (float) $entry['regular_price']) {
                return false;
            }

            if ((int) ($woo->stock_quantity ?? 0) !== (int) $entry['stock_quantity']) {
                return false;
            }
            if (($woo->stock_status ?? null) !== $entry['stock_status']) {
                return false;
            }

            $wooCats = $woo->categories ?? [];
            if (count($wooCats) !== 1 || (int) ($wooCats[0]->id ?? 0) !== (int) $entry['categories'][0]['id']) {
                return false;
            }

            $wooAttrs = $woo->attributes ?? [];
            if (count($wooAttrs) !== 1) {
                return false;
            }
            $attr = $wooAttrs[0];
            if ((int) ($attr->id ?? 0) !== (int) $entry['attributes'][0]['id']) {
                return false;
            }
            $norm         = fn($o) => strtoupper($this->normalizeText((string) $o));
            $wooOptions   = array_map($norm, (array) ($attr->options ?? []));
            $entryOptions = array_map($norm, $entry['attributes'][0]['options']);
            if (array_values($wooOptions) !== array_values($entryOptions)) {
                return false;
            }

            // WooCommerce runs stored content through display filters (wpautop
            // et al.) before returning it, so descriptions are compared on
            // text content, not markup.
            if ($this->normalizeText($woo->description ?? '') !== $this->normalizeText($entry['description'])) {
                return false;
            }
            if ($this->normalizeText($woo->short_description ?? '') !== $this->normalizeText($entry['short_description'])) {
                return false;
            }

            foreach ($entry['meta_data'] as $meta) {
                if ($this->metaValue($woo, $meta['key']) !== (string) $meta['value']) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Collapse markup, entities and whitespace so only text content is compared. */
    private function normalizeText(?string $text): string
    {
        $text = html_entity_decode($text ?? '', ENT_QUOTES | ENT_HTML5);
        $text = strip_tags($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        return trim($text);
    }

    private function metaValue(object $woo, string $key): ?string
    {
        foreach ($woo->meta_data ?? [] as $m) {
            if (($m->key ?? null) === $key) {
                return is_scalar($m->value ?? null) ? (string) $m->value : null;
            }
        }
        return null;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function cleanEncoding(string $value): string
    {
        $value = str_replace(["'", "'", "´", "`"], "'", $value);
        $enc   = mb_detect_encoding($value, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true) ?: 'UTF-8';
        return mb_convert_encoding($value, 'UTF-8', $enc);
    }

    /**
     * Normalise a Woo API response to a plain array. The package returns a
     * Collection by default (QueryBuilderTrait::$isCollection = true), but can
     * be configured to hand back the raw stdClass — this copes with either.
     */
    private function toArray($response): array
    {
        if (is_array($response)) {
            return $response;
        }
        if (is_object($response)) {
            return json_decode(json_encode($response), true) ?: [];
        }
        return [];
    }

    /**
     * WooCommerce batch endpoints return HTTP 200 even when individual rows
     * fail — each failed row carries an `error` object instead of throwing.
     * Report every failed row, and collect the SKUs that actually landed so the
     * caller can log a manifest of what shipped rather than only a count.
     *
     * A row that is simply *absent* from the response is not an error row and
     * would otherwise be counted as a success by omission, so the response is
     * also checked against the payload length: a short response means rows were
     * dropped, and the run must not go on to advance the watermark.
     *
     * @return array{failed: int, shipped: array<int, array{sku: string, woo_id: int|null}>}
     */
    private function reportBatchErrors($response, string $op, array $payload): array
    {
        $res = $this->toArray($response);

        if (empty($res) && !empty($payload)) {
            $this->wooErrors++;
            $this->error("  FAILED product {$op} batch — empty response from WooCommerce");
            Log::channel('sync')->error('Product batch returned empty response', ['op' => $op]);
            return ['failed' => count($payload), 'shipped' => []];
        }

        // Anything that is not a list of rows counts as zero rows returned —
        // count() on a scalar is fatal in PHP 8, and a response shaped
        // unexpectedly is precisely what the length check below is here for.
        $rows = $res[$op] ?? [];
        $rows = is_array($rows) ? $rows : [];

        if (count($rows) !== count($payload)) {
            $this->wooErrors++;
            $this->error("  FAILED product {$op} batch — WooCommerce returned " . count($rows) . ' row(s) for ' . count($payload) . ' sent');
            Log::channel('sync')->error('Product batch response is missing rows', [
                'op'       => $op,
                'sent'     => count($payload),
                'returned' => count($rows),
            ]);
        }

        $failed  = max(0, count($payload) - count($rows));
        $shipped = [];

        foreach ($rows as $i => $row) {
            if (!isset($row['error'])) {
                // Succeeded — record just enough to trace a SKU back to its run.
                $shipped[] = [
                    'sku'    => $payload[$i]['sku'] ?? ($row['sku'] ?? ''),
                    'woo_id' => !empty($row['id']) ? (int) $row['id'] : ($payload[$i]['id'] ?? null),
                ];
                continue;
            }
            $failed++;
            $this->wooErrors++;
            $name    = $payload[$i]['name'] ?? ($row['name'] ?? '');
            $sku     = $payload[$i]['sku'] ?? ($row['sku'] ?? '');
            $wooId   = !empty($row['id']) ? $row['id'] : ($payload[$i]['id'] ?? null);
            $code    = $row['error']['code'] ?? 'unknown';
            $message = $row['error']['message'] ?? 'Unknown error';

            $this->error("  FAILED product {$op} for {$name}" . ($sku !== '' ? " [SKU {$sku}]" : '') . " — {$message} [{$code}]");
            Log::channel('sync')->error('Product batch row failed', [
                'op'      => $op,
                'product' => $name,
                'sku'     => $sku,
                'woo_id'  => $wooId,
                'code'    => $code,
                'message' => $message,
            ]);
        }

        return ['failed' => $failed, 'shipped' => $shipped];
    }
}
