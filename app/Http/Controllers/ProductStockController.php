<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;

class ProductStockController extends Controller
{
    public function __invoke(): JsonResponse
    {
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

        return response()->json([
            'products' => $products,
            'total_available_stock' => $products->sum('available_stock'),
        ])->withHeaders([
            'Cache-Control' => 'no-store, private, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
