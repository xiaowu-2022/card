<?php

use Illuminate\Support\Facades\Route;

Route::post('/card-provider', fn () => response()->json([
    'message' => 'Phase 0 placeholder. Tenant resolution must use trusted provider mappings.',
], 501));
