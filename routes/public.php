<?php

use App\Http\Controllers\Api\ImageDeliveryController;
use Illuminate\Support\Facades\Route;

// Public routes not requiring tenant context may be added here.

Route::get('/media/images/{image}', ImageDeliveryController::class)
    ->whereUuid('image')->name('media.image');
