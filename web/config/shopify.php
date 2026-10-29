<?php

return [
    // App API key (client id) from the Shopify Dev Dashboard. Public value.
    'api_key' => env('SHOPIFY_API_KEY'),

    // App API secret. Never commit; set only in the server's .env.
    'api_secret' => env('SHOPIFY_API_SECRET'),

    // Admin API version; must match the extension's shopify.extension.toml.
    'api_version' => env('SHOPIFY_API_VERSION', '2026-07'),

    // Public base URL of this app (used for redirects and webhook addresses).
    'app_url' => env('APP_URL'),

    // Shops allowed to install/use the app (lower-cased bare *.myshopify.com hosts).
    'allowed_shops' => array_values(array_filter(array_map(
        fn ($d) => strtolower(trim($d)),
        explode(',', (string) env('SHOPIFY_ALLOWED_SHOPS', ''))
    ))),

    // Absolute, or relative to base_path().
    'shared_constants_path' => env('SHOPIFY_SHARED_CONSTANTS_PATH', base_path('../shared/offer-constants.json')),
];
