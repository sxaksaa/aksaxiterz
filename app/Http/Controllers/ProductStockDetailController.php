<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\Product;
use App\Support\StorefrontCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ProductStockDetailController extends Controller
{
    public function __invoke(string $product): JsonResponse
    {
        $productId = Product::query()
            ->select('id')
            ->visible()
            ->where('slug', $product)
            ->value('id');

        abort_unless($productId, 404);

        $snapshot = Cache::remember(
            StorefrontCache::STOCK_DETAIL_PREFIX.$productId,
            now()->addSeconds(3),
            function () use ($productId): array {
                $product = Product::query()
                    ->select(['id', 'status'])
                    ->withCount('availableLicenseStocks')
                    ->with([
                        'packages' => fn ($query) => $query
                            ->select(['id', 'product_id'])
                            ->withCount('availableLicenseStocks')
                            ->orderBy('id'),
                    ])
                    ->findOrFail($productId);

                return [
                    'id' => (int) $product->id,
                    'status' => $product->status,
                    'status_label' => $product->status_label,
                    'available_stock' => (int) $product->available_license_stocks_count,
                    'packages' => $product->packages
                        ->map(fn (Package $package): array => [
                            'id' => (int) $package->id,
                            'available_stock' => (int) $package->available_license_stocks_count,
                        ])
                        ->values(),
                ];
            }
        );

        return response()->json($snapshot)->withHeaders([
            'Cache-Control' => 'no-store, private, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
