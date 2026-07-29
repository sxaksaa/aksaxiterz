<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class StorefrontCache
{
    public const SALES_ALL_TIME = 'storefront:sales-signals:all-time:v1';

    public const SALES_RECENT = 'storefront:sales-signals:recent:v1';

    public const RECENT_PURCHASES_PREFIX = 'storefront:recent-purchases:v1:';

    public const STOCK_LIST = 'storefront:product-stocks:v1';

    public const STOCK_DETAIL_PREFIX = 'storefront:product-stock:v1:';

    public static function forgetSalesAndPurchases(): void
    {
        Cache::forget(self::SALES_ALL_TIME);
        Cache::forget(self::SALES_RECENT);
        Cache::forget(self::RECENT_PURCHASES_PREFIX.'all');
    }

    public static function forgetRecentPurchasesForProduct(?int $productId): void
    {
        self::forgetSalesAndPurchases();

        if ($productId) {
            Cache::forget(self::RECENT_PURCHASES_PREFIX.'product:'.$productId);
        }
    }

    public static function forgetStock(?int $productId = null): void
    {
        Cache::forget(self::STOCK_LIST);

        if ($productId) {
            Cache::forget(self::STOCK_DETAIL_PREFIX.$productId);
        }
    }
}
