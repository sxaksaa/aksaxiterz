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
    $cartPackageIds = $miniCartItems->pluck('package_id')->map(fn ($id) => (int) $id);
    $recommendation = $miniCartItems
        ->pluck('product')
        ->filter()
        ->unique('id')
        ->flatMap(function ($product) use ($cartPackageIds) {
            $daily = $product->packages?->first(fn ($package) => $package->durationDays() === 1);
            if (! $daily || (int) $daily->price <= 0) return collect();

            return $product->packages
                ->filter(fn ($package) =>
                    ! $cartPackageIds->contains((int) $package->id) &&
                    (int) ($package->available_license_stocks_count ?? 0) > 0 &&
                    ($package->durationDays() ?? 0) > 1
                )
                ->map(function ($package) use ($product, $daily) {
                    $days = $package->durationDays();
                    $comparison = (int) $daily->price * $days;
                    $savingPercent = $comparison > 0
                        ? (int) round((($comparison - (int) $package->price) / $comparison) * 100)
                        : 0;

                    return compact('product', 'package', 'savingPercent');
                });
        })
        ->filter(fn ($suggestion) => $suggestion['savingPercent'] > 0)
        ->sortByDesc('savingPercent')
        ->first();
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

        @if ($recommendation)
            <a href="{{ route('products.show', $recommendation['product']->slug) }}"
                class="mini-cart-recommendation" data-soft-nav>
                <span class="mini-cart-recommendation-icon"><x-ui.icon name="sparkles" class="h-4 w-4" /></span>
                <span class="min-w-0 flex-1">
                    <span class="block text-xs font-semibold text-white">
                        Save {{ $recommendation['savingPercent'] }}% with {{ $recommendation['package']->name }}
                    </span>
                    <span class="mt-0.5 block truncate text-[11px] text-gray-500">
                        {{ $recommendation['product']->name }} · lower price per day
                    </span>
                </span>
                <x-ui.icon name="arrow-right" class="h-4 w-4 shrink-0 text-aksa-accent" />
            </a>
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
