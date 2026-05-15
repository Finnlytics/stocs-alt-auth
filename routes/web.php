<?php

use Illuminate\Support\Facades\Route;

// stocs-auth is a headless API. The web router exists only because Laravel
// wires it by default — there are intentionally no web endpoints here.
// Anything hitting the root falls through to the JSON 404 renderer in
// bootstrap/app.php.
Route::fallback(fn () => response()->json(['message' => 'Not found.'], 404));
