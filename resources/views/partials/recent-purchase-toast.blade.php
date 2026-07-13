@php
    $purchaseFeed = collect($recentPurchases ?? [])
        ->filter(fn ($purchase) => filled($purchase['product'] ?? null))
        ->values();
    $recentPurchaseEndpoint = $recentPurchaseEndpoint ?? route('purchases.recent', [], false);
    $recentPurchaseProductSlug = trim((string) ($recentPurchaseProductSlug ?? ''));
@endphp

<aside class="recent-purchase-toast" data-recent-purchase-toast
    data-recent-purchase-endpoint="{{ $recentPurchaseEndpoint }}"
    @if ($recentPurchaseProductSlug !== '') data-recent-purchase-product-slug="{{ $recentPurchaseProductSlug }}" @endif
    hidden aria-live="polite" aria-atomic="true">
    <template data-recent-purchase-data>@json($purchaseFeed)</template>

    <div class="recent-purchase-icon">
        <x-ui.icon name="shopping-cart" class="h-4 w-4" />
    </div>

    <div class="min-w-0 flex-1">
        <div class="recent-purchase-eyebrow">Recent purchase</div>
        <div class="recent-purchase-title">
            <span data-recent-purchase-buyer>Customer</span>
            <span>bought</span>
        </div>
        <div class="recent-purchase-product" data-recent-purchase-product>Product</div>
        <div class="recent-purchase-meta">
            <span data-recent-purchase-package>License</span>
            <span aria-hidden="true">·</span>
            <span data-recent-purchase-time>recently</span>
        </div>
    </div>

    <button type="button" class="recent-purchase-close" data-recent-purchase-close
        aria-label="Hide recent purchase notification">
        <x-ui.icon name="x" class="h-4 w-4" />
    </button>
</aside>
