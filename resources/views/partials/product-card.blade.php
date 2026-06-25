@forelse ($products as $product)
    @php
        $minPackage = $product->packages->sortBy('price')->first();
        $stock = $product->available_license_stocks_count ?? 0;
        $durationDays = $minPackage?->durationDays();
        $durationLabel = $durationDays
            ? $durationDays . ' ' . \Illuminate\Support\Str::plural('day', $durationDays)
            : 'Package';
        $categoryName = $product->category->name ?? 'Product';
        $categoryKey = strtolower(trim($categoryName));
        $categoryIcon = match ($categoryKey) {
            'pc', 'desktop', 'windows' => 'monitor',
            'ios', 'iphone', 'ipad', 'macos' => 'apple',
            'android' => 'android',
            default => 'box',
        };
        $statusBadgeClass = $product->status === \App\Models\Product::STATUS_UPDATING
            ? 'product-status-badge-updating'
            : 'product-status-badge-ready';
        $isUpdating = $product->status === \App\Models\Product::STATUS_UPDATING;
        $availabilityIcon = $isUpdating ? 'discord' : ($stock > 0 ? 'key-round' : 'discord');
        $availabilityLabel = $isUpdating ? 'Update alerts in Discord' : ($stock > 0 ? $stock . ' ready' : 'Manual order');
        $salesBadgeLabel = $product->sales_badge_label;
        $salesBadgeVariant = $product->sales_badge_variant ?: 'popular';
    @endphp

    <a href="{{ route('products.show', $product) }}" class="product-card product-card-storefront fade-up flex min-h-60 flex-col gap-4 p-5">

        <div class="flex items-start justify-between gap-3">
            <span class="product-category-pill">
                <x-ui.icon :name="$categoryIcon" class="h-4 w-4" />
                <span>{{ $categoryName }}</span>
            </span>

            <span class="product-status-badge product-status-badge-static {{ $statusBadgeClass }}">{{ $product->status_label }}</span>
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
            <span class="product-card-fact">
                <x-ui.icon name="calendar" class="h-4 w-4" />
                <span>From {{ $durationLabel }}</span>
            </span>
            <span class="product-card-fact">
                <x-ui.icon :name="$availabilityIcon" class="h-4 w-4" />
                <span>{{ $availabilityLabel }}</span>
            </span>
        </div>

        <div class="mt-auto flex items-end justify-between gap-4">
            <div>
                <div class="text-[10px] uppercase tracking-wide text-gray-500 mb-1">Start from</div>
                <span class="product-card-price">
                    @if ($minPackage)
                        Rp {{ number_format($minPackage->price) }} / ${{ rtrim(rtrim($minPackage->price_usdt, '0'), '.') }}
                    @else
                        -
                    @endif
                </span>
            </div>

            <span class="product-card-cta">
                <x-ui.icon name="shopping-cart" class="h-4 w-4" />
                <span>View</span>
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
    </div>
@endforelse
