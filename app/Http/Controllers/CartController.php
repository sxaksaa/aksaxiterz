<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Models\Package;
use App\Models\Product;
use App\Services\CartService;
use App\Services\CheckoutLockService;
use App\Services\VoucherService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CartController extends Controller
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly CheckoutLockService $checkoutLockService
    ) {}

    public function index(Request $request)
    {
        $items = $this->cartService->items($request->user());
        $this->attachAvailability($items);
        $hasUnavailableItems = $items->contains(
            fn ($item): bool => ! (bool) $item->is_checkout_available ||
                (int) $item->available_stock < (int) $item->quantity
        );

        return view('cart', [
            'cartItems' => $items,
            'hasUnavailableItems' => $hasUnavailableItems,
            'subtotalIdr' => (int) $items->sum(fn ($item) => $item->package->price * $item->quantity),
            'subtotalUsdt' => round((float) $items->sum(fn ($item) => $item->package->price_usdt * $item->quantity), 6),
            'stablecoinPricingAvailable' => $items->every(
                fn ($item): bool => $item->package->price_usdt !== null &&
                    (float) $item->package->price_usdt > 0
            ),
        ]);
    }

    public function preview(Request $request)
    {
        $items = $this->cartService->items($request->user());
        $this->attachAvailability($items);
        $this->loadRecommendationData($items);

        return response()->json([
            'cart_count' => (int) $items->sum('quantity'),
            'html' => view('partials.mini-cart-content', [
                'miniCartItems' => $items,
            ])->render(),
        ]);
    }

    public function store(Request $request, Product $product)
    {
        $validated = $request->validate([
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:'.CartService::MAX_TOTAL_QUANTITY],
        ]);
        $package = Package::whereKey($validated['package_id'])
            ->where('product_id', $product->id)
            ->firstOrFail();

        return $this->runCartMutation($request, function () use ($request, $product, $package, $validated) {
            try {
                $item = $this->cartService->add(
                    $request->user(),
                    $product,
                    $package,
                    (int) ($validated['quantity'] ?? 1)
                );
            } catch (\Exception $error) {
                return $this->errorResponse($request, $error->getMessage());
            }

            return $this->successResponse($request, [
                'message' => $product->name.' - '.$package->name.' was added to your cart.',
                'cart_count' => (int) $request->user()->cartItems()->sum('quantity'),
                'item_id' => $item->id,
                'cart_preview_html' => view('partials.mini-cart-content', [
                    'miniCartItems' => tap($this->cartService->items($request->user()), function ($items): void {
                        $this->attachAvailability($items);
                        $this->loadRecommendationData($items);
                    }),
                ])->render(),
            ]);
        });
    }

    private function loadRecommendationData($items): void
    {
        $items->loadMissing([
            'product.packages' => fn ($query) => $query->withCount('availableLicenseStocks'),
        ]);
    }

    public function update(Request $request, CartItem $cartItem)
    {
        abort_unless((int) $cartItem->user_id === (int) $request->user()->id, 404);

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:'.CartService::MAX_TOTAL_QUANTITY],
        ]);

        return $this->runCartMutation($request, function () use ($request, $cartItem, $validated) {
            try {
                $item = $this->cartService->update($request->user(), $cartItem, (int) $validated['quantity']);
            } catch (\Exception $error) {
                return $this->errorResponse($request, $error->getMessage());
            }

            $items = $this->cartService->items($request->user());
            $availableStock = $item->package->availableLicenseStocks()->count();
            $otherCartQuantity = (int) $items->where('id', '!=', $item->id)->sum('quantity');
            $maxQuantity = min(
                $availableStock,
                max(1, CartService::MAX_TOTAL_QUANTITY - $otherCartQuantity)
            );
            $totalQuantity = (int) $items->sum('quantity');
            $itemLimits = $items->map(function ($cartItem) use ($totalQuantity): array {
                $availableStock = $cartItem->package->availableLicenseStocks()->count();
                $otherCartQuantity = $totalQuantity - $cartItem->quantity;

                return [
                    'id' => $cartItem->id,
                    'max_quantity' => min(
                        $availableStock,
                        max(1, CartService::MAX_TOTAL_QUANTITY - $otherCartQuantity)
                    ),
                ];
            })->values();

            return $this->successResponse($request, [
                'message' => 'Cart quantity updated.',
                'item' => [
                    'id' => $item->id,
                    'quantity' => $item->quantity,
                    'line_total_idr' => (int) $item->package->price * $item->quantity,
                    'line_total_usdt' => round((float) $item->package->price_usdt * $item->quantity, 6),
                    'max_quantity' => $maxQuantity,
                ],
                'cart' => [
                    'distinct_items' => $items->count(),
                    'quantity' => $totalQuantity,
                    'subtotal_idr' => (int) $items->sum(fn ($cartItem) => $cartItem->package->price * $cartItem->quantity),
                    'subtotal_usdt' => round((float) $items->sum(
                        fn ($cartItem) => $cartItem->package->price_usdt * $cartItem->quantity
                    ), 6),
                    'item_limits' => $itemLimits,
                ],
            ]);
        });
    }

    public function destroy(Request $request, CartItem $cartItem)
    {
        return $this->runCartMutation($request, function () use ($request, $cartItem) {
            $this->cartService->remove($request->user(), $cartItem);

            return $this->successResponse($request, ['message' => 'Item removed from your cart.']);
        });
    }

    public function clear(Request $request)
    {
        return $this->runCartMutation($request, function () use ($request) {
            $this->cartService->clear($request->user());

            return $this->successResponse($request, ['message' => 'Your cart is now empty.']);
        });
    }

    public function previewVoucher(Request $request, VoucherService $voucherService)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'payment_method' => ['required', Rule::in(['gopay_qris', 'crypto', 'binance_pay'])],
            'coin' => [
                'nullable',
                'string',
                'max:20',
                'required_if:payment_method,crypto,binance_pay',
                Rule::in(array_merge(['usdt', 'usdc'], array_keys(config('services.crypto_direct.networks', [])))),
            ],
        ]);
        $items = $this->cartService->items($request->user());

        try {
            $this->cartService->validateForCheckout($items);
            $quote = $voucherService->quoteCart(
                $items,
                $request->user(),
                $validated['code'],
                null,
                null,
                false,
                $validated['payment_method'],
                $validated['coin'] ?? null
            );
        } catch (\Exception $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }

        unset($quote['voucher_id']);

        return response()->json($quote);
    }

    private function attachAvailability($items): void
    {
        foreach ($items as $item) {
            $item->setAttribute('available_stock', $item->package->availableLicenseStocks()->count());
            $item->setAttribute(
                'is_checkout_available',
                (bool) $item->product?->isReadyForAutomaticCheckout() &&
                    $item->available_stock >= (int) $item->quantity
            );
        }
    }

    private function successResponse(Request $request, array $payload)
    {
        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return back()->with('info', $payload['message']);
    }

    private function runCartMutation(Request $request, callable $callback)
    {
        try {
            return $this->checkoutLockService->run((int) $request->user()->id, $callback);
        } catch (LockTimeoutException) {
            return $this->errorResponse(
                $request,
                'Checkout is updating your cart. Wait a moment and try again.',
                409
            );
        }
    }

    private function errorResponse(Request $request, string $message, int $status = 422)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        return back()->withErrors(['cart' => $message]);
    }
}
