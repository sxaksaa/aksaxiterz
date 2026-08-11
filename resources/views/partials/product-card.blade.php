@forelse ($products as $product)
    @php
        $minPackageIdr = $product->packages->sortBy('price')->first();
        $minPackageUsd = $product->packages
            ->filter(fn ($package) => $package->price_usdt !== null && (float) $package->price_usdt > 0)
            ->sortBy('price_usdt')
            ->first();
        $stock = $product->available_license_stocks_count ?? 0;
        $categoryName = $product->category->name ?? 'Product';
        $categoryKey = strtolower(trim($categoryName));
        $categoryIcon = match ($categoryKey) {
            'pc', 'desktop', 'windows' => 'monitor',
            'ios', 'iphone', 'ipad', 'macos' => 'apple',
            'android' => 'android',
            default => 'box',
        };
        $isUpdating = $product->status === \App\Models\Product::STATUS_UPDATING;
        $hasReadyStock = ! $isUpdating && $stock > 0;
        $availabilityLabel = $isUpdating
            ? 'Checkout paused · Discord alerts'
            : ($stock > 0 ? $stock.' available · Auto delivery' : 'Manual order via Discord');
        $salesBadgeLabel = $product->sales_badge_label;
        $salesBadgeVariant = $product->sales_badge_variant ?: 'popular';
    @endphp

    <a href="{{ route('products.show', $product) }}" data-soft-nav
        class="product-card product-card-storefront flex min-h-60 flex-col gap-4 p-5"
        data-product-stock-card data-product-id="{{ $product->id }}" data-product-status="{{ $product->status }}"
        data-product-stock="{{ $stock }}">

        <div class="flex items-start justify-between gap-3">
            <span class="product-category-pill">
                <x-ui.icon :name="$categoryIcon" class="h-4 w-4" />
                <span>{{ $categoryName }}</span>
            </span>

            <span class="product-status-badge product-status-badge-static {{ $isUpdating ? 'product-status-badge-updating' : 'hidden' }}"
                data-product-status-badge>{{ $product->status_label }}</span>
        </div>

        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-xl font-semibold text-white">{{ $product->name }}</h2>

                @if ($salesBadgeLabel)
                    <span class="sales-signal-badge sales-signal-badge-{{ $salesBadgeVariant }}">
                        <x-ui.icon name="sparkles" class="h-3.5 w-3.5" />
                        <span>{{ $salesBadgeLabel }}</span>
                    </span>
                @endif
            </div>

            <p class="mt-2 line-clamp-3 text-sm leading-6 text-gray-400">
                {{ $product->description }}
            </p>
        </div>

        <div class="product-card-facts">
            <span class="product-card-fact" data-product-availability>
                <x-ui.icon name="key-round" class="h-4 w-4 {{ $hasReadyStock ? '' : 'hidden' }}"
                    data-product-stock-icon-ready />
                <x-ui.icon name="discord" class="h-4 w-4 {{ $hasReadyStock ? 'hidden' : '' }}"
                    data-product-stock-icon-unavailable />
                <span data-product-stock-label>{{ $availabilityLabel }}</span>
            </span>
        </div>

        <div class="mt-auto flex items-end justify-between gap-4">
            <div>
                <div class="text-[10px] uppercase tracking-wide text-gray-500 mb-1">Start from</div>
                <span class="product-card-price"
                    @if ($minPackageIdr)
                        data-display-price
                        data-price-idr="{{ (int) $minPackageIdr->price }}"
                        data-price-usd="{{ $minPackageUsd ? (float) $minPackageUsd->price_usdt : '' }}"
                    @endif>
                    @if ($minPackageIdr)
                        Rp {{ number_format($minPackageIdr->price, 0, ',', '.') }}
                    @else
                        -
                    @endif
                </span>
            </div>

            <span class="product-card-cta">
                <x-ui.icon name="shopping-cart" class="h-4 w-4" />
                <span>View Packages</span>
            </span>
        </div>

    </a>
@empty
    <div class="empty-state sm:col-span-2 lg:col-span-3">
        <span class="empty-state-icon">
            <x-ui.icon name="box" class="h-6 w-6" />
        </span>
        <span class="empty-state-title">No products found</span>
        <p class="empty-state-copy">Try another keyword or category.</p>
        <button type="button" class="order-action mt-4" data-clear-product-filters>
            Clear Filters
        </button>
    </div>
@endforelse
