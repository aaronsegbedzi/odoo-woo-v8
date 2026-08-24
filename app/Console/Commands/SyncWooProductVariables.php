<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\OdooProduct;
use App\Http\Controllers\WooCategory;
use App\Http\Controllers\WooAttribute;
use App\Http\Controllers\WooProduct;
use Codexshaper\WooCommerce\Facades\Product;
use Codexshaper\WooCommerce\Facades\Variation;
use App\Http\Controllers\Controller;
use App\Http\Controllers\OdooCategory;
use App\Services\SyncWatermark;
use Illuminate\Support\Facades\Log;
use DateTime;

class SyncWooProductVariables extends Command
{
    protected $signature = 'woo:sync-woo-product-variables {id? : Odoo product template ID to sync on its own} {--images : Force image push for every product and variant} {--since= : Override the lookback start, "Y-m-d H:i:s" (UTC). Does not advance the watermark.}';
    protected $description = 'Synchronize variable products from Odoo to WooCommerce';

    /** Watermark key for this command — see App\Services\SyncWatermark. */
    private const WATERMARK = 'variable';

    /** Nominal schedule interval, used to flag windows that reveal skipped ticks. */
    private const NOMINAL_INTERVAL_MINUTES = 30;

    private bool $forceImages = false;
    private int $wooErrors = 0;

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
        $this->wooErrors  = 0;
        $this->imageCache = [];
        $this->imageHttp  = null;

        // Captured before any work so records modified *during* this run fall
        // inside the next run's window rather than into the gap between them.
        $runStartedAt = date('Y-m-d H:i:s');

        $this->info('OdooWoo Variable Products Sync — ' . $runStartedAt);

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

