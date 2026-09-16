@php
    $readyProducts = $products->where('status', \App\Models\Product::STATUS_READY)->values();
    $updatingProducts = $products->where('status', \App\Models\Product::STATUS_UPDATING)->values();
@endphp

@if ($products->isEmpty())
    <div class="empty-state col-span-full">
        <span class="empty-state-icon">
            <x-ui.icon name="box" class="h-6 w-6" />
        </span>
        <span class="empty-state-title">No products found</span>
        <p class="empty-state-copy">Try another keyword or category.</p>
        <button type="button" class="order-action mt-4" data-clear-product-filters>
            Clear Filters
        </button>
    </div>
@else
    @if ($readyProducts->isNotEmpty())
        <div class="product-section-divider product-section-divider-ready col-span-full" data-product-section-heading="ready">
            <span class="product-section-divider-line" aria-hidden="true"></span>
            <div class="product-section-divider-copy">
                <span class="product-section-eyebrow">
                    <span class="product-section-status-dot" aria-hidden="true"></span>
                    Available now
                </span>
            </div>
            <span class="product-section-divider-line" aria-hidden="true"></span>
        </div>

        @include('partials.product-card-items', ['products' => $readyProducts])
    @endif

    @if ($updatingProducts->isNotEmpty())
        <div class="product-section-divider col-span-full" data-product-section-heading="updating">
            <span class="product-section-divider-line" aria-hidden="true"></span>
            <div class="product-section-divider-copy">
                <span class="product-section-eyebrow product-section-eyebrow-updating">Currently updating</span>
                <p>These products are temporarily paused while we prepare the latest version.</p>
            </div>
            <span class="product-section-divider-line" aria-hidden="true"></span>
        </div>

        @include('partials.product-card-items', ['products' => $updatingProducts])
    @endif
@endif
