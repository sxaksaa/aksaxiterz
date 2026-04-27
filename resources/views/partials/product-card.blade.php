@forelse ($products as $product)
    @php
        $minPackage = $product->packages->sortBy('price')->first();
        $stock = $product->available_license_stocks_count ?? 0;
        $badge = null;

        if ($minPackage && $minPackage->price <= 50000) {
            $badge = 'Low Price';
        }
        if ($minPackage && $minPackage->price >= 100000) {
            $badge = 'Premium';
        }
        if ($product->category?->slug === 'testing-payment') {
            $badge = 'Testing';
        }
    @endphp

    <a href="/product/{{ $product->id }}" class="product-card fade-up p-5 flex min-h-56 flex-col">

        @if ($badge)
            <div class="badge">{{ $badge }}</div>
        @endif

        <div class="text-xs text-gray-500 mb-4">{{ $product->category->name ?? 'Product' }}</div>

        <h2 class="text-xl font-semibold mb-2 pr-20">{{ $product->name }}</h2>

        <p class="text-gray-400 text-sm mb-6 line-clamp-3">
            {{ $product->description }}
        </p>

        <div class="mt-auto flex items-end justify-between gap-4">
            <div>
                <div class="text-[10px] uppercase tracking-wide text-gray-500 mb-1">Start from</div>
                <span class="price">
                    @if ($minPackage)
                        Rp {{ number_format($minPackage->price) }} / ${{ rtrim(rtrim($minPackage->price_usdt, '0'), '.') }}
                    @else
                        -
                    @endif
                </span>
            </div>

            <span class="text-xs {{ $stock > 0 ? 'text-[#C084FC]' : 'text-red-300' }}">
                {{ $stock > 0 ? $stock . ' stock' : 'Out of stock' }}
            </span>
        </div>

    </a>
@empty
    <div class="empty-state sm:col-span-2 lg:col-span-3">
        No products match this filter yet.
    </div>
@endforelse
