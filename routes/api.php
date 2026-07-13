<?php

use App\Http\Controllers\ProductStockController;
use App\Http\Controllers\RecentPurchaseController;
use Illuminate\Support\Facades\Route;

Route::get('/product-stocks', ProductStockController::class)
    ->middleware('throttle:120,1')
    ->name('products.stocks');

Route::get('/recent-purchases', RecentPurchaseController::class)
    ->middleware('throttle:120,1')
    ->name('purchases.recent');
