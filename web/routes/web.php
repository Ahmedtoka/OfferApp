<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Embedded admin page load (Shopify admin HMAC or id_token).
Route::get('/', HomeController::class)->middleware('shopify.hmac');

// Shopify webhooks: raw-body HMAC instead of CSRF/session.
Route::post('/webhooks', [WebhookController::class, 'handle'])->middleware('shopify.webhook');
