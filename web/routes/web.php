<?php

use Illuminate\Support\Facades\Route;

// Embedded admin page load; the real page replaces this placeholder in a later task.
Route::get('/', fn () => response('', 200))->middleware('shopify.hmac');
