<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

class ProductStockDetailController extends Controller
{
    public function __invoke(string $product): JsonResponse
    {
        $product = Product::query()
            ->select(['id', 'status'])
            ->visible()
            ->where('slug', $product)
            ->withCount('availableLicenseStocks')
            ->with([
                'packages' => fn ($query) => $query
                    ->select(['id', 'product_id'])
                    ->withCount('availableLicenseStocks')
                    ->orderBy('id'),
            ])
            ->firstOrFail();

        return response()->json([
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
        ])->withHeaders([
            'Cache-Control' => 'no-store, private, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
