<?php

use App\Http\Controllers\ProductStockController;
use Illuminate\Support\Facades\Route;

Route::get('/product-stocks', ProductStockController::class)
    ->middleware('throttle:120,1')
    ->name('products.stocks');
