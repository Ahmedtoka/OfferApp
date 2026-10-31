<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TestCartController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Every /api route is session-token authenticated (Bearer only; no cookies/CSRF).
Route::middleware(['shopify.session', 'throttle:shop-api'])->group(function () {
    Route::get('/ping', fn (Request $request) => ['ok' => true, 'shop' => $request->attributes->get('shop')]);
    Route::get('/settings', [SettingsController::class, 'show']);
    Route::put('/settings', [SettingsController::class, 'update']);
    Route::get('/billing', [BillingController::class, 'show']);
    Route::post('/billing/subscribe', [BillingController::class, 'subscribe']);
    Route::post('/test-cart', [TestCartController::class, 'store'])->middleware('throttle:test-cart');
});

// Expensive (live Admin GraphQL), so throttled per shop on top of the session check.
Route::middleware(['shopify.session', 'throttle:analytics'])->get('/analytics', [AnalyticsController::class, 'show']);
