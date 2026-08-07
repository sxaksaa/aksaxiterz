@php
    $miniCartItems = collect($miniCartItems ?? []);
    $previewItems = $miniCartItems->sortByDesc('id')->take(3);
    $hiddenItemCount = max(0, $miniCartItems->count() - $previewItems->count());
    $subtotalIdr = (int) $miniCartItems->sum(
        fn ($item) => ((int) ($item->package?->price ?? 0)) * (int) $item->quantity
    );
    $subtotalUsd = round((float) $miniCartItems->sum(
        fn ($item) => ((float) ($item->package?->price_usdt ?? 0)) * (int) $item->quantity
    ), 4);
    $checkoutAvailable = $miniCartItems->isNotEmpty() && $miniCartItems->every(
        fn ($item) => (bool) ($item->is_checkout_available ?? false) &&
            (int) ($item->available_stock ?? 0) >= (int) $item->quantity
    );
@endphp

<div data-mini-cart-content>
    <div class="mini-cart-header">
        <div>
            <p class="text-sm font-semibold text-white">Your cart</p>
            <p class="mt-0.5 text-xs text-gray-500">
                {{ $miniCartItems->count() }} {{ \Illuminate\Support\Str::plural('package', $miniCartItems->count()) }}
            </p>
        </div>
    </div>

    @if ($miniCartItems->isEmpty())
        <div class="mini-cart-empty">
            <span class="mini-cart-empty-icon"><x-ui.icon name="shopping-cart" class="h-5 w-5" /></span>
            <p class="text-sm font-semibold text-white">Your cart is empty</p>
            <p class="mt-1 text-xs text-gray-500">Choose a package to see it here.</p>
        </div>
    @else
        <div class="mini-cart-items">
            @foreach ($previewItems as $item)
                <div class="mini-cart-item">
                    <span class="mini-cart-item-icon"><x-ui.icon name="key-round" class="h-4 w-4" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-white">{{ $item->product?->name ?? 'Product' }}</p>
                        <p class="mt-0.5 truncate text-xs text-gray-500">
                            {{ $item->package?->name ?? 'Package' }} · {{ $item->quantity }}
                            {{ \Illuminate\Support\Str::plural('license', $item->quantity) }}
                        </p>
                    </div>
                    <span class="shrink-0 text-sm font-semibold text-aksa-accent-soft" data-display-price
                        data-price-idr="{{ ((int) ($item->package?->price ?? 0)) * (int) $item->quantity }}"
                        data-price-usd="{{ ((float) ($item->package?->price_usdt ?? 0)) * (int) $item->quantity }}">
                        Rp {{ number_format(((int) ($item->package?->price ?? 0)) * (int) $item->quantity, 0, ',', '.') }}
                    </span>
                </div>
            @endforeach
        </div>

        @if ($hiddenItemCount > 0)
            <p class="mini-cart-more">+{{ $hiddenItemCount }} other {{ \Illuminate\Support\Str::plural('package', $hiddenItemCount) }}</p>
        @endif

        <div class="mini-cart-summary">
            <span class="text-sm text-gray-400">Subtotal</span>
            <span class="font-bold text-white" data-display-price
                data-price-idr="{{ $subtotalIdr }}" data-price-usd="{{ $subtotalUsd }}">
                Rp {{ number_format($subtotalIdr, 0, ',', '.') }}
            </span>
        </div>

        <div class="mini-cart-actions">
            @if ($checkoutAvailable)
                <a href="{{ route('cart.index') }}" class="btn-footer-secondary min-h-10">Edit Cart</a>
                <a href="{{ route('checkout.cart') }}" class="btn-main min-h-10">Checkout</a>
            @else
                <a href="{{ route('cart.index') }}" class="btn-main min-h-10 mini-cart-review-action">Review Cart</a>
            @endif
        </div>
    @endif
</div>
