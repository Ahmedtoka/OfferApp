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

    // Public app name and marketing/support site (shown in the admin page and links).
    'app_name' => env('APP_NAME', 'Pairly: BOGO Offers'),
    'site_url' => env('SITE_URL'),

    // Where the site's install button goes; set it to the install or App Store link. Empty: the setup docs.
    'install_url' => env('SHOPIFY_INSTALL_URL'),

    // Business identity on the legal pages, supplied by the owner. Empty shows a visible placeholder.
    'legal_name' => env('SHOPIFY_LEGAL_NAME'),
    'support_email' => env('SHOPIFY_SUPPORT_EMAIL'),

    // Shops allowed to install/use the app (lower-cased bare *.myshopify.com hosts).
    // Empty means open: any valid *.myshopify.com store (public app). Set it to restrict.
    'allowed_shops' => array_values(array_filter(array_map(
        fn ($d) => strtolower(trim($d)),
        explode(',', (string) env('SHOPIFY_ALLOWED_SHOPS', ''))
    ))),

    // Turning the offer on needs a Pairly subscription (partner dev stores excepted). Older deployments that
    // never subscribe (Le Voile, Fashion Avenue) set SHOPIFY_BILLING_REQUIRED=false until they are retired.
    'billing_required' => filter_var(env('SHOPIFY_BILLING_REQUIRED', true), FILTER_VALIDATE_BOOLEAN),

    // The app's handle in the admin URL (https://admin.shopify.com/store/{shop}/apps/{handle}); the
    // billing approval returns the merchant there.
    'app_handle' => env('SHOPIFY_APP_HANDLE', 'pairly-bogo-offers'),

    // Title of our Discount Function as Shopify reports it (extension name).
    'function_title' => env('SHOPIFY_FUNCTION_TITLE', 'Pairly Discount'),

    // Absolute, or relative to base_path().
    'shared_constants_path' => env('SHOPIFY_SHARED_CONSTANTS_PATH', base_path('../shared/offer-constants.json')),
];
