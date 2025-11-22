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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use DateTime;

class SyncWooProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'woo:sync {--images}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize Products in WooCommerce';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('OdooWoo Simple Products Synchronization Job - ' . date("F j, Y, g:i a"));
        $syncImages = $this->option('images');
        $controller = new Controller();

        $dateTime = date("Y-m-d h:i:s", strtotime("-1 hour"));
        $this->info('Date Time: ' . $dateTime);

        // Cache::forget('odoo_products');
        // Cache::forget('woo_products');

        // Get the products from Odoo.
        $OdooProduct = new OdooProduct();
        $OdooProducts = $OdooProduct->getProducts(true, $dateTime);
        $this->info('Odoo Simple Products Fetched: ' . count($OdooProducts));
        // $OdooProducts = Cache::remember(
        //     'odoo_products',
        //     now()->addMinutes(60),
        //     fn() => $OdooProduct->getProducts(false, $dateTime)
        // );
        $this->info('Odoo Simple Products Fetched (cached): ' . count($OdooProducts));

        // Get the products from WooCommerce.
        $WooProduct = new WooProduct();
        $WooProducts = $WooProduct->getProducts();
        $this->info('Woo Simple Products Fetched: ' . count($WooProducts));
        // $WooProducts = Cache::remember(
        //     'woo_products',
        //     now()->addMinutes(60),
        //     fn() => $WooProduct->getProducts()
        // );
        $this->info('Woo Simple Products Fetched (cached): ' . count($WooProducts));

        //CATEGORIES/////////////////////////////////////////////////////////////////////////////////////////
        // Get the categories from Odoo.

        $OdooCategory = new OdooCategory();
        $OdooCategories = $OdooCategory->getCategories();
        $this->info('Odoo Categories Fetched: ' . count($OdooCategories));
        // dd($OdooCategories);

        // Get the categories from WooCommerce.
        $WooCategory = new WooCategory();
        $WooCategories = $WooCategory->getCategories();
        $this->info('Woo Categories Fetched: ' . count($WooCategories));

        // Create Categories if not exist in WooCommerce.
        $array1_ids = array_column($OdooCategories, 1);
        $array2_ids = array_column($WooCategories, 1);
        $diff = array_diff($array1_ids, $array2_ids);
        // Filter array1 based on differences
        $CreateCategories = array_filter($OdooCategories, function ($item) use ($diff) {
            return in_array($item[1], $diff);
        });

        if (count($CreateCategories)) {
            $this->info('Creating ' . count($CreateCategories) . ' Categories in Woo.');
            foreach ($CreateCategories as $CreateCategory) {
                $CreateCategory[5] = $WooCategory->createCategory($CreateCategory[1]);
                $this->info('Created Category: ' . $CreateCategory[1]);
            }
            // Get the categories from WooCommerce.
            $WooCategory = new WooCategory();
            $WooCategories = $WooCategory->getCategories();
        }

        // Merge Odoo and WooCommerce categories.
        $Categories = array_map(function ($item1) use ($WooCategories) {
            $matchingItems = array_filter($WooCategories, function ($item2) use ($item1) {
                return $item2[1] === $item1[1];
            });
            return array_merge($item1, ...$matchingItems);
        }, $OdooCategories);

        if (count($CreateCategories)) {
            $this->info('Applying Parent Structure for ' . count($CreateCategories) . ' Categories in Woo.');
            foreach ($CreateCategories as $CreateCategory) {
                if ($CreateCategory[3] == true) {
                    $woo_id = $this->searchArray(1, $CreateCategory[1], 5, $Categories);
                    $parent_odoo_id = $this->searchArray(1, $CreateCategory[1], 4, $Categories);
                    $parent_id = $this->searchArray(0, $parent_odoo_id, 5, $Categories);
                    $WooCategory->setParentCatergory($woo_id, $parent_id);
                }
            }
            // Get the categories from WooCommerce.
            $WooCategory = new WooCategory();
            $WooCategories = $WooCategory->getCategories();
        }
        // dd($Categories);
        //CATEGORIES////////////////////////////////////////////////////////////////////////////////////////

        //BRANDS///////////////////////////////////////////////////////////////////////////////////////////
        // Get the Brand from Odoo.
        $OdooBrands = [];
        foreach ($OdooProducts as $OdooProduct) {
            if (!empty($OdooProduct['brand'])) {
                $brand = $this->cleanEncoding($OdooProduct['brand']);
                $OdooBrands[] = strtoupper($brand);
            }
        }

        $OdooBrands = array_values(array_map("unserialize", array_unique(array_map("serialize", $OdooBrands))));
        $this->info('Odoo Brands Fetched: ' . count($OdooBrands));

        // Get the Brand from WooCommerce.
        $WooAttribute = new WooAttribute();
        $WooAttributeTerms = $WooAttribute->getAttributeTerms(env('WOOCOMMERCE_BRAND_ID', ''));
        $this->info('Woo Brands Fetched: ' . count($WooAttributeTerms));

        // Create Brands if not exist in WooCommerce.
        $CreateTerms = array_diff($OdooBrands, array_column($WooAttributeTerms, 1));

        if (count($CreateTerms) > 0) {
            $this->info('Creating ' . count($CreateTerms) . ' Brands in Woo.');
            foreach ($CreateTerms as $CreateTerm) {
                $WooAttribute->createAttributeTerm(env('WOOCOMMERCE_BRAND_ID', ''), $CreateTerm);
                $this->info('Created Brand: ' . $CreateTerm);
            }
            // Get the Brand from WooCommerce.
            $WooAttribute = new WooAttribute();
            $WooAttributeTerms = $WooAttribute->getAttributeTerms(env('WOOCOMMERCE_BRAND_ID', ''));
        }
        //BRANDS//////////////////////////////////////////////////////////////////////////////////////////

        $CreateProducts = [];
        $UpdateProducts = [];
        $DeleteProducts = [];

        // Find products to create or update
        foreach ($OdooProducts as $OdooProduct) {
            $update = false;
            foreach ($WooProducts as $WooProduct) {
                if ($OdooProduct['sku'] == $WooProduct->sku) {
                    $OdooProduct['woo_id'] = $WooProduct->id;
                    $UpdateProducts[] = $OdooProduct;
                    $update = true;
                    break;
                }
            }
            if ($update == false) {
                $CreateProducts[] = $OdooProduct;
            }
        }

        // Find products to delete
        foreach ($WooProducts as $WooProduct) {
            $found = false;
            foreach ($OdooProducts as $OdooProduct) {
                if ($WooProduct->sku == $OdooProduct['sku']) {
                    $found = true;
                    break;
                }
            }
            if ($found == false) {
                $DeleteProducts[] = $WooProduct;
            }
        }

        $this->info('No. Products To Create: ' . count($CreateProducts));
        $this->info('No. Products To Update: ' . count($UpdateProducts));
        // $this->info('No. Products To Trash: ' . count($DeleteProducts));

        $BatchCreate = [];
        $BatchUpdate = [];

        if (count($CreateProducts) > 0) {
            $j = 0;
            $this->info('Products Create Job Initiated');
            foreach ($CreateProducts as $CreateProduct) {

                $searchValue = $CreateProduct['cat'][0];
                $index = null;
                foreach ($Categories as $key => $element) {
                    if ($element[0] === $searchValue) {
                        $index = $key;
                        break;
                    }
                }

                $searchValue = $CreateProduct['brand'];
                // $this->info($CreateProduct['brand']);
                $index2 = null;
                foreach ($WooAttributeTerms as $key => $element) {
                    if ($element[1] === $searchValue) {
                        $index2 = $key;
                        break;
                    }
                }

                $BatchCreate[$j] = [
                    'name' => $CreateProduct['name'],
                    'status' => 'publish',
                    'type' => 'simple',
                    'regular_price' => (string) $CreateProduct['price'],
                    'sku' => $CreateProduct['sku'],
                    'manage_stock' => true,
                    'stock_quantity' => $CreateProduct['qty'] > 0 ? $CreateProduct['qty'] : 0,
                    'stock_status' => $CreateProduct['qty'] > 0 ? 'instock' : 'outofstock',
                    'description' => $controller->formatDescription($CreateProduct['description'], $CreateProduct['directions'], $CreateProduct['ingredients'], $CreateProduct['name']),
                    'short_description' => $this->truncateString($CreateProduct['description']),
                    'categories' => [
                        [
                            'id' => isset($Categories[$index][5]) ? $Categories[$index][5] : 15
                        ]
                    ],
                    'images' => [
                        [
                            'src' => $CreateProduct['image']
                        ]
                    ],
                    'attributes' => [
                        [
                            'id' => env('WOOCOMMERCE_BRAND_ID', ''),
                            'name' => 'Brand',
                            'visible' => true,
                            'variation' => false,
                            'options' => [
                                $WooAttributeTerms[$index2][1] ?? ''
                            ]
                        ]
                    ],
                    'meta_data' => [
                        [
                            'key' => 'odoo_woo_id',
                            'value' => (string) $CreateProduct['id']
                        ],
                        [
                            "key" => "_woosea_gtin",
                            "value" => (string) $CreateProduct['gtin']
                        ]
                    ]
                ];

                if ($controller->mycredEnabled()) {
                    $BatchCreate[$j]['meta_data'][] = array(
                        'key' => 'mycred_reward',
                        'value' => array(
                            'mycred_default' =>  $controller->mycredDefaultPoints()
                        )
                    );
                }

                $j++;
            }
            $batchSize = $controller->wooProductsPerBatch();
            $i = 1;
            $chunks = array_chunk($BatchCreate, $batchSize);
            foreach ($chunks as $chunk) {
                try {
                    $this->info('Batch ' . $i . ': ' . date("F j, Y, g:i a"));
                    $_batch = Product::batch(['create' => $chunk]);
                    // $this->info($_batch);
                    // Log::info('Woo Batch Response', [
                    //     'batch' => $_batch
                    // ]);
                    $this->info('COMPLETED Batch ' . $i . ' @ ' . date("F j, Y, g:i a"));
                } catch (\Exception $e) {
                    $this->info('FAILED Batch ' . $i . ' - REASON: ' . $e->getMessage());
                }
                $i++;
                sleep($controller->wooSleepSeconds());
            }
            $this->info('Products Create Job Completed');
        }

        if (count($UpdateProducts) > 0) {
            $j = 0;
            $this->info('Product Update Job Initiated');
            foreach ($UpdateProducts as $UpdateProduct) {

                $searchValue = $UpdateProduct['cat'][0];
                $index = null;
                foreach ($Categories as $key => $element) {
                    if ($element[0] === $searchValue) {
                        $index = $key;
                        break;
                    }
                }

                // $this->info($UpdateProduct['brand']);
                $searchValue = $UpdateProduct['brand'];
                $index2 = null;
                foreach ($WooAttributeTerms as $key => $element) {
                    if ($element[1] === $searchValue) {
                        $index2 = $key;
                        break;
                    }
                }

                $BatchUpdate[$j] = [
                    'id' => $UpdateProduct['woo_id'],
                    'name' => $UpdateProduct['name'],
                    'status' => 'publish',
                    'type' => 'simple',
                    'regular_price' => (string) $UpdateProduct['price'],
                    'manage_stock' => true,
                    'stock_quantity' => $UpdateProduct['qty'] > 0 ? $UpdateProduct['qty'] : 0,
                    'stock_status' => $UpdateProduct['qty'] > 0 ? 'instock' : 'outofstock',
                    'description' => $controller->formatDescription($UpdateProduct['description'], $UpdateProduct['directions'], $UpdateProduct['ingredients'], $UpdateProduct['name']),
                    'short_description' => $this->truncateString($UpdateProduct['description']),
                    'categories' => [
                        [
                            'id' => isset($Categories[$index][5]) ? $Categories[$index][5] : 15
                        ]
                    ],
                    'attributes' => [
                        [
                            'id' => env('WOOCOMMERCE_BRAND_ID', ''),
                            'name' => 'Brand',
                            'visible' => true,
                            'variation' => false,
                            'options' => [$WooAttributeTerms[$index2][1]]
                        ],
                    ],
                    'meta_data' => [
                        [
                            'key' => 'odoo_woo_id',
                            'value' => (string) $UpdateProduct['id']
                        ],
                        [
                            "key" => "_woosea_gtin",
                            "value" => (string) $UpdateProduct['gtin']
                        ]
                    ]
                ];

                if (!empty($UpdateProduct['x_image_last_updated_on'])) {
                    $lastUpdatedTime = new DateTime($UpdateProduct['x_image_last_updated_on']);
                    $currentTime = new DateTime();
                    $interval = $currentTime->diff($lastUpdatedTime);
                    if ($interval->h < 1 && $interval->days === 0) {
                        $BatchUpdate[$j]['images'] = [
                            [
                                'src' => $UpdateProduct['image']
                            ]
                        ];
                    }
                }

                if ($controller->mycredEnabled()) {
                    $BatchUpdate[$j]['meta_data'][] = array(
                        'key' => 'mycred_reward',
                        'value' => array(
                            'mycred_default' =>  $controller->mycredDefaultPoints()
                        )
                    );
                }

                $j++;
            }
            $batchSize = $controller->wooProductsPerBatch();
            $i = 1;
            $chunks = array_chunk($BatchUpdate, $batchSize);
            foreach ($chunks as $chunk) {
                try {
                    $this->info('Batch ' . $i . ': ' . date("F j, Y, g:i a"));
                    $_batch = Product::batch(['update' => $chunk]);
                    // Log::info('Woo Batch Response', [
                    //     'batch' => $_batch
                    // ]);
                    $this->info('COMPLETED Batch ' . $i . ' @ ' . date("F j, Y, g:i a"));
                } catch (\Exception $e) {
                    $this->info('FAILED Batch ' . $i . ' - REASON: ' . $e->getMessage());
                }
                $i++;
                sleep($controller->wooSleepSeconds());
            }
            $this->info('Product Update Job Completed');
        }

        // if (count($DeleteProducts) > 0) {
        //     foreach ($DeleteProducts as $DeleteProduct) {
        //         $this->info('Trashing Product: ' . $DeleteProduct->name);
        //         $product = Product::delete($DeleteProduct->id, ['force' => false]);
        //     }
        // }

        $this->info('OdooWoo Synchronization Completed. Have Fun :)');
    }

    private function cutToEndOfLastSentence($text)
    {
        // Find the last occurrence of a period, question mark, or exclamation mark
        $lastSentenceEnd = max(strrpos($text, '.'), strrpos($text, '?'), strrpos($text, '!'));

        // If no valid sentence end is found, return the original text
        if ($lastSentenceEnd === false) {
            return $text;
        }

        // Cut the text to the end of the last sentence
        $cutText = substr($text, 0, $lastSentenceEnd + 1); // Include the sentence end punctuation

        return $cutText;
    }

    private function trimSentences($inputText)
    {
        // Split the input text into paragraphs
        $paragraphs = preg_split('/\n\s*\n/', $inputText);

        // Process each paragraph
        foreach ($paragraphs as &$paragraph) {
            // Split the paragraph into sentences
            $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z])/', $paragraph);

            // Trim each sentence
            foreach ($sentences as &$sentence) {
                $sentence = trim($sentence);
            }

            // Join the sentences back into the paragraph
            $paragraph = implode(' ', $sentences);
        }

        // Join the paragraphs back into the text
        $cleanedText = implode("\n\n", $paragraphs);

        return $cleanedText;
    }

    private function truncateString($inputText, $limit = 250)
    {

        $inputText = preg_replace('/[^\S\r\n]+/', ' ', $inputText);
        $inputText = preg_replace('/^(?=[^\s\r\n])\s+/m', '', $inputText);
        $paragraphs = preg_split('/\n\s*\n/', $inputText, 2, PREG_SPLIT_NO_EMPTY);

        // Check if there's at least one paragraph
        if (empty($paragraphs)) {
            return '';
        }

        // Get the first paragraph
        $firstParagraph = $this->trimSentences($paragraphs[0]);

        // Check the length of the first paragraph
        if (strlen($firstParagraph) <= $limit) {
            return $firstParagraph;
        } else {
            // Shorten the text to 250 characters
            return $this->cutToEndOfLastSentence(substr($firstParagraph, 0, $limit));
        }
    }

    private function searchArray($searchKey, $searchValue, $returnKey, $array)
    {
        $result = null;
        foreach ($array as $item) {
            if ($item[$searchKey] == $searchValue) {
                $result = $item[$returnKey];
                break;
            }
        }
        return $result;
    }

    private function cleanEncoding($value)
    {
        if (!is_string($value)) return $value;

        // Replace fancy quotes with normal '
        $value = str_replace(
            ["’", "‘", "´", "`"],
            "'",
            $value
        );

        // Ensure UTF-8 safe string
        return mb_convert_encoding(
            $value,
            'UTF-8',
            mb_detect_encoding($value, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true) ?: 'UTF-8'
        );
    }
}
