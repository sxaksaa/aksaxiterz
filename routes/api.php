<?php

use App\Http\Controllers\GopayNotificationController;
use App\Http\Controllers\ProductStockController;
use App\Http\Controllers\ProductStockDetailController;
use App\Http\Controllers\RecentPurchaseController;
use Illuminate\Support\Facades\Route;

Route::post('/payments/gopay-qris/notifications', GopayNotificationController::class)
    ->middleware('throttle:120,1')
    ->name('payments.gopay-qris.notifications');

Route::get('/product-stocks', ProductStockController::class)
    ->middleware('throttle:120,1')
    ->name('products.stocks');

Route::get('/product-stocks/{product}', ProductStockDetailController::class)
    ->where('product', '[A-Za-z0-9-]+')
    ->middleware('throttle:120,1')
    ->name('products.stock-detail');

Route::get('/recent-purchases', RecentPurchaseController::class)
    ->middleware('throttle:120,1')
    ->name('purchases.recent');
