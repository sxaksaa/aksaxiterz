@extends('layouts.app')

@section('seo_title', $product->name.' License - Aksa Xiterz')
@section('seo_description', \Illuminate\Support\Str::limit(strip_tags($product->description), 155))
@section('seo_type', 'product')

@section('content')
    @php
        $stock = $product->available_license_stocks_count ?? 0;
        $discordUrl = config('links.discord_url');
        $isProductReady = $product->status === \App\Models\Product::STATUS_READY;
        $hasAutoDelivery = $isProductReady && $stock > 0;
        $checkoutAvailable = $hasAutoDelivery;
        $dailyPackage = $product->packages->first(fn ($package) => $package->durationDays() === 1);
        $formatIdr = fn ($amount) => 'Rp '.number_format((int) $amount, 0, ',', '.');
        $categoryName = $product->category?->name ?? 'Product';
        $categoryKey = strtolower(trim($categoryName));
        $categoryIcon = match ($categoryKey) {
            'pc', 'desktop', 'windows' => 'monitor',
            'ios', 'iphone', 'ipad', 'macos' => 'apple',
            'android' => 'android',
            default => 'box',
        };
        $salesBadgeLabel = $product->sales_badge_label;
        $salesBadgeVariant = $product->sales_badge_variant ?: 'popular';
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
        <div class="product-hero mb-6">
            <div>
                <a href="/" data-soft-nav data-soft-nav-back
                    class="text-sm text-aksa-accent transition hover:text-white">Back to products</a>
                <div class="mt-4 flex flex-wrap gap-2">
                    <span class="support-pill product-hero-pill">
                        <x-ui.icon :name="$categoryIcon" class="h-4 w-4" />
                        <span>{{ $categoryName }}</span>
                    </span>
                    <span data-product-status-badge
                        class="product-status-badge product-status-badge-static {{ $isProductReady ? 'hidden' : 'product-status-badge-updating' }}">
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

        @include('partials.promo-banner', [
            'promoVoucher' => $promoVoucher ?? null,
            'promoClass' => 'mb-6 fade-up',
        ])

        @if (filled($product->important_note))
            <div class="product-section mb-6 fade-up" data-scroll-reveal>
                <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">Please Read</p>
                <h2 class="mt-1 text-xl font-semibold text-white">Important Note</h2>
                <p class="mt-4 max-w-4xl whitespace-pre-line text-sm leading-6 text-gray-300">
                    {{ $product->important_note }}
                </p>
            </div>
        @endif

        <section class="product-section mb-6 fade-up">
            <div class="mb-4">
                <h2 class="text-xl font-semibold text-white">Select package</h2>
                <p class="mt-1 text-sm text-gray-400">
                    Choose a package to continue to checkout.
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

                    <article data-package-card data-scroll-reveal data-price="{{ (float) $package->price }}"
                        data-package-id="{{ $package->id }}" data-package-name="{{ $package->name }}"
                        data-price-usdt="{{ $package->price_usdt !== null ? (float) $package->price_usdt : '' }}"
                        data-stock="{{ $packageStock }}"
                        data-package-checkout-enabled="{{ $packageCheckoutAvailable ? 'true' : 'false' }}"
                        aria-disabled="{{ $packageCheckoutAvailable ? 'false' : 'true' }}"
                        role="button" tabindex="{{ $packageCheckoutAvailable ? '0' : '-1' }}"
                        class="package-card package relative flex flex-col p-4 transition {{ $packageCheckoutAvailable ? 'cursor-pointer' : 'cursor-not-allowed opacity-75' }}">
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
                            </div>
                        </div>

                        <div class="package-price-row">
                            <div class="min-w-0">
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
                            <div class="mt-3 {{ ($saving['saving'] ?? 0) > 0 ? '' : 'hidden' }}"
                                data-currency-visibility
                                data-currency-visible-idr="{{ ($saving['saving'] ?? 0) > 0 ? 'true' : 'false' }}"
                                data-currency-visible-usd="{{ ($saving['saving_usdt'] ?? 0) > 0 ? 'true' : 'false' }}">
                                <span class="package-saving-badge" data-currency-text
                                    data-currency-text-idr="Save {{ $saving['percent'] }}% vs daily"
                                    data-currency-text-usd="Save {{ (int) ($saving['percent_usdt'] ?? 0) }}% vs daily">
                                    Save {{ $saving['percent'] }}% vs daily
                                </span>
                            </div>
                        @endif

                        <p data-package-availability
                            class="package-availability mt-auto border-t border-white/5 pt-3 {{ $packageCheckoutAvailable ? 'package-availability-ready' : 'package-availability-manual' }}">
                            <span class="package-availability-dot" aria-hidden="true"></span>
                            <span data-package-availability-label>
                                {{ ! $isProductReady ? 'Checkout paused during update' : ($packageStock > 0 ? $packageStock.' available · Auto delivery' : 'Manual order via Discord') }}
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

            <div class="product-summary-details grid grid-cols-1 gap-2 md:grid-cols-2 lg:grid-cols-4">
                <div class="summary-row summary-product-row">
                    <span>Product</span>
                    <span>{{ $product->name }}</span>
                </div>
                <div class="summary-row">
                    <span>Package</span>
                    <span id="selectedPackage">-</span>
                </div>
                <div class="summary-row">
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

        <aside id="mobileCheckoutBar" class="mobile-checkout-bar" aria-hidden="true">
            <div class="min-w-0">
                <p id="mobileSelectedPackage" class="truncate text-xs text-gray-400">Select package</p>
                <p id="mobileSelectedSubtotal" class="mt-0.5 text-base font-bold text-white">-</p>
            </div>
            <button id="mobileBuyNowBtn" type="button" class="btn-main min-h-11 shrink-0 px-5" disabled>
                Checkout
            </button>
        </aside>
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
            const packageMemoryKey = `aksa:last-package:${currentProductId}`;
            let productReady = @json($isProductReady);
            let productUnavailable = false;
            let selectedPackage = null;
            let selectedQuantity = 1;
            let stockTimer = null;
            let stockRequest = null;
            let addRequestPending = false;
            let quantityDirection = null;

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
                const mobileBar = document.getElementById('mobileCheckoutBar');
                if (!selectedPackage) {
                    summary.classList.add('hidden');
                    mobileBar?.classList.remove('is-visible');
                    mobileBar?.setAttribute('aria-hidden', 'true');
                    document.body.classList.remove('mobile-checkout-open');
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
                document.getElementById('mobileSelectedPackage').textContent =
                    normalizedPackageName(selectedPackage.name);
                const quantityValue = document.getElementById('quantityValue');
                quantityValue.textContent = selectedQuantity;
                if (quantityDirection) {
                    quantityValue.classList.remove('quantity-change-up', 'quantity-change-down');
                    void quantityValue.offsetWidth;
                    quantityValue.classList.add(`quantity-change-${quantityDirection}`);
                    setTimeout(() => quantityValue.classList.remove('quantity-change-up', 'quantity-change-down'), 280);
                    quantityDirection = null;
                }
                document.getElementById('quantityLimit').textContent = `Max: ${maxQuantity}`;
                document.getElementById('quantityMinus').disabled = selectedQuantity <= 1;
                document.getElementById('quantityPlus').disabled = selectedQuantity >= maxQuantity;
                const displayCurrency = window.getAksaDisplayCurrency?.() ||
                    document.documentElement.dataset.displayCurrency ||
                    'idr';
                const subtotal = document.getElementById('selectedSubtotal');
                const nextSubtotal = displayCurrency === 'usd'
                        ? (selectedPackage.priceUsdt > 0
                            ? formatUsd(selectedPackage.priceUsdt * selectedQuantity)
                            : 'USD unavailable')
                        : formatIdr(selectedPackage.price * selectedQuantity);
                if (subtotal.textContent !== nextSubtotal && subtotal.textContent !== '-') {
                    subtotal.classList.remove('aksa-price-changing');
                    void subtotal.offsetWidth;
                    subtotal.classList.add('aksa-price-changing');
                    setTimeout(() => subtotal.classList.remove('aksa-price-changing'), 420);
                }
                subtotal.textContent = nextSubtotal;
                document.getElementById('mobileSelectedSubtotal').textContent = nextSubtotal;

                const available = selectionAvailable();
                const addButton = document.getElementById('addToCartBtn');
                const buyButton = document.getElementById('buyNowBtn');
                addButton.disabled = !available || addRequestPending;
                buyButton.disabled = !available;
                document.getElementById('mobileBuyNowBtn').disabled = !available;
                addButton.classList.toggle('opacity-60', !available || addRequestPending);
                buyButton.classList.toggle('opacity-60', !available);

                if (!addRequestPending) {
                    buttonLabel(addButton, available ? 'Add to Cart' : 'Unavailable');
                }
                buttonLabel(buyButton, available ? 'Continue to Checkout' : 'Unavailable');
                mobileBar?.classList.add('is-visible');
                mobileBar?.setAttribute('aria-hidden', 'false');
                document.body.classList.add('mobile-checkout-open');
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
                try {
                    sessionStorage.setItem(packageMemoryKey, String(selectedPackage.id));
                } catch (error) {
                    // Package selection still works when storage is unavailable.
                }
                selectedQuantity = 1;
                document.querySelectorAll('[data-package-card]').forEach(item => {
                    item.classList.toggle('active', item === card);
                });
                card.classList.remove('package-selection-pop');
                void card.offsetWidth;
                card.classList.add('package-selection-pop');
                setTimeout(() => card.classList.remove('package-selection-pop'), 420);
                renderSelection();
                const checkoutButton = document.getElementById('buyNowBtn');
                checkoutButton?.classList.remove('checkout-ready-shimmer');
                if (checkoutButton && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    void checkoutButton.offsetWidth;
                    checkoutButton.classList.add('checkout-ready-shimmer');
                    setTimeout(() => checkoutButton.classList.remove('checkout-ready-shimmer'), 1000);
                }
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
                    statusBadge.classList.toggle('hidden', productReady);
                    statusBadge.classList.toggle('product-status-badge-updating', !productReady);
                }

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
                    const previousStock = Number(card.dataset.stock || 0);
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
                    const availabilityLabel = card.querySelector('[data-package-availability-label]');
                    availabilityLabel.textContent = productUnavailable
                        ? 'Product unavailable'
                        : (!productReady
                            ? 'Checkout paused during update'
                            : (packageStock > 0 ? `${packageStock} available · Auto delivery` : 'Manual order via Discord'));
                    if (previousStock !== packageStock) {
                        availabilityLabel.classList.remove('package-stock-changed');
                        void availabilityLabel.offsetWidth;
                        availabilityLabel.classList.add('package-stock-changed');
                        setTimeout(() => availabilityLabel.classList.remove('package-stock-changed'), 620);
                    }

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

            try {
                const rememberedPackageId = sessionStorage.getItem(packageMemoryKey);
                const rememberedCard = rememberedPackageId
                    ? document.querySelector(`[data-package-card][data-package-id="${CSS.escape(rememberedPackageId)}"]`)
                    : null;

                if (rememberedCard) {
                    sessionStorage.removeItem(packageMemoryKey);
                    rememberedCard.classList.add('package-return-highlight');
                    setTimeout(() => rememberedCard.classList.remove('package-return-highlight'), 1200);
                }
            } catch (error) {
                // Returning to the page remains functional when storage is unavailable.
            }

            document.querySelectorAll('[data-manual-order]').forEach(button => {
                button.addEventListener('click', event => {
                    event.stopPropagation();
                    manualOrder(button);
                }, { signal: pageController.signal });
            });

            document.getElementById('quantityMinus')?.addEventListener('click', () => {
                quantityDirection = 'down';
                selectedQuantity = Math.max(1, selectedQuantity - 1);
                renderSelection();
            }, { signal: pageController.signal });

            document.getElementById('quantityPlus')?.addEventListener('click', () => {
                if (!selectedPackage) return;
                quantityDirection = 'up';
                selectedQuantity = Math.min(
                    selectedPackage.stock,
                    maxCheckoutQuantity,
                    selectedQuantity + 1
                );
                renderSelection();
            }, { signal: pageController.signal });

            document.getElementById('buyNowBtn')?.addEventListener('click', event => {
                if (!selectionAvailable()) return;
                const url = new URL(checkoutUrl, window.location.origin);
                url.searchParams.set('package', String(selectedPackage.id));
                url.searchParams.set('quantity', String(selectedQuantity));
                window.pulseAksaSuccess?.(event.currentTarget);
                setTimeout(() => {
                    window.location.href = isAuthenticated
                        ? url.toString()
                        : `/auth/google?redirect=${encodeURIComponent(url.pathname + url.search)}`;
                }, 240);
            }, { signal: pageController.signal });

            document.getElementById('mobileBuyNowBtn')?.addEventListener('click', () => {
                const mobileButton = document.getElementById('mobileBuyNowBtn');
                window.pulseAksaSuccess?.(mobileButton);
                setTimeout(() => document.getElementById('buyNowBtn')?.click(), 120);
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

                    const previousCartCount = Number(document.querySelector('[data-cart-count]')?.textContent || 0);
                    document.querySelectorAll('[data-cart-count]').forEach(badge => {
                        badge.textContent = data.cart_count;
                        badge.classList.toggle('hidden', Number(data.cart_count) < 1);
                    });
                    await window.animateAksaCartTransfer?.(
                        document.querySelector('[data-package-card].active') || this
                    );
                    window.refreshAksaMiniCart?.(data.cart_preview_html, data.cart_count, {
                        autoOpen: true,
                        bumpBadge: true,
                        firstItem: previousCartCount === 0 && Number(data.cart_count) > 0,
                        highlightItemId: data.item_id,
                    });
                    toast('Added to cart', data.message, 'success');
                    buttonLabel(this, 'Added');
                    window.pulseAksaSuccess?.(this);
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
                document.body.classList.remove('mobile-checkout-open');
                clearTimeout(stockTimer);
                stockRequest?.abort();
                pageController.abort();
            }, { once: true });

            scheduleStock();
        })();
    </script>
@endpush
