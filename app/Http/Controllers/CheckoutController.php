<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartService $cartService
    ) {}

    public function cart(Request $request): View|RedirectResponse
    {
        $items = $this->cartService->items($request->user());

        if ($items->isEmpty()) {
            return redirect()
                ->route('cart.index')
                ->withErrors(['cart' => 'Your cart is empty.']);
        }

        $checkoutItems = $items->map(function ($item): array {
            $availableStock = $item->package->availableLicenseStocks()->count();
            $isAvailable = $item->product?->isReadyForAutomaticCheckout() &&
                $availableStock >= (int) $item->quantity;

            return [
                'product_name' => $item->product?->name ?? 'Unavailable product',
                'product_slug' => $item->product?->slug,
                'package_name' => $item->package?->name ?? 'Unavailable package',
                'quantity' => (int) $item->quantity,
                'available_stock' => $availableStock,
                'line_total_idr' => (int) $item->package->price * (int) $item->quantity,
                'line_total_usdt' => round(
                    (float) $item->package->price_usdt * (int) $item->quantity,
                    6
                ),
                'has_usd_price' => $item->package->price_usdt !== null &&
                    (float) $item->package->price_usdt > 0,
                'is_available' => $isAvailable,
            ];
        });

        return $this->checkoutView(
            mode: 'cart',
            checkoutItems: $checkoutItems,
            subtotalIdr: (int) $checkoutItems->sum('line_total_idr'),
            subtotalUsdt: round((float) $checkoutItems->sum('line_total_usdt'), 6),
            hasUnavailableItems: $checkoutItems->contains(
                fn (array $item): bool => ! $item['is_available']
            ),
            checkoutAction: route('cart.checkout'),
            voucherUrl: route('cart.vouchers.preview'),
            cartSignature: $this->cartService->signature($items)
        );
    }

    public function product(Request $request, Product $product): View
    {
        abort_unless($product->is_visible, 404);

        $validated = $request->validate([
            'package' => ['required', 'integer', 'exists:packages,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:'.CartService::MAX_TOTAL_QUANTITY],
        ]);

        $package = Package::query()
            ->whereKey($validated['package'])
            ->where('product_id', $product->id)
            ->firstOrFail();
        $quantity = (int) ($validated['quantity'] ?? 1);
        $availableStock = $package->availableLicenseStocks()->count();
        $isAvailable = $product->isReadyForAutomaticCheckout() &&
            $availableStock >= $quantity;
        $checkoutItems = collect([[
            'product_name' => $product->name,
            'product_slug' => $product->slug,
            'package_name' => $package->name,
            'quantity' => $quantity,
            'available_stock' => $availableStock,
            'line_total_idr' => (int) $package->price * $quantity,
            'line_total_usdt' => round((float) $package->price_usdt * $quantity, 6),
            'has_usd_price' => $package->price_usdt !== null && (float) $package->price_usdt > 0,
            'is_available' => $isAvailable,
        ]]);

        return $this->checkoutView(
            mode: 'direct',
            checkoutItems: $checkoutItems,
            subtotalIdr: (int) $package->price * $quantity,
            subtotalUsdt: round((float) $package->price_usdt * $quantity, 6),
            hasUnavailableItems: ! $isAvailable,
            checkoutAction: route('checkout.product.process', $product),
            voucherUrl: route('vouchers.preview'),
            product: $product,
            package: $package,
            quantity: $quantity
        );
    }

    private function checkoutView(
        string $mode,
        $checkoutItems,
        int $subtotalIdr,
        float $subtotalUsdt,
        bool $hasUnavailableItems,
        string $checkoutAction,
        string $voucherUrl,
        ?Product $product = null,
        ?Package $package = null,
        int $quantity = 1,
        ?string $cartSignature = null
    ): View {
        $binancePayAvailable = app()->environment('local') || (
            (bool) config('services.binance.pay.enabled') &&
            filled(config('services.binance.pay.pay_id')) &&
            filled(config('services.binance.pay.api_key')) &&
            filled(config('services.binance.pay.api_secret'))
        );

        return view('checkout', [
            'checkoutMode' => $mode,
            'checkoutItems' => $checkoutItems,
            'subtotalIdr' => $subtotalIdr,
            'subtotalUsdt' => $subtotalUsdt,
            'hasUnavailableItems' => $hasUnavailableItems,
            'checkoutAction' => $checkoutAction,
            'voucherUrl' => $voucherUrl,
            'product' => $product,
            'package' => $package,
            'quantity' => $quantity,
            'cartSignature' => $cartSignature,
            'qrisAvailable' => (bool) config('services.gopay_qris.enabled'),
            'binancePayAvailable' => $binancePayAvailable,
            'stablecoinPricingAvailable' => $checkoutItems->every(
                fn (array $item): bool => (bool) ($item['has_usd_price'] ?? false)
            ),
        ]);
    }
}
