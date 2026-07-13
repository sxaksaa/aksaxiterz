<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\RecentPurchaseFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecentPurchaseController extends Controller
{
    public function __construct(
        private readonly RecentPurchaseFeed $recentPurchaseFeed
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product' => ['nullable', 'string', 'max:255', 'regex:/\A[A-Za-z0-9-]+\z/'],
        ]);

        $product = null;
        $productSlug = $validated['product'] ?? null;

        if (filled($productSlug)) {
            $product = Product::query()
                ->visible()
                ->where('slug', $productSlug)
                ->firstOrFail();
        }

        return response()->json([
            'purchases' => $this->recentPurchaseFeed->storefront($product),
        ])->withHeaders([
            'Cache-Control' => 'no-store, private, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
