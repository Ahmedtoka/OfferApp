<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// `/` is the embedded admin page load when it carries Shopify's HMAC or id_token, and the public landing when
// it carries neither, on the host of SITE_URL (see ServeLanding).
Route::get('/', HomeController::class)->middleware(['site.landing', 'shopify.hmac']);

// The nav pages are sections of the same embedded document; a direct load of any of them serves the shell.
foreach (['/settings', '/analytics', '/test'] as $page) {
    Route::get($page, HomeController::class)->middleware('shopify.hmac');
}

// Public marketing site, English and Arabic.
Route::get('/ar', [SiteController::class, 'page'])->defaults('page', 'home')->defaults('lang', 'ar');
foreach (['pricing', 'docs', 'privacy', 'terms'] as $page) {
    Route::get("/{$page}", [SiteController::class, 'page'])->defaults('page', $page)->defaults('lang', 'en');
    Route::get("/ar/{$page}", [SiteController::class, 'page'])->defaults('page', $page)->defaults('lang', 'ar');
}

// Shopify webhooks: raw-body HMAC instead of CSRF/session.
Route::post('/webhooks', [WebhookController::class, 'handle'])->middleware('shopify.webhook');
