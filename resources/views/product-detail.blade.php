@extends('layouts.app')

@section('content')
    @php
        $stock = $product->available_license_stocks_count ?? 0;
        $discordUrl = config('links.discord_url');
        $isProductReady = $product->status === \App\Models\Product::STATUS_READY;
        $hasAutoDelivery = $isProductReady && $stock > 0;
        $checkoutAvailable = $hasAutoDelivery;
        $dailyPackage = $product->packages->first(fn ($package) => $package->durationDays() === 1);
        $formatIdr = fn ($amount) => 'Rp '.number_format((int) $amount, 0, ',', '.');
        $minPackageIdr = $product->packages->sortBy('price')->first();
        $minPackageUsd = $product->packages
            ->filter(fn ($package) => $package->price_usdt !== null && (float) $package->price_usdt > 0)
            ->sortBy('price_usdt')
            ->first();
        $categoryName = $product->category?->name ?? 'Product';
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
        $salesBadgeLabel = $product->sales_badge_label;
        $salesBadgeVariant = $product->sales_badge_variant ?: 'popular';
        $startDurationDaysIdr = $minPackageIdr?->durationDays();
        $startDurationDaysUsd = $minPackageUsd?->durationDays();
        $startDurationLabelIdr = $startDurationDaysIdr
            ? $startDurationDaysIdr.' '.\Illuminate\Support\Str::plural('day', $startDurationDaysIdr).' access'
            : 'Duration access';
        $startDurationLabelUsd = $startDurationDaysUsd
            ? $startDurationDaysUsd.' '.\Illuminate\Support\Str::plural('day', $startDurationDaysUsd).' access'
            : 'USD price unavailable';
        $packageSavings = $product->packages->mapWithKeys(function ($package) use ($dailyPackage) {
            $days = $package->durationDays();
            $comparisonPrice = $dailyPackage && $days ? ((int) $dailyPackage->price * $days) : 0;
            $saving = max(0, $comparisonPrice - (int) $package->price);
            $hasUsdComparison = $dailyPackage &&
                $days &&
                $dailyPackage->price_usdt !== null &&
                $package->price_usdt !== null &&
                (float) $dailyPackage->price_usdt > 0 &&
                (float) $package->price_usdt > 0;
            $comparisonPriceUsdt = $hasUsdComparison ? ((float) $dailyPackage->price_usdt * $days) : 0;
            $savingUsdt = $hasUsdComparison
                ? max(0, $comparisonPriceUsdt - (float) $package->price_usdt)
                : null;

            return [$package->id => [
                'days' => $days,
                'saving' => $saving,
                'saving_usdt' => $savingUsdt !== null ? round($savingUsdt, 4) : null,
                'percent' => $comparisonPrice > 0 ? (int) round(($saving / $comparisonPrice) * 100) : 0,
                'percent_usdt' => $savingUsdt !== null && $comparisonPriceUsdt > 0
                    ? (int) round(($savingUsdt / $comparisonPriceUsdt) * 100)
                    : null,
                'per_day' => $days ? (int) round($package->price / $days) : null,
                'per_day_usdt' => $days && $package->price_usdt !== null && (float) $package->price_usdt > 0
                    ? round(((float) $package->price_usdt / $days), 4)
                    : null,
            ]];
        });
        $bestValuePackageIdIdr = $packageSavings
            ->filter(fn ($saving) => $saving['saving'] > 0)
            ->sortByDesc('percent')
            ->keys()
            ->first();
        $bestValuePackageIdUsd = $packageSavings
            ->filter(fn ($saving) => ($saving['saving_usdt'] ?? 0) > 0)
            ->sortByDesc('percent_usdt')
            ->keys()
            ->first();
    @endphp

    <div id="content" class="page-shell py-6 md:py-10"
        data-product-checkout-ready="{{ $checkoutAvailable ? 'true' : 'false' }}"
        data-product-stock-endpoint="{{ route('products.stock-detail', $product, false) }}">
        <div class="product-hero mb-6 fade-up">
            <div class="grid gap-5 md:grid-cols-[1fr_340px] md:items-stretch">
                <div class="flex min-w-0 flex-col justify-between gap-5">
                    <div>
                        <a href="/" class="text-sm text-aksa-accent transition hover:text-white">Back to products</a>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <span class="support-pill product-hero-pill">
                                <x-ui.icon :name="$categoryIcon" class="h-4 w-4" />
                                <span>{{ $categoryName }}</span>
                            </span>
                            <span data-product-status-badge
                                class="product-status-badge product-status-badge-static {{ $statusBadgeClass }}">
                                {{ $product->status_label }}
                            </span>
                            @if ($salesBadgeLabel)
                                <span class="sales-signal-badge sales-signal-badge-{{ $salesBadgeVariant }}">
                                    <x-ui.icon name="sparkles" class="h-3.5 w-3.5" />
                                    <span>{{ $salesBadgeLabel }}</span>
                                </span>
                            @endif
                        </div>
                        <h1 class="mt-4 text-3xl font-bold md:text-5xl">{{ $product->name }}</h1>
                        <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                            {{ $product->description }}
                        </p>
                    </div>
                </div>

                <div class="grid gap-3">
                    <div class="product-stat product-stat-featured">
                        <div class="text-xs uppercase text-gray-500">Starts from</div>
                        <div class="mt-2 text-2xl font-bold text-aksa-accent-soft"
                            @if ($minPackageIdr)
                                data-display-price
                                data-price-idr="{{ (int) $minPackageIdr->price }}"
                                data-price-usd="{{ $minPackageUsd ? (float) $minPackageUsd->price_usdt : '' }}"
                            @endif>
                            {{ $minPackageIdr ? $formatIdr($minPackageIdr->price) : '-' }}
                        </div>
                        <div class="mt-1 text-sm text-gray-400"
                            @if ($minPackageIdr)
                                data-currency-text
                                data-currency-text-idr="{{ $startDurationLabelIdr }}"
                                data-currency-text-usd="{{ $startDurationLabelUsd }}"
                            @endif>
                            {{ $minPackageIdr ? $startDurationLabelIdr : 'No package yet' }}
                        </div>
                    </div>

                    <div class="product-stat">
                        <div class="mb-2 text-xs uppercase text-gray-500">Availability</div>
                        <div class="flex items-end justify-between gap-4">
                            <div>
                                <div data-product-availability-value
                                    class="text-2xl font-bold {{ $hasAutoDelivery ? 'text-aksa-accent' : 'text-amber-300' }}">
                                    {{ ! $isProductReady ? 'Updating' : ($hasAutoDelivery ? $stock : 'Manual') }}
                                </div>
                                <div data-product-availability-caption class="text-sm text-gray-400">
                                    {{ ! $isProductReady ? 'update alerts on Discord' : ($hasAutoDelivery ? 'license ready' : 'order via Discord') }}
                                </div>
                            </div>
                            <div data-product-availability-note class="text-right text-xs text-gray-500">
                                {{ ! $isProductReady ? 'Join Discord for update alerts' : ($hasAutoDelivery ? 'Auto delivery after paid' : 'Join Discord to order') }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @include('partials.promo-banner', [
            'promoVoucher' => $promoVoucher ?? null,
            'promoClass' => 'mb-6 fade-up',
        ])

        @if (filled($product->important_note))
            <div class="product-section mb-6 fade-up">
                <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">Please Read</p>
                <h2 class="mt-1 text-xl font-semibold text-white">Important Note</h2>
                <p class="mt-4 max-w-4xl whitespace-pre-line text-sm leading-6 text-gray-300">
                    {{ $product->important_note }}
                </p>
            </div>
        @endif

        <div class="discord-mini-panel mb-6 fade-up">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-sm font-semibold text-white">Need help before buying?</h2>
                    <p class="mt-1 text-sm text-gray-400">
                        Join Discord for vouchers, restock alerts, setup guidance, and checkout help.
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="/downloads"
                        class="inline-flex items-center justify-center rounded-lg border border-[#27272A] px-3 py-2 text-xs font-semibold text-gray-300 transition hover:text-white">
                        <x-ui.icon name="download" class="h-4 w-4" />
                        <span>Downloads</span>
                    </a>
                    <a href="{{ $discordUrl ?: '#' }}"
                        @if ($discordUrl) target="_blank" rel="noopener noreferrer" @endif
                        class="discord-cta px-3 py-2 text-xs {{ $discordUrl ? '' : 'cursor-not-allowed opacity-50' }}">
                        <x-ui.icon name="discord" class="h-4 w-4" />
                        <span>Join Discord</span>
                    </a>
                </div>
            </div>
        </div>

        <section class="product-section mb-6 fade-up">
            <div class="mb-4">
                <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">Step 1</p>
                <h2 class="mt-1 text-xl font-semibold text-white">Select package</h2>
                <p class="mt-1 text-sm text-gray-400">
                    Pick a package and quantity here. Payment method and voucher are selected on the checkout page.
                </p>
            </div>

            <div data-checkout-paused
                class="mb-5 rounded-xl border border-amber-400/30 bg-amber-400/10 p-4 text-sm text-amber-100 {{ $isProductReady ? 'hidden' : '' }}">
                Checkout is temporarily paused while this product is updating. Join Discord for availability alerts.
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($product->packages as $package)
                    @php
                        $packageStock = $package->available_license_stocks_count ?? 0;
                        $packageCheckoutAvailable = $isProductReady && $packageStock > 0;
                        $packageName = str_replace(
                            ['1 Hari', '7 Hari', '30 Hari', 'Hari'],
                            ['1 Day', '7 Days', '30 Days', 'Days'],
                            $package->name
                        );
                        $saving = $packageSavings[$package->id] ?? null;
                        $badgeIdr = $bestValuePackageIdIdr === $package->id;
                        $badgeUsd = $bestValuePackageIdUsd === $package->id;
                    @endphp

                    <article data-package-card data-price="{{ (float) $package->price }}"
                        data-package-id="{{ $package->id }}" data-package-name="{{ $package->name }}"
                        data-price-usdt="{{ $package->price_usdt !== null ? (float) $package->price_usdt : '' }}"
                        data-stock="{{ $packageStock }}"
                        data-package-checkout-enabled="{{ $packageCheckoutAvailable ? 'true' : 'false' }}"
                        aria-disabled="{{ $packageCheckoutAvailable ? 'false' : 'true' }}"
                        role="button" tabindex="{{ $packageCheckoutAvailable ? '0' : '-1' }}"
                        class="package-card package relative p-4 transition {{ $packageCheckoutAvailable ? 'cursor-pointer' : 'cursor-not-allowed opacity-75' }}">
                        @if ($badgeIdr || $badgeUsd)
                            <div class="badge {{ $badgeIdr ? '' : 'hidden' }}" data-currency-visibility
                                data-currency-visible-idr="{{ $badgeIdr ? 'true' : 'false' }}"
                                data-currency-visible-usd="{{ $badgeUsd ? 'true' : 'false' }}">
                                Best Value
                            </div>
                        @endif

                        <div class="package-card-heading">
                            <span class="package-card-icon">
                                <x-ui.icon name="calendar" class="h-4 w-4" />
                            </span>
                            <div class="min-w-0 pr-8">
                                <p class="truncate text-sm font-semibold text-white">{{ $packageName }}</p>
                                <p class="mt-0.5 text-xs text-gray-500">
                                    {{ ($saving['days'] ?? null) ? $saving['days'].' '.\Illuminate\Support\Str::plural('day', $saving['days']).' access' : 'Duration access' }}
                                </p>
                            </div>
                        </div>

                        <div class="package-price-row">
                            <div class="min-w-0">
                                <div class="text-[10px] uppercase tracking-normal text-gray-500">Price</div>
                                <p class="price-text package-price" data-display-price
                                    data-price-idr="{{ (int) $package->price }}"
                                    data-price-usd="{{ $package->price_usdt !== null && (float) $package->price_usdt > 0 ? (float) $package->price_usdt : '' }}">
                                    {{ $formatIdr($package->price) }}
                                </p>
                            </div>
                            @if (($saving['per_day'] ?? null) !== null)
                                <span class="package-per-day" data-display-price
                                    data-price-idr="{{ (int) $saving['per_day'] }}"
                                    data-price-usd="{{ $saving['per_day_usdt'] !== null ? (float) $saving['per_day_usdt'] : '' }}"
                                    data-price-suffix=" per day">
                                    {{ $formatIdr($saving['per_day']) }} per day
                                </span>
                            @endif
                        </div>

                        @if (($saving['saving'] ?? 0) > 0 || ($saving['saving_usdt'] ?? 0) > 0)
                            <div class="package-saving {{ ($saving['saving'] ?? 0) > 0 ? '' : 'hidden' }}"
                                data-currency-visibility
                                data-currency-visible-idr="{{ ($saving['saving'] ?? 0) > 0 ? 'true' : 'false' }}"
                                data-currency-visible-usd="{{ ($saving['saving_usdt'] ?? 0) > 0 ? 'true' : 'false' }}">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-xs font-semibold text-aksa-accent" data-display-price
                                        data-price-idr="{{ (int) $saving['saving'] }}"
                                        data-price-usd="{{ $saving['saving_usdt'] !== null ? (float) $saving['saving_usdt'] : '' }}"
                                        data-price-prefix="Save ">
                                        Save {{ $formatIdr($saving['saving']) }}
                                    </p>
                                    <span class="package-saving-badge" data-currency-text
                                        data-currency-text-idr="{{ $saving['percent'] }}% vs daily"
                                        data-currency-text-usd="{{ (int) ($saving['percent_usdt'] ?? 0) }}% vs daily">
                                        {{ $saving['percent'] }}% vs daily
                                    </span>
                                </div>
                            </div>
                        @endif

                        <p data-package-availability
                            class="package-availability {{ $packageCheckoutAvailable ? 'package-availability-ready' : 'package-availability-manual' }}">
                            <span class="package-availability-dot" aria-hidden="true"></span>
                            <span data-package-availability-label>
                                {{ ! $isProductReady ? 'Checkout paused during update' : ($packageStock > 0 ? $packageStock.' licenses ready' : 'Manual order via Discord') }}
                            </span>
                        </p>

                        <button type="button" data-manual-order data-product-name="{{ $product->name }}"
                            data-package-name="{{ $packageName }}"
                            data-request-mode="{{ $isProductReady ? 'manual-order' : 'update-alert' }}"
                            class="mt-3 {{ $packageCheckoutAvailable ? 'hidden' : 'inline-flex' }} w-full items-center justify-center gap-2 rounded-lg border border-aksa-accent-35 bg-aksa-accent-10 px-3 py-2 text-xs font-semibold text-aksa-accent-soft transition hover:border-aksa-accent hover:bg-aksa-accent-20 hover:text-white">
                            <x-ui.icon name="discord" class="h-4 w-4" />
                            <span data-manual-order-label>
                                {{ $isProductReady ? 'Join Discord to Order' : 'Get Update Alerts' }}
                            </span>
                        </button>
                    </article>
                @endforeach
            </div>
        </section>

        <section id="summaryBox" class="product-summary-card product-summary-sticky hidden fade-up">
            <div class="product-summary-heading mb-4">
                <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">Step 2</p>
                <h2 class="mt-1 text-xl font-semibold text-white">Ready to checkout</h2>
            </div>

            <div class="summary-row summary-product-row mb-2">
                <span>Product</span>
                <span>{{ $product->name }}</span>
            </div>
            <div class="summary-row mb-2">
                <span>Package</span>
                <span id="selectedPackage">-</span>
            </div>
            <div class="summary-row mb-2">
                <span>
                    Quantity
                    <small id="quantityLimit" class="ml-1 text-gray-500">Max: -</small>
                </span>
                <div class="quantity-stepper" aria-label="License quantity">
                    <button id="quantityMinus" type="button" class="quantity-stepper-button"
                        aria-label="Decrease quantity" disabled>−</button>
                    <output id="quantityValue" class="quantity-stepper-value" aria-live="polite">1</output>
                    <button id="quantityPlus" type="button" class="quantity-stepper-button"
                        aria-label="Increase quantity" disabled>+</button>
                </div>
            </div>
            <div class="summary-row">
                <span>Subtotal</span>
                <span id="selectedSubtotal">-</span>
            </div>

            <div class="product-summary-actions mt-5 grid grid-cols-2 gap-2 sm:gap-3">
                <button id="addToCartBtn" type="button"
                    class="btn-footer-secondary min-h-12 w-full {{ ! $checkoutAvailable ? 'cursor-not-allowed opacity-60' : '' }}"
                    @disabled(! $checkoutAvailable)>
                    <x-ui.icon name="shopping-cart" class="h-4 w-4" />
                    <span data-button-label>{{ $checkoutAvailable ? 'Add to Cart' : 'Unavailable' }}</span>
                </button>
                <button id="buyNowBtn" type="button"
                    class="btn-main w-full {{ ! $checkoutAvailable ? 'cursor-not-allowed opacity-60' : '' }}"
                    @disabled(! $checkoutAvailable)>
                    <x-ui.icon name="arrow-right" class="h-4 w-4" />
                    <span data-button-label>{{ $checkoutAvailable ? 'Continue to Checkout' : 'Unavailable' }}</span>
                </button>
            </div>
            <p class="product-summary-helper mt-3 text-xs text-gray-500">
                Continue to Checkout opens payment options. Add to Cart lets you combine packages first.
            </p>
        </section>
    </div>

    @include('partials.recent-purchase-toast', [
        'recentPurchases' => $recentPurchases ?? collect(),
        'recentPurchaseEndpoint' => route('purchases.recent', [], false),
        'recentPurchaseProductSlug' => $product->slug,
    ])
@endsection

@push('scripts')
    <script nonce="{{ request()->attributes->get('csp_nonce') }}">
        (() => {
            const root = document.getElementById('content');
            if (!root) return;

            const pageController = new AbortController();
            const currentProductId = @json((int) $product->id);
            const stockEndpoint = root.dataset.productStockEndpoint || '';
            const addToCartUrl = @json(route('cart.items.store', $product, false));
            const checkoutUrl = @json(route('checkout.product', $product, false));
            const discordUrl = @json($discordUrl);
            const isAuthenticated = @json(auth()->check());
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const maxCheckoutQuantity = @json(\App\Services\CartService::MAX_TOTAL_QUANTITY);
            let productReady = @json($isProductReady);
            let productUnavailable = false;
            let selectedPackage = null;
            let selectedQuantity = 1;
            let stockTimer = null;
            let stockRequest = null;
            let addRequestPending = false;

            const formatIdr = value => `Rp ${Number(value).toLocaleString('id-ID')}`;
            const formatUsd = value => `$${Number(value).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 4,
            })}`;

            function toast(title, message, variant = 'info') {
                window.showAppToast?.(title, message, { variant });
            }

            function buttonLabel(button, label) {
                const target = button?.querySelector('[data-button-label]');
                if (target) target.textContent = label;
            }

            function normalizedPackageName(name) {
                return String(name || '')
                    .replace('1 Hari', '1 Day')
                    .replace('7 Hari', '7 Days')
                    .replace('30 Hari', '30 Days')
                    .replace('Hari', 'Days');
            }

            function selectionAvailable() {
                return productReady &&
                    selectedPackage &&
                    selectedPackage.stock >= selectedQuantity &&
                    selectedPackage.enabled;
            }

            function renderSelection() {
                const summary = document.getElementById('summaryBox');
                if (!selectedPackage) {
                    summary.classList.add('hidden');
                    return;
                }

                const maxQuantity = Math.max(
                    1,
                    Math.min(selectedPackage.stock, maxCheckoutQuantity)
                );
                selectedQuantity = Math.min(selectedQuantity, maxQuantity);
                summary.classList.remove('hidden');
                document.getElementById('selectedPackage').textContent =
                    normalizedPackageName(selectedPackage.name);
                document.getElementById('quantityValue').textContent = selectedQuantity;
                document.getElementById('quantityLimit').textContent = `Max: ${maxQuantity}`;
                document.getElementById('quantityMinus').disabled = selectedQuantity <= 1;
                document.getElementById('quantityPlus').disabled = selectedQuantity >= maxQuantity;
                const displayCurrency = window.getAksaDisplayCurrency?.() ||
                    document.documentElement.dataset.displayCurrency ||
                    'idr';
                document.getElementById('selectedSubtotal').textContent =
                    displayCurrency === 'usd'
                        ? (selectedPackage.priceUsdt > 0
                            ? formatUsd(selectedPackage.priceUsdt * selectedQuantity)
                            : 'USD unavailable')
                        : formatIdr(selectedPackage.price * selectedQuantity);

                const available = selectionAvailable();
                const addButton = document.getElementById('addToCartBtn');
                const buyButton = document.getElementById('buyNowBtn');
                addButton.disabled = !available || addRequestPending;
                buyButton.disabled = !available;
                addButton.classList.toggle('opacity-60', !available || addRequestPending);
                buyButton.classList.toggle('opacity-60', !available);

                if (!addRequestPending) {
                    buttonLabel(addButton, available ? 'Add to Cart' : 'Unavailable');
                }
                buttonLabel(buyButton, available ? 'Continue to Checkout' : 'Unavailable');
            }

            function choosePackage(card) {
                if (!productReady || card.dataset.packageCheckoutEnabled !== 'true') {
                    toast(
                        productUnavailable ? 'Product unavailable' : 'Package unavailable',
                        productReady
                            ? 'Automatic delivery is not available for this package. Join Discord for help.'
                            : 'Checkout is paused while this product is updating.',
                        'warning'
                    );
                    return;
                }

                selectedPackage = {
                    id: Number(card.dataset.packageId),
                    name: card.dataset.packageName || '',
                    price: Number(card.dataset.price || 0),
                    priceUsdt: card.dataset.priceUsdt === ''
                        ? null
                        : Number(card.dataset.priceUsdt),
                    stock: Number(card.dataset.stock || 0),
                    enabled: true,
                };
                selectedQuantity = 1;
                document.querySelectorAll('[data-package-card]').forEach(item => {
                    item.classList.toggle('active', item === card);
                });
                renderSelection();
                document.getElementById('summaryBox')?.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest',
                });
            }

            function applyStockSnapshot(payload) {
                const snapshot = payload?.product || payload;
                if (!snapshot || Number(snapshot.id) !== currentProductId || !Array.isArray(snapshot.packages)) {
                    return;
                }

                const status = String(snapshot.status || '').toLowerCase();
                const availableStock = Number(snapshot.available_stock || 0);
                productReady = status === 'ready';
                productUnavailable = status === 'unavailable';

                const statusBadge = document.querySelector('[data-product-status-badge]');
                if (statusBadge) {
                    statusBadge.textContent = snapshot.status_label || (productReady ? 'Ready' : 'Updating');
                    statusBadge.classList.toggle('product-status-badge-ready', productReady);
                    statusBadge.classList.toggle('product-status-badge-updating', !productReady);
                }

                const hasAutomaticDelivery = productReady && availableStock > 0;
                const availabilityValue = document.querySelector('[data-product-availability-value]');
                const availabilityCaption = document.querySelector('[data-product-availability-caption]');
                const availabilityNote = document.querySelector('[data-product-availability-note]');
                availabilityValue.textContent = productUnavailable
                    ? 'Unavailable'
                    : (productReady ? (availableStock > 0 ? String(availableStock) : 'Manual') : 'Updating');
                availabilityValue.classList.toggle('text-aksa-accent', hasAutomaticDelivery);
                availabilityValue.classList.toggle('text-amber-300', !hasAutomaticDelivery);
                availabilityCaption.textContent = productUnavailable
                    ? 'not currently available'
                    : (productReady ? (availableStock > 0 ? 'license ready' : 'order via Discord') : 'update alerts on Discord');
                availabilityNote.textContent = productUnavailable
                    ? 'Browse other products'
                    : (productReady ? (availableStock > 0 ? 'Auto delivery after paid' : 'Join Discord to order') : 'Join Discord for update alerts');

                const paused = document.querySelector('[data-checkout-paused]');
                paused.classList.toggle('hidden', productReady);
                paused.textContent = productUnavailable
                    ? 'This product is no longer available. Please choose another product.'
                    : 'Checkout is temporarily paused while this product is updating. Join Discord for availability alerts.';

                const stocks = new Map(snapshot.packages.map(item => [
                    Number(item.id),
                    Number(item.available_stock || 0),
                ]));

                document.querySelectorAll('[data-package-card]').forEach(card => {
                    const id = Number(card.dataset.packageId);
                    const packageStock = stocks.get(id) || 0;
                    const enabled = productReady && packageStock > 0;
                    card.dataset.stock = String(packageStock);
                    card.dataset.packageCheckoutEnabled = enabled ? 'true' : 'false';
                    card.setAttribute('aria-disabled', enabled ? 'false' : 'true');
                    card.classList.toggle('cursor-pointer', enabled);
                    card.classList.toggle('cursor-not-allowed', !enabled);
                    card.classList.toggle('opacity-75', !enabled);

                    const availability = card.querySelector('[data-package-availability]');
                    availability.classList.toggle('package-availability-ready', enabled);
                    availability.classList.toggle('package-availability-manual', !enabled);
                    card.querySelector('[data-package-availability-label]').textContent = productUnavailable
                        ? 'Product unavailable'
                        : (!productReady
                            ? 'Checkout paused during update'
                            : (packageStock > 0 ? `${packageStock} licenses ready` : 'Manual order via Discord'));

                    const manual = card.querySelector('[data-manual-order]');
                    manual.classList.toggle('hidden', enabled);
                    manual.classList.toggle('inline-flex', !enabled);
                    manual.dataset.requestMode = productReady || productUnavailable ? 'manual-order' : 'update-alert';
                    manual.querySelector('[data-manual-order-label]').textContent = productUnavailable
                        ? 'Contact Support'
                        : (productReady ? 'Join Discord to Order' : 'Get Update Alerts');

                    if (selectedPackage?.id === id) {
                        selectedPackage.stock = packageStock;
                        selectedPackage.enabled = enabled;

                        if (!enabled) {
                            card.classList.remove('active');
                        }
                    }
                });

                renderSelection();
            }

            async function refreshStock() {
                if (document.hidden || !stockEndpoint || stockRequest || pageController.signal.aborted) return;

                stockRequest = new AbortController();

                try {
                    const response = await fetch(stockEndpoint, {
                        cache: 'no-store',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        signal: stockRequest.signal,
                    });

                    if (response.status === 404) {
                        applyStockSnapshot({
                            id: currentProductId,
                            status: 'unavailable',
                            status_label: 'Unavailable',
                            available_stock: 0,
                            packages: [],
                        });
                        return;
                    }

                    if (response.ok) {
                        applyStockSnapshot(await response.json());
                    }
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        // Best effort only; checkout validates stock again on the server.
                    }
                } finally {
                    stockRequest = null;
                }
            }

            function scheduleStock() {
                clearTimeout(stockTimer);
                if (document.hidden || pageController.signal.aborted) return;

                stockTimer = setTimeout(async () => {
                    await refreshStock();
                    scheduleStock();
                }, 30000);
            }

            async function manualOrder(button) {
                const updateAlert = button.dataset.requestMode === 'update-alert';
                const message = updateAlert
                    ? `Please notify me when ${button.dataset.productName} - ${button.dataset.packageName} is ready again.`
                    : `I want to buy ${button.dataset.productName} - ${button.dataset.packageName}. Is manual order available?`;

                if (discordUrl) window.open(discordUrl, '_blank', 'noopener');

                try {
                    await navigator.clipboard.writeText(message);
                    toast('Message copied', 'Paste it in Discord when the server opens.', 'success');
                } catch (error) {
                    toast('Open Discord', 'Send the product and package name to support.', 'warning');
                }
            }

            document.querySelectorAll('[data-package-card]').forEach(card => {
                card.addEventListener('click', event => {
                    if (event.target.closest('[data-manual-order]')) return;
                    choosePackage(card);
                }, { signal: pageController.signal });
                card.addEventListener('keydown', event => {
                    if (event.key !== 'Enter' && event.key !== ' ') return;
                    event.preventDefault();
                    choosePackage(card);
                }, { signal: pageController.signal });
            });

            document.querySelectorAll('[data-manual-order]').forEach(button => {
                button.addEventListener('click', event => {
                    event.stopPropagation();
                    manualOrder(button);
                }, { signal: pageController.signal });
            });

            document.getElementById('quantityMinus')?.addEventListener('click', () => {
                selectedQuantity = Math.max(1, selectedQuantity - 1);
                renderSelection();
            }, { signal: pageController.signal });

            document.getElementById('quantityPlus')?.addEventListener('click', () => {
                if (!selectedPackage) return;
                selectedQuantity = Math.min(
                    selectedPackage.stock,
                    maxCheckoutQuantity,
                    selectedQuantity + 1
                );
                renderSelection();
            }, { signal: pageController.signal });

            document.getElementById('buyNowBtn')?.addEventListener('click', () => {
                if (!selectionAvailable()) return;
                const url = new URL(checkoutUrl, window.location.origin);
                url.searchParams.set('package', String(selectedPackage.id));
                url.searchParams.set('quantity', String(selectedQuantity));
                window.location.href = isAuthenticated
                    ? url.toString()
                    : `/auth/google?redirect=${encodeURIComponent(url.pathname + url.search)}`;
            }, { signal: pageController.signal });

            document.getElementById('addToCartBtn')?.addEventListener('click', async function() {
                if (!selectionAvailable() || addRequestPending) return;

                if (!isAuthenticated) {
                    toast('Login required', 'Login first to save packages in your cart.', 'warning');
                    window.location.href = `/auth/google?redirect=${encodeURIComponent(window.location.pathname)}`;
                    return;
                }

                addRequestPending = true;
                this.disabled = true;
                buttonLabel(this, 'Adding...');
                const body = new FormData();
                body.set('package_id', String(selectedPackage.id));
                body.set('quantity', String(selectedQuantity));

                try {
                    const response = await fetch(addToCartUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body,
                    });
                    const data = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        throw new Error(data.message || 'The package could not be added to your cart.');
                    }

                    document.querySelectorAll('[data-cart-count]').forEach(badge => {
                        badge.textContent = data.cart_count;
                        badge.classList.toggle('hidden', Number(data.cart_count) < 1);
                    });
                    toast('Added to cart', data.message, 'success');
                    buttonLabel(this, 'Added');
                    setTimeout(() => {
                        addRequestPending = false;
                        renderSelection();
                    }, 900);
                } catch (error) {
                    addRequestPending = false;
                    toast('Cart not updated', error.message, 'error');
                    renderSelection();
                }
            }, { signal: pageController.signal });

            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    clearTimeout(stockTimer);
                    stockRequest?.abort();
                    stockRequest = null;
                } else {
                    refreshStock().finally(scheduleStock);
                }
            }, { signal: pageController.signal });

            window.addEventListener('aksa:currency-change', renderSelection, {
                signal: pageController.signal,
            });

            window.addEventListener('aksa:before-page-swap', () => {
                clearTimeout(stockTimer);
                stockRequest?.abort();
                pageController.abort();
            }, { once: true });

            scheduleStock();
        })();
    </script>
@endpush
