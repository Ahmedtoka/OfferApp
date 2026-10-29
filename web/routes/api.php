<?php

use App\Http\Controllers\SettingsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Every /api route is session-token authenticated (Bearer only; no cookies/CSRF).
Route::middleware('shopify.session')->group(function () {
    Route::get('/ping', fn (Request $request) => ['ok' => true, 'shop' => $request->attributes->get('shop')]);
    Route::get('/settings', [SettingsController::class, 'show']);
    Route::put('/settings', [SettingsController::class, 'update']);
});