        Log::channel('sync')->info('Variable product sync started', [
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
            Log::channel('sync')->info('Variable sync window resolved', $window);

            $this->reportWindow($window, $since);
        }

        // ── 1. Fetch Odoo variable products ───────────────────────────────────
        try {
            $OdooProduct  = new OdooProduct();
            $OdooProducts = $OdooProduct->getVariableProducts(true, $since, (bool) $singleId, (int) $singleId);
        } catch (\Throwable $e) {
            $this->error('Failed to fetch Odoo variable products: ' . $e->getMessage());
            Log::channel('sync')->error('Failed to fetch Odoo variable products', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        if (empty($OdooProducts)) {
            if ($singleId) {
                $this->error("No Odoo variable product found with ID {$singleId}.");
                $this->line('It must satisfy all of: type=consu, is_favorite=1, x_brand set, image_1920 set, available_in_pos=true, has_configurable_attributes=true.');
                Log::channel('sync')->warning('Single variable sync — product not found', ['odoo_id' => $singleId]);
                return self::FAILURE;
            }

            $this->info('No Odoo variable products modified since ' . $since . '. Nothing to do.');
            Log::channel('sync')->info('Variable sync skipped — no products modified since', ['since' => $since]);

            // A quiet window is still a covered window. Skipping this would let
            // the window grow without bound across an idle night.
            $this->advanceWatermark($singleId, $runStartedAt);
            return self::SUCCESS;
        }

        $this->info('Odoo variable products fetched: ' . count($OdooProducts));
        Log::channel('sync')->info('Odoo variable products fetched', ['count' => count($OdooProducts)]);

        // ── 2. Fetch WooCommerce variable products ────────────────────────────
        try {
            $WooProduct  = new WooProduct();
            // Only id + meta_data are ever read from the variable catalogue
            // (classification by odoo_woo_id meta) — trims the response payload.
            $WooProducts = $WooProduct->getVariableProducts(['id', 'meta_data']);
        } catch (\Throwable $e) {
            $this->error('Failed to fetch WooCommerce variable products: ' . $e->getMessage());
            Log::channel('sync')->error('Failed to fetch WooCommerce variable products', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        $this->info('WooCommerce variable products fetched: ' . count($WooProducts));

        // ── 3. Categories ─────────────────────────────────────────────────────
        $Categories = $this->syncCategories();

        // ── 4. Brands ─────────────────────────────────────────────────────────
        $WooAttributeTerms = $this->syncBrands($OdooProducts);

        // ── 5. Attributes ─────────────────────────────────────────────────────
        $Attributes = $this->syncAttributes($OdooProducts);

        // ── 6. Classify products — O(n) via odoo_woo_id meta index ───────────
        $wooMetaIndex = [];
        foreach ($WooProducts as $woo) {
            $odooId = $this->getMetaValue($woo->meta_data);
            if ($odooId !== null) {
                $wooMetaIndex[$odooId] = $woo;
            }
        }

        $CreateProducts = [];
        $UpdateProducts = [];

        foreach ($OdooProducts as $odooProduct) {
            $odooId = (int) $odooProduct['id'];
            if (isset($wooMetaIndex[$odooId])) {
                $odooProduct['woo_id'] = $wooMetaIndex[$odooId]->id;
                $UpdateProducts[]      = $odooProduct;
            } else {
                $CreateProducts[] = $odooProduct;
            }
        }

        $this->info('To create: ' . count($CreateProducts) . ' | To update: ' . count($UpdateProducts));
        Log::channel('sync')->info('Variable product diff computed', [
            'create' => count($CreateProducts),
            'update' => count($UpdateProducts),
        ]);

        // Build O(1) lookup indexes
        $categoryIndex = [];
        foreach ($Categories as $cat) {
            $categoryIndex[$cat[0]] = $cat;
        }
        $brandIndex = [];
        foreach ($WooAttributeTerms as $term) {
            $brandIndex[$term[1]] = $term;
        }
        $attributeIndex = [];
        foreach ($Attributes as $attr) {
            $attributeIndex[$attr[1]] = $attr; // keyed by attribute name
        }

        // ── 7. Create products ────────────────────────────────────────────────
        if (!empty($CreateProducts)) {
            $this->runCreate($CreateProducts, $categoryIndex, $brandIndex, $attributeIndex, $controller);
        }

        // ── 8. Update products ────────────────────────────────────────────────
        if (!empty($UpdateProducts)) {
            $this->runUpdate($UpdateProducts, $categoryIndex, $brandIndex, $attributeIndex, $controller);
        }

        $elapsed = round(microtime(true) - $startTime, 1);
        if ($this->wooErrors > 0) {
            $this->error("Sync completed in {$elapsed}s with {$this->wooErrors} WooCommerce error(s) — see sync log for details.");
        } else {
            $this->info("Sync completed in {$elapsed}s.");
        }
        Log::channel('sync')->info('Variable product sync completed', [
            'elapsed_s' => $elapsed,
            'created'   => count($CreateProducts),
            'updated'   => count($UpdateProducts),
            'errors'    => $this->wooErrors,
            'odoo_id'   => $singleId,
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
        $OdooCategories = (new OdooCategory())->getCategories();
        $this->info('Odoo categories fetched: ' . count($OdooCategories));

        $WooCategories = (new WooCategory())->getCategories();
        $this->info('WooCommerce categories fetched: ' . count($WooCategories));

        $wooNames         = array_flip(array_column($WooCategories, 1));
        $CreateCategories = array_filter($OdooCategories, fn($c) => !isset($wooNames[$c[1]]));

        if (!empty($CreateCategories)) {
            $WooCat = new WooCategory();
            foreach ($CreateCategories as $cat) {
                // createCategory() swallows every throwable and returns false.
                // Left unchecked, the category stays missing and every product
                // in it is published under the fallback id — counting it as an
                // error holds the watermark so the next run re-pushes them.
                $createdId = $WooCat->createCategory($cat[1]);

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

        $wooByName = [];
        foreach ($WooCategories as $wc) {
            $wooByName[$wc[1]] = $wc;
        }
        $Categories = array_map(fn($oc) => array_merge($oc, $wooByName[$oc[1]] ?? []), $OdooCategories);

        if (!empty($CreateCategories)) {
            $catByOdooId = [];
            $catByName   = [];
            foreach ($Categories as $cat) {
                $catByOdooId[$cat[0]] = $cat;
                $catByName[$cat[1]]   = $cat;
            }
            foreach ($CreateCategories as $cat) {
                if ($cat[3]) {
                    $wooCatId     = $catByName[$cat[1]][5] ?? null;
                    $parentOdooId = $catByName[$cat[1]][4] ?? null;
                    $parentWooId  = isset($catByOdooId[$parentOdooId]) ? ($catByOdooId[$parentOdooId][5] ?? null) : null;
                    if ($wooCatId && $parentWooId) {
                        (new WooCategory())->setParentCatergory($wooCatId, $parentWooId);
                    }
                }
            }
            $WooCategories = (new WooCategory())->getCategories();
            $wooByName     = [];
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
        $brandId    = config('app.woo_brand_attribute_id');
        $OdooBrands = [];
        foreach ($OdooProducts as $p) {
            if (!empty($p['brand'])) {
                // Same normalization as the simple sync, so a brand with a
                // curly apostrophe matches the same Woo term in both commands.
                $OdooBrands[strtoupper($this->cleanEncoding($p['brand']))] = true;
            }
        }
        $OdooBrands = array_keys($OdooBrands);
        $this->info('Odoo brands: ' . count($OdooBrands));

        $WooAttribute      = new WooAttribute();
        $WooAttributeTerms = $WooAttribute->getAttributeTerms($brandId);
        $this->info('WooCommerce brands: ' . count($WooAttributeTerms));

        $wooTermNames = array_flip(array_column($WooAttributeTerms, 1));
        $CreateTerms  = array_filter($OdooBrands, fn($b) => !isset($wooTermNames[$b]));

        if (!empty($CreateTerms)) {
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

    // ─── Attribute sync ───────────────────────────────────────────────────────

    private function syncAttributes(array $OdooProducts): array
    {
        $OdooAttributeNames = [];
        foreach ($OdooProducts as $p) {
            foreach ($p['variants'] ?? [] as $v) {
                $OdooAttributeNames[$v['att_name']] = true;
            }
        }

        $WooAttribute  = new WooAttribute();
        $WooAttributes = $WooAttribute->getAttributes();
        $this->info('WooCommerce attributes fetched: ' . count($WooAttributes));

        $wooAttrNames     = array_flip(array_column($WooAttributes, 1));
        $CreateAttributes = array_filter(array_keys($OdooAttributeNames), fn($n) => !isset($wooAttrNames[$n]));

        if (!empty($CreateAttributes)) {
            foreach ($CreateAttributes as $name) {
                // A missing variation attribute is the worst of the three: the
                // payload carries a null attribute id, which either fails the
                // whole product row or strips the variation attribute from it.
                if (!$WooAttribute->createAttribute($name)) {
                    $this->wooErrors++;
                    $this->error("  FAILED to create attribute: {$name}");
                    Log::channel('sync')->error('Attribute creation failed', ['name' => $name]);
                    continue;
                }

                $this->info("  Created attribute: {$name}");
                Log::channel('sync')->info('Attribute created', ['name' => $name]);
            }
            $WooAttributes = (new WooAttribute())->getAttributes();
        }

        return $WooAttributes;
    }

    // ─── Create variable products ─────────────────────────────────────────────

    private function runCreate(array $products, array $categoryIndex, array $brandIndex, array $attributeIndex, Controller $controller): void
    {
        $total   = count($products);
        $brandId = config('app.woo_brand_attribute_id');
        $this->info("Creating {$total} variable products.");
        Log::channel('sync')->info('Variable create started', ['count' => $total]);

        foreach ($products as $i => $p) {
            $num   = $i + 1;
            $cat   = $categoryIndex[$p['cat'][0] ?? 0] ?? null;
            $brand = $brandIndex[strtoupper($this->cleanEncoding($p['brand'] ?? ''))] ?? null;
            $attr  = $attributeIndex[$p['variants'][0]['att_name'] ?? ''] ?? null;
            $this->warnRelationalMisses($p, $cat, $brand, $attr);

            // Verified once per product, then reused as the variant fallback.
            $parentImage = $this->imageUsable($p['image'] ?? null) ? $p['image'] : '';
            if ($parentImage === '') {
                $this->warn("  Parent image missing for {$p['name']} — creating without it.");
                Log::channel('sync')->warning('Parent image missing', ['product' => $p['name'], 'url' => $p['image'] ?? null]);
            }

            $data = [
                'name'              => $p['name'],
                'status'            => 'publish',
                'type'              => 'variable',
                'description'       => $controller->formatDescription($p['description'], $p['directions'], $p['ingredients'], $p['name']),
                'short_description' => $controller->truncateString($p['description']),
                'categories'        => [['id' => $cat[5] ?? 15]],
                'attributes'        => [
                    [
                        'id'        => $brandId,
                        'name'      => 'Brand',
                        'visible'   => true,
                        'variation' => false,
                        'options'   => [$brand[1] ?? ''],
                    ],
                    [
                        'id'        => $attr[0] ?? null,
                        'name'      => $attr[1] ?? '',
                        'position'  => 0,
                        'visible'   => false,
                        'variation' => true,
                        'options'   => array_column($p['variants'], 'att_value'),
                    ],
                ],
                'meta_data' => [
                    ['key' => 'odoo_woo_id', 'value' => (string) $p['id']],
                ],
            ];

            if ($parentImage !== '') {
                $data['images'] = [['src' => $parentImage]];
            }

            try {
                $created   = Product::create($data);
                $createdId = $this->extractId($created);
                if (!$createdId) {
                    throw new \RuntimeException('WooCommerce returned no product ID: ' . json_encode($created));
                }
                $this->info("  Created {$num}/{$total}: {$p['name']}");
                Log::channel('sync')->info('Variable product created', ['name' => $p['name'], 'odoo_id' => $p['id'], 'woo_id' => $createdId]);
            } catch (\Throwable $e) {
                $this->wooErrors++;
                $this->error("  FAILED to create {$num}/{$total}: {$p['name']} — " . $e->getMessage());
                Log::channel('sync')->error('Variable product create failed', [
                    'name'  => $p['name'],
                    'error' => $e->getMessage(),
                ]);
                sleep($controller->wooSleepSeconds());
                continue;
            }

            sleep($controller->wooSleepSeconds());

            $variants = $this->buildVariantCreatePayload($p['variants'], $attr, $controller, $parentImage, $p['name']);

            try {
                if (!empty($variants)) {
                    $response = Variation::batch($createdId, ['create' => $variants]);
                    $failed   = $this->reportVariantBatchErrors($response, $variants, [], $p['name']);
                    Log::channel('sync')->info('Variants created', [
                        'product' => $p['name'],
                        'count'   => count($variants) - $failed,
                        'failed'  => $failed,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->wooErrors++;
                $this->error("  FAILED to create variants for {$p['name']} — " . $e->getMessage());
                Log::channel('sync')->error('Variant create failed', [
                    'product' => $p['name'],
                    'error'   => $e->getMessage(),
                ]);
            }

            // No sleep after the last product — there is nothing left to pace.
            if ($num < $total) {
                sleep($controller->wooSleepSeconds());
            }
        }

        $this->info('Variable create job completed.');
    }

    // ─── Update variable products ─────────────────────────────────────────────

    private function runUpdate(array $products, array $categoryIndex, array $brandIndex, array $attributeIndex, Controller $controller): void
    {
        $total   = count($products);
        $brandId = config('app.woo_brand_attribute_id');
        $now     = new DateTime();
        $this->info("Updating {$total} variable products.");
        Log::channel('sync')->info('Variable update started', ['count' => $total]);

        foreach ($products as $i => $p) {
            $num   = $i + 1;
            $cat   = $categoryIndex[$p['cat'][0] ?? 0] ?? null;
            $brand = $brandIndex[strtoupper($this->cleanEncoding($p['brand'] ?? ''))] ?? null;
            $attr  = $attributeIndex[$p['variants'][0]['att_name'] ?? ''] ?? null;
            $this->warnRelationalMisses($p, $cat, $brand, $attr);

            $data = [
                'name'              => $p['name'],
                'status'            => 'publish',
                'type'              => 'variable',
                'description'       => $controller->formatDescription($p['description'], $p['directions'], $p['ingredients'], $p['name']),
                'short_description' => $controller->truncateString($p['description']),
                'categories'        => [['id' => $cat[5] ?? 15]],
                'attributes'        => [
                    [
                        'id'        => $brandId,
                        'name'      => 'Brand',
                        'visible'   => true,
                        'variation' => false,
                        'options'   => [$brand[1] ?? ''],
                    ],
                    [
                        'id'        => $attr[0] ?? null,
                        'name'      => $attr[1] ?? '',
                        'position'  => 0,
                        'visible'   => false,
                        'variation' => true,
                        'options'   => array_column($p['variants'], 'att_value'),
                    ],
                ],
            ];

            // Verified once per product, then reused as the variant fallback.
            $parentImage = $this->imageUsable($p['image'] ?? null) ? $p['image'] : '';

            // Push image only if updated within the last hour — unless forced (single-product mode)
            $pushImage = $this->forceImages;
            if (!$pushImage && !empty($p['x_image_last_updated_on'])) {
                $diff      = $now->diff(new DateTime($p['x_image_last_updated_on']));
                $pushImage = $diff->days === 0 && $diff->h < 1;
            }

            if ($pushImage) {
                if ($parentImage !== '') {
                    $data['images'] = [['src' => $parentImage]];
                } else {
                    $this->warn("  Parent image missing for {$p['name']} — leaving the existing Woo image alone.");
                    Log::channel('sync')->warning('Parent image missing', ['product' => $p['name'], 'url' => $p['image'] ?? null]);
                }
            }

            try {
                $updated = Product::update($p['woo_id'], $data);
                if (!$this->extractId($updated)) {
                    throw new \RuntimeException('WooCommerce returned no product ID: ' . json_encode($updated));
                }
                $this->info("  Updated {$num}/{$total}: {$p['name']}");
                Log::channel('sync')->info('Variable product updated', ['name' => $p['name'], 'woo_id' => $p['woo_id']]);
            } catch (\Throwable $e) {
                $this->wooErrors++;
                $this->error("  FAILED to update {$num}/{$total}: {$p['name']} — " . $e->getMessage());
                Log::channel('sync')->error('Variable product update failed', [
                    'name'   => $p['name'],
                    'woo_id' => $p['woo_id'],
                    'error'  => $e->getMessage(),
                ]);
                sleep($controller->wooSleepSeconds());
                continue;
            }

            sleep($controller->wooSleepSeconds());

            // Sync variants — only id + meta_data are read for the diff.
            $WooVariations = (new WooProduct())->getVariations($p['woo_id'], ['id', 'meta_data']);

            $wooVariantIndex = [];
            foreach ($WooVariations as $wv) {
                $odooId = $this->getMetaValue($wv->meta_data);
                if ($odooId !== null) {
                    $wooVariantIndex[$odooId] = $wv;
                }
            }

            $CreateVariants = [];
            $UpdateVariants = [];

            foreach ($p['variants'] as $v) {
                $vid = (int) $v['id'];
                if (isset($wooVariantIndex[$vid])) {
                    $v['woo_id']      = $wooVariantIndex[$vid]->id;
                    $UpdateVariants[] = $v;
                } else {
                    $CreateVariants[] = $v;
                }
            }

            $batchCreate = $this->buildVariantCreatePayload($CreateVariants, $attr, $controller, $parentImage, $p['name']);
            $batchUpdate = $this->buildVariantUpdatePayload($UpdateVariants, $controller, $now, $parentImage, $p['name'], $p['x_image_last_updated_on'] ?? null);

            try {
                if (!empty($batchCreate) || !empty($batchUpdate)) {
                    $response = Variation::batch($p['woo_id'], ['create' => $batchCreate, 'update' => $batchUpdate]);
                    $failed   = $this->reportVariantBatchErrors($response, $batchCreate, $batchUpdate, $p['name']);
                    Log::channel('sync')->info('Variants synced', [
                        'product' => $p['name'],
                        'created' => count($batchCreate),
                        'updated' => count($batchUpdate),
                        'failed'  => $failed,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->wooErrors++;
                $this->error("  FAILED variant sync for {$p['name']} — " . $e->getMessage());
                Log::channel('sync')->error('Variant sync failed', [
                    'product' => $p['name'],
                    'error'   => $e->getMessage(),
                ]);
            }

            // No sleep after the last product — there is nothing left to pace.
            if ($num < $total) {
                sleep($controller->wooSleepSeconds());
            }
        }

        $this->info('Variable update job completed.');
    }

    // ─── Image resolution ─────────────────────────────────────────────────────

    /**
     * Does this image actually exist on the Odoo image host? WooCommerce
     * side-loads images by URL, so a 404 fails the whole variant row with
     * `woocommerce_variation_image_upload_error`. Checking first is cheaper
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

    /**
     * Resolve the image for a variant: its own image, else the parent product
     * image, else none at all (the variant is still created/updated, just
     * without an image).
     *
     * Ownership comes from Odoo (`has_own_image`, derived from
     * image_variant_1920), never from whether the image URL happens to resolve.
     * The image proxy answers `<id>.jpg` for whichever model it finds first, and
     * template ids and variant ids are overlapping Odoo sequences — so a 200
     * proves only that *some* record has that id. Trusting it is what served
     * template 3844's image to variant 3844.
     *
     * Variants that inherit the parent image are given the parent's own URL
     * rather than their variant URL. Both return the same bytes, but reusing the
     * parent URL keeps WooCommerce from side-loading a duplicate copy of that
     * image for every inheriting variant.
     */
    private function resolveVariantImage(array $v, string $parentImage, string $productName): ?string
    {
        $sku         = $v['sku'] ?? '';
        $hasOwnImage = !empty($v['has_own_image']) && !empty($v['image']);

        if ($hasOwnImage) {
            // Only a definitive 404 triggers the fallback; inconclusive keeps the
            // original URL and lets WooCommerce decide, as it did before.
            if ($this->imageUsable($v['image'])) {
                return $v['image'];
            }

            $this->warn("  Variant image could not be fetched [SKU {$sku}] — falling back to parent image.");
            Log::channel('sync')->warning('Variant has its own image in Odoo but the URL did not resolve', [
                'product' => $productName,
                'sku'     => $sku,
                'variant' => $v['image'],
            ]);
        }

        if ($parentImage !== '') {
            if (!$hasOwnImage) {
                // Inheriting the template image is normal Odoo behaviour, not a
                // fault — record it without shouting about it.
                Log::channel('sync')->info('Variant has no image of its own, using parent image', [
                    'product' => $productName,
                    'sku'     => $sku,
                    'parent'  => $parentImage,
                ]);
            }
            return $parentImage;
        }

        $this->warn("  No variant image and no parent image [SKU {$sku}] — skipping image.");
        Log::channel('sync')->warning('Variant and parent images both unavailable, image skipped', [
            'product' => $productName,
            'sku'     => $sku,
            'variant' => $v['image'] ?? null,
        ]);
        return null;
    }

    // ─── Variant payload builders ─────────────────────────────────────────────

    private function buildVariantCreatePayload(array $variants, ?array $attr, Controller $controller, string $parentImage = '', string $productName = ''): array
    {
        $payload = [];
        foreach ($variants as $v) {
            $entry = [
                'status'         => 'publish',
                'regular_price'  => (string) $v['price'],
                'stock_status'   => $v['qty'] > 0 ? 'instock' : 'outofstock',
                'stock_quantity' => max(0, (int) $v['qty']),
                'manage_stock'   => true,
                'sku'            => $v['sku'],
                'attributes'     => [[
                    'id'     => $attr[0] ?? null,
                    'option' => $v['att_value'],
                ]],
                'meta_data' => [
                    ['key' => 'odoo_woo_id',  'value' => (string) $v['id']],
                    ['key' => '_woosea_gtin', 'value' => (string) $v['gtin']],
                ],
            ];

            $image = $this->resolveVariantImage($v, $parentImage, $productName);
            if ($image !== null) {
                $entry['image'] = ['src' => $image];
            }

            $payload[] = $entry;
        }
        return $payload;
    }

    private function buildVariantUpdatePayload(array $variants, Controller $controller, DateTime $now, string $parentImage = '', string $productName = '', $parentImageUpdatedAt = null): array
    {
        $payload = [];
        foreach ($variants as $v) {
            $entry = [
                'id'             => $v['woo_id'],
                'status'         => 'publish',
                'regular_price'  => (string) $v['price'],
                'stock_status'   => $v['qty'] > 0 ? 'instock' : 'outofstock',
                'stock_quantity' => max(0, (int) $v['qty']),
                'manage_stock'   => true,
                'meta_data'      => [
                    ['key' => '_woosea_gtin', 'value' => (string) $v['gtin']],
                ],
            ];

            $pushImage = $this->forceImages;
            if (!$pushImage) {
                // A variant that inherits the parent image has no image timestamp
                // of its own, so it has to follow the parent's — otherwise a
                // re-uploaded template image never reaches its variants.
                $stamp = !empty($v['has_own_image'])
                    ? ($v['x_image_last_updated_on'] ?? null)
                    : $parentImageUpdatedAt;

                if (!empty($stamp)) {
                    $diff      = $now->diff(new DateTime($stamp));
                    $pushImage = $diff->days === 0 && $diff->h < 1;
                }
            }

            if ($pushImage) {
                $image = $this->resolveVariantImage($v, $parentImage, $productName);
                if ($image !== null) {
                    $entry['image'] = ['src' => $image];
                }
            }

            $payload[] = $entry;
        }
        return $payload;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function cleanEncoding(string $value): string
    {
        $value = str_replace(["'", "'", "´", "`"], "'", $value);
        $enc   = mb_detect_encoding($value, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true) ?: 'UTF-8';
        return mb_convert_encoding($value, 'UTF-8', $enc);
    }

    /**
     * A relational lookup that misses falls back silently (default category,
     * empty brand, null attribute) — surface every miss so it can be fixed on
     * the Odoo or Woo side instead of going unnoticed.
     */
    private function warnRelationalMisses(array $p, ?array $cat, ?array $brand, ?array $attr): void
    {
        if ($cat === null) {
            $this->warn("  Category not matched for {$p['name']} — using default category.");
            Log::channel('sync')->warning('Category not matched, default used', [
                'product'          => $p['name'],
                'odoo_category_id' => $p['cat'][0] ?? null,
                'odoo_category'    => $p['cat'][1] ?? null,
                'fallback_woo_id'  => 15,
            ]);
        }
        if ($brand === null) {
            $this->warn("  Brand term not matched for {$p['name']} — pushing empty brand.");
            Log::channel('sync')->warning('Brand term not matched, empty brand pushed', [
                'product' => $p['name'],
                'brand'   => $p['brand'] ?? null,
            ]);
        }
        if ($attr === null) {
            $this->warn("  Variation attribute not matched for {$p['name']} — payload will carry a null attribute id.");
            Log::channel('sync')->warning('Variation attribute not matched', [
                'product'  => $p['name'],
                'att_name' => $p['variants'][0]['att_name'] ?? null,
            ]);
        }
    }

    private function getMetaValue(array $meta): ?int
    {
        foreach ($meta as $m) {
            if ($m->key === 'odoo_woo_id') {
                return (int) $m->value;
            }
        }
        return null;
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
     * Pull the `id` from a Woo API response, whatever shape it arrives in.
     */
    private function extractId($response): ?int
    {
        $data = $this->toArray($response);
        return !empty($data['id']) ? (int) $data['id'] : null;
    }

    /**
     * WooCommerce batch endpoints return HTTP 200 even when individual rows
     * fail — each failed row carries an `error` object instead of throwing.
     * Report every failed row and return how many failed.
     *
     * A row that is simply *absent* from the response is not an error row and
     * would otherwise be counted as a success by omission, so each op's
     * response is also checked against its payload length: a short response
     * means rows were dropped, and the run must not advance the watermark.
     */
    private function reportVariantBatchErrors($response, array $createPayload, array $updatePayload, string $productName): int
    {
        $res = $this->toArray($response);

        if (empty($res) && (!empty($createPayload) || !empty($updatePayload))) {
            $this->wooErrors++;
            $this->error("  FAILED variant batch for {$productName} — empty response from WooCommerce");
            Log::channel('sync')->error('Variant batch returned empty response', ['product' => $productName]);
            return count($createPayload) + count($updatePayload);
        }

        $failed = 0;

        foreach (['create' => $createPayload, 'update' => $updatePayload] as $op => $payload) {
            // Anything that is not a list of rows counts as zero rows returned —
            // count() on a scalar is fatal in PHP 8, and a response shaped
            // unexpectedly is precisely what the length check below is here for.
            $rows = $res[$op] ?? [];
            $rows = is_array($rows) ? $rows : [];

            if (count($rows) !== count($payload)) {
                $failed += max(0, count($payload) - count($rows));
                $this->wooErrors++;
                $this->error("  FAILED variant {$op} for {$productName} — WooCommerce returned " . count($rows) . ' row(s) for ' . count($payload) . ' sent');
                Log::channel('sync')->error('Variant batch response is missing rows', [
                    'product'  => $productName,
                    'op'       => $op,
                    'sent'     => count($payload),
                    'returned' => count($rows),
                ]);
            }

            foreach ($rows as $i => $row) {
                if (!isset($row['error'])) {
                    continue;
                }
                $failed++;
                $this->wooErrors++;
                $sku     = $payload[$i]['sku'] ?? ($row['sku'] ?? '');
                $wooId   = !empty($row['id']) ? $row['id'] : ($payload[$i]['id'] ?? null);
                $code    = $row['error']['code'] ?? 'unknown';
                $message = $row['error']['message'] ?? 'Unknown error';

                $this->error("  FAILED variant {$op} for {$productName}" . ($sku !== '' ? " [SKU {$sku}]" : '') . " — {$message} [{$code}]");
                Log::channel('sync')->error('Variant batch row failed', [
                    'product' => $productName,
                    'op'      => $op,
                    'sku'     => $sku,
                    'woo_id'  => $wooId,
                    'code'    => $code,
                    'message' => $message,
                ]);
            }
        }

        return $failed;
    }
}
