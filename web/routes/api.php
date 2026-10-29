<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Every /api route is session-token authenticated (Bearer only; no cookies/CSRF).
Route::middleware('shopify.session')->group(function () {
    // Placeholder so the auth middleware is testable; Tasks 8-9 add the real routes.
    Route::get('/ping', fn (Request $request) => ['ok' => true, 'shop' => $request->attributes->get('shop')]);
});
