<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Embedded admin page load; the real page replaces this placeholder in a later task.
Route::get('/', fn () => response('', 200))->middleware('shopify.hmac');

// Shopify webhooks: raw-body HMAC instead of CSRF/session.
Route::post('/webhooks', [WebhookController::class, 'handle'])->middleware('shopify.webhook');
