<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application. This value is used when the
    | framework needs to place the application's name in a notification or
    | any other location as required by the application or its packages.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | your application so that it is used when running Artisan tasks.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    'asset_url' => env('ASSET_URL', null),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. We have gone
    | ahead and set this to a sensible default for you out of the box.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by the translation service provider. You are free to set this value
    | to any of the locales which will be supported by the application.
    |
    */

    'locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Application Fallback Locale
    |--------------------------------------------------------------------------
    |
    | The fallback locale determines the locale to use when the current one
    | is not available. You may change the value to correspond to any of
    | the language folders that are provided through your application.
    |
    */

    'fallback_locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Faker Locale
    |--------------------------------------------------------------------------
    |
    | This locale will be used by the Faker PHP library when generating fake
    | data for your database seeds. For example, this will be used to get
    | localized telephone numbers, street address information and more.
    |
    */

    'faker_locale' => 'en_US',

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is used by the Illuminate encrypter service and should be set
    | to a random, 32 character string, otherwise these encrypted strings
    | will not be safe. Please do this before deploying an application!
    |
    */

    'key' => env('APP_KEY'),

    'cipher' => 'AES-256-CBC',

    /*
    |--------------------------------------------------------------------------
    | Autoloaded Service Providers
    |--------------------------------------------------------------------------
    |
    | The service providers listed here will be automatically loaded on the
    | request to your application. Feel free to add your own services to
    | this array to grant expanded functionality to your applications.
    |
    */

    'providers' => [

        /*
         * Laravel Framework Service Providers...
         */
        Illuminate\Auth\AuthServiceProvider::class,
        Illuminate\Broadcasting\BroadcastServiceProvider::class,
        Illuminate\Bus\BusServiceProvider::class,
        Illuminate\Cache\CacheServiceProvider::class,
        Illuminate\Foundation\Providers\ConsoleSupportServiceProvider::class,
        Illuminate\Cookie\CookieServiceProvider::class,
        Illuminate\Database\DatabaseServiceProvider::class,
        Illuminate\Encryption\EncryptionServiceProvider::class,
        Illuminate\Filesystem\FilesystemServiceProvider::class,
        Illuminate\Foundation\Providers\FoundationServiceProvider::class,
        Illuminate\Hashing\HashServiceProvider::class,
        Illuminate\Mail\MailServiceProvider::class,
        Illuminate\Notifications\NotificationServiceProvider::class,
        Illuminate\Pagination\PaginationServiceProvider::class,
        Illuminate\Pipeline\PipelineServiceProvider::class,
        Illuminate\Queue\QueueServiceProvider::class,
        Illuminate\Redis\RedisServiceProvider::class,
        Illuminate\Auth\Passwords\PasswordResetServiceProvider::class,
        Illuminate\Session\SessionServiceProvider::class,
        Illuminate\Translation\TranslationServiceProvider::class,
        Illuminate\Validation\ValidationServiceProvider::class,
        Illuminate\View\ViewServiceProvider::class,

        /*
         * Package Service Providers...
         */

        /*
         * Application Service Providers...
         */
        App\Providers\AppServiceProvider::class,
        App\Providers\AuthServiceProvider::class,
        // App\Providers\BroadcastServiceProvider::class,
        App\Providers\EventServiceProvider::class,
        App\Providers\RouteServiceProvider::class,

    ],

    /*
    |--------------------------------------------------------------------------
    | Class Aliases
    |--------------------------------------------------------------------------
    |
    | This array of class aliases will be registered when this application
    | is started. However, feel free to register as many as you wish as
    | the aliases are "lazy" loaded so they don't hinder performance.
    |
    */

    'aliases' => [

        'App' => Illuminate\Support\Facades\App::class,
        'Arr' => Illuminate\Support\Arr::class,
        'Artisan' => Illuminate\Support\Facades\Artisan::class,
        'Auth' => Illuminate\Support\Facades\Auth::class,
        'Blade' => Illuminate\Support\Facades\Blade::class,
        'Broadcast' => Illuminate\Support\Facades\Broadcast::class,
        'Bus' => Illuminate\Support\Facades\Bus::class,
        'Cache' => Illuminate\Support\Facades\Cache::class,
        'Config' => Illuminate\Support\Facades\Config::class,
        'Cookie' => Illuminate\Support\Facades\Cookie::class,
        'Crypt' => Illuminate\Support\Facades\Crypt::class,
        'Date' => Illuminate\Support\Facades\Date::class,
        'DB' => Illuminate\Support\Facades\DB::class,
        'Eloquent' => Illuminate\Database\Eloquent\Model::class,
        'Event' => Illuminate\Support\Facades\Event::class,
        'File' => Illuminate\Support\Facades\File::class,
        'Gate' => Illuminate\Support\Facades\Gate::class,
        'Hash' => Illuminate\Support\Facades\Hash::class,
        'Http' => Illuminate\Support\Facades\Http::class,
        'Js' => Illuminate\Support\Js::class,
        'Lang' => Illuminate\Support\Facades\Lang::class,
        'Log' => Illuminate\Support\Facades\Log::class,
        'Mail' => Illuminate\Support\Facades\Mail::class,
        'Notification' => Illuminate\Support\Facades\Notification::class,
        'Password' => Illuminate\Support\Facades\Password::class,
        'Queue' => Illuminate\Support\Facades\Queue::class,
        'RateLimiter' => Illuminate\Support\Facades\RateLimiter::class,
        'Redirect' => Illuminate\Support\Facades\Redirect::class,
        // 'Redis' => Illuminate\Support\Facades\Redis::class,
        'Request' => Illuminate\Support\Facades\Request::class,
        'Response' => Illuminate\Support\Facades\Response::class,
        'Route' => Illuminate\Support\Facades\Route::class,
        'Schema' => Illuminate\Support\Facades\Schema::class,
        'Session' => Illuminate\Support\Facades\Session::class,
        'Storage' => Illuminate\Support\Facades\Storage::class,
        'Str' => Illuminate\Support\Str::class,
        'URL' => Illuminate\Support\Facades\URL::class,
        'Validator' => Illuminate\Support\Facades\Validator::class,
        'View' => Illuminate\Support\Facades\View::class,

    ],

    // OdooWoo Settings
    'odoowoo_company' => env('ODOOWOO_COMPANY_NAME', "COCOWARE"),
    'odoowoo_admin_email' => env('MAIL_NOTIFICATIONS','admin@cocoware.co.uk'),
    'odoowoo_sync_simple' => (bool) env('ODOOWOO_SYNC_SIMPLE_ENABLED', false),
    'odoowoo_sync_variable' => (bool) env('ODOOWOO_SYNC_VARIABLE_ENABLED', false),
    // Scheduler liveness. `woo:cron` stamps a heartbeat every minute and
    // `woo:cron --check` fails when it, or a sync watermark, goes stale.
    //
    // Defaults to ON, unlike the sync flags. This was ODOOWOO_CRON_DEBUG and
    // defaulted to off, which is backwards for a liveness check: the setup it
    // detects a fault in — a host where nobody wired up `schedule:run` — is
    // exactly the setup where nobody thought to switch the detector on either.
    'odoowoo_heartbeat' => (bool) env('ODOOWOO_HEARTBEAT_ENABLED', true),
    // How old the scheduler heartbeat may get before --check calls it stale.
    // The tick is every minute, so this is "four consecutive ticks missed",
    // which tolerates one slow schedule:run without crying wolf.
    'odoowoo_heartbeat_max_tick_minutes' => (int) env('ODOOWOO_HEARTBEAT_MAX_TICK_MINUTES', 5),
    // The same for the sync watermarks, which advance once per *successful*
    // run of each leg. woo:sync-all runs every 30 minutes, so this allows three
    // failed or skipped runs before alarming.
    //
    // Keep it below the 360-minute withoutOverlapping() expiry in Console\Kernel:
    // a run wedged behind that lock shows up here as a watermark that stopped
    // advancing, and a threshold above the expiry would let it clear itself
    // before anyone was told.
    'odoowoo_heartbeat_max_sync_minutes' => (int) env('ODOOWOO_HEARTBEAT_MAX_SYNC_MINUTES', 120),
    // Skip simple-product updates whose payload would change nothing in Woo.
    // Set ODOOWOO_SKIP_UNCHANGED=false to restore the old always-push behaviour.
    'odoowoo_skip_unchanged' => (bool) env('ODOOWOO_SKIP_UNCHANGED', true),
    // Read the Woo catalogue by SKU batches instead of paging all of it.
    // Set ODOOWOO_SKU_LOOKUP=false to restore full-catalogue paging.
    'odoowoo_sku_lookup' => (bool) env('ODOOWOO_SKU_LOOKUP', true),

    // OdooWoo Sync Window Settings
    // Batch runs filter Odoo by write_date >= the last successful run (see
    // App\Services\SyncWatermark). These two tune that window.
    //
    // How far back before the watermark to start, covering write_date's
    // one-second granularity and clock skew against Odoo. Kept small — the
    // variable sync re-pushes every product it re-sees, at ~50-90s each.
    //
    // REQUIRES this host and the Odoo server to be NTP-synchronised: the
    // watermark is written from this host's clock and compared against Odoo's
    // write_date, so this value is the entire margin for the difference.
    // Floored at 1 minute in code — 0 leaves no margin at all.
    'odoowoo_sync_overlap_minutes' => (int) env('ODOOWOO_SYNC_OVERLAP_MINUTES', 3),
    // Hard ceiling on the window. Without it, a stale watermark (server down
    // for a week) turns one scheduled run into an unbounded catalogue pull.
    //
    // Hitting this ceiling DISCARDS the range between the watermark and the
    // ceiling — the run logs it at error level with a --since command to
    // recover it, then advances the watermark past it. Lowering this value
    // shortens the outage a run can absorb before it starts losing records.
    'odoowoo_sync_max_lookback_hours' => (int) env('ODOOWOO_SYNC_MAX_LOOKBACK_HOURS', 168),

    // OdooWoo POS SMS Settings
    'odoowoo_pos_sms' => (bool) env('ODOOWOO_POS_SMS_NOTIFICATIONS', false),
    'odoowoo_pos_sms_recipients' => env('ODOOWOO_POS_SMS_NOTIFICATIONS_RECIPIENTS', "0558181935"),
    'odoowoo_pos_sms_time' => env('ODOOWOO_POS_SMS_NOTIFICATIONS_TIME', '18:00'),
    
    // OdooWoo Customer SMS Settings
    'odoowoo_customer_sms' => (bool) env('ODOOWOO_CUSTOMER_SMS_NOTIFICATIONS', false),
    // Not cast: this is a HH:MM string passed to dailyAt(). The (bool) that
    // used to sit here turned every configured time into `true`, which the
    // scheduler entry masked by hardcoding 13:00.
    'odoowoo_customer_sms_time' => env('ODOOWOO_CUSTOMER_SMS_NOTIFICATIONS_TIME', '12:00'),
    'odoowoo_customer_sms_template_1' => env('ODOOWOO_CUSTOMER_SMS_TEMPLATE', "Hey [name], it's [company]. Your recent purchase warms our hearts. Thank you for choosing us!"),

    // Addressed to a customer whose Odoo contact carries no usable given name
    // (commonly one named by phone number at the till).
    'odoowoo_customer_sms_fallback_name' => env('ODOOWOO_CUSTOMER_SMS_FALLBACK_NAME', 'there'),

    // Boolean field on res.partner recording marketing consent. Leave empty to
    // message every customer; set it to filter to those who opted in. Ghana's
    // Data Protection Act 2012 requires a lawful basis for direct marketing,
    // and this template is closer to marketing than to a transactional receipt.
    'odoowoo_customer_sms_consent_field' => env('ODOOWOO_CUSTOMER_SMS_CONSENT_FIELD', ''),

    // res.partner fields to read a number from, in order of preference. Odoo 19
    // merged `mobile` into `phone` and rejects the old name outright, so the
    // default is `phone` alone; on Odoo 18 and earlier set "mobile,phone" to
    // pick up till-created contacts that only filled the mobile column.
    'odoowoo_customer_sms_phone_fields' => env('ODOOWOO_CUSTOMER_SMS_PHONE_FIELDS', 'phone'),

    // Country code used to canonicalise phone numbers before de-duplication.
    'odoowoo_sms_country_code' => env('ODOOWOO_SMS_COUNTRY_CODE', '233'),

    // How long the duplicate-send ledger keeps a trading day's recipients.
    'odoowoo_sms_ledger_days' => (int) env('ODOOWOO_SMS_LEDGER_DAYS', 90),
    
    // OdooWoo Currency Settings
    'odoowoo_currency' => env('ODOOWOO_CURRENCY', 'GHS'),

    // OdooWoo Pricelist Settings
    'odoowoo_pricelist' => (int) env('ODOO_PRICELIST_ID', 0),

    // WooCommerce Settings
    'woo_sleep_seconds' => env('WOOCOMMERCE_BATCH_WAIT_TIME', 10),
    'woo_products_per_batch' => env('WOOCOMMERCE_PRODUCTS_PER_BATCH', 10),
    'woo_default_desc' => (bool) env('WOOCOMMERCE_DEFAULT_DESCRIPTION', false),
    'woo_brand_attribute_id' => env('WOOCOMMERCE_BRAND_ID', ''),

    // Odoo Settings
    'odoo_url' => env('ODOO_URL', ''),
    'odoo_db' => env('ODOO_DB', ''),
    'odoo_username' => env('ODOO_USERNAME', ''),
    'odoo_password' => env('ODOO_PASSWORD', ''),
    'odoo_delay' => env('ODOO_DELAY_SECONDS', 1),
    'odoo_verify_ssl' => env('ODOO_VERIFY_SSL', false),

    // mNotify Settings
    'mnotify_api_url' => env('MNOTIFY_API_URL', ''),
    'mnotify_api_key' => env('MNOTIFY_API_KEY', ''),
    'mnotify_sender_id' => env('MNOTIFY_SENDER_ID', 'COCOWARE'),

    // Bounds on the gateway call. The send loop runs once per customer, so an
    // unbounded request stalls the entire run, not just one message.
    'mnotify_timeout' => (int) env('MNOTIFY_TIMEOUT', 15),
    'mnotify_connect_timeout' => (int) env('MNOTIFY_CONNECT_TIMEOUT', 10),
    'mnotify_retries' => (int) env('MNOTIFY_RETRIES', 3),
    'mnotify_retry_delay_ms' => (int) env('MNOTIFY_RETRY_DELAY_MS', 1000),

];
