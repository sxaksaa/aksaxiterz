<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\StorefrontCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ProductStockController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $snapshot = Cache::remember(StorefrontCache::STOCK_LIST, now()->addSeconds(3), function (): array {
            $products = Product::query()
                ->select(['id', 'status'])
                ->visible()
                ->withCount('availableLicenseStocks')
                ->orderBy('id')
                ->get()
                ->map(fn (Product $product): array => [
                    'id' => (int) $product->id,
                    'status' => $product->status,
                    'status_label' => $product->status_label,
                    'available_stock' => (int) $product->available_license_stocks_count,
                ])
                ->values();

            return [
                'products' => $products,
                'total_available_stock' => $products
                    ->where('status', Product::STATUS_READY)
                    ->sum('available_stock'),
            ];
        });

        return response()->json($snapshot)->withHeaders([
            'Cache-Control' => 'no-store, private, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
