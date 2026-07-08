@extends('layouts.app')

@section('content')
    @php
        $stock = $product->available_license_stocks_count ?? 0;
        $discordUrl = config('links.discord_url');
        $hasAutoDelivery = $stock > 0;
        $binancePayConfigured = (bool) config('services.binance.pay.enabled') &&
            filled(config('services.binance.pay.pay_id')) &&
            filled(config('services.binance.pay.api_key')) &&
            filled(config('services.binance.pay.api_secret'));
        $binancePayAvailable = app()->environment('local') || $binancePayConfigured;
        $dailyPackage = $product->packages->first(fn ($package) => $package->durationDays() === 1);
        $formatUsdCompact = function ($amount) {
            $amount = (float) $amount;
            $formatted = abs($amount - round($amount)) < 0.005
                ? number_format($amount, 0)
                : number_format($amount, 2);

            return '$'.$formatted;
        };
        $minPackage = $product->packages->sortBy('price')->first();
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
        $needsUpdateAlerts = $product->status === \App\Models\Product::STATUS_UPDATING;
        $salesBadgeLabel = $product->sales_badge_label;
        $salesBadgeVariant = $product->sales_badge_variant ?: 'popular';
        $startDurationDays = $minPackage?->durationDays();
        $startDurationLabel = $startDurationDays
            ? $startDurationDays . ' ' . \Illuminate\Support\Str::plural('day', $startDurationDays) . ' access'
            : 'Duration access';
        $packageSavings = $product->packages->mapWithKeys(function ($package) use ($dailyPackage) {
            $days = $package->durationDays();
            $comparisonPrice = $dailyPackage && $days ? ((int) $dailyPackage->price * $days) : 0;
            $saving = max(0, $comparisonPrice - (int) $package->price);
            $comparisonPriceUsdt = $dailyPackage && $days ? ((float) $dailyPackage->price_usdt * $days) : 0;
            $savingUsdt = max(0, $comparisonPriceUsdt - (float) $package->price_usdt);

            return [$package->id => [
                'days' => $days,
                'saving' => $saving,
                'saving_usdt' => round($savingUsdt, 2),
                'percent' => $comparisonPrice > 0 ? (int) round(($saving / $comparisonPrice) * 100) : 0,
                'percent_usdt' => $comparisonPriceUsdt > 0 ? (int) round(($savingUsdt / $comparisonPriceUsdt) * 100) : 0,
                'per_day' => $days ? (int) round($package->price / $days) : null,
                'per_day_usdt' => $days ? round(((float) $package->price_usdt / $days), 2) : null,
            ]];
        });
        $bestValuePackageId = $packageSavings
            ->filter(fn ($saving) => $saving['saving'] > 0)
            ->sortByDesc('percent')
            ->keys()
            ->first();
    @endphp

    <div id="content" class="page-shell py-6 md:py-10">

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
                            <span class="product-status-badge product-status-badge-static {{ $statusBadgeClass }}">
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
                        <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">{{ $product->description }}</p>
                    </div>
                </div>

                <div class="grid gap-3">
                    <div class="product-stat product-stat-featured">
                        <div class="text-xs uppercase text-gray-500">Starts from</div>
                        <div class="mt-2 text-2xl font-bold text-aksa-accent-soft">
                            {{ $minPackage ? 'Rp ' . number_format($minPackage->price) : '-' }}
                        </div>
                        <div class="mt-1 text-sm text-gray-400">
                            {{ $minPackage ? $formatUsdCompact($minPackage->price_usdt) . ' · ' . $startDurationLabel : 'No package yet' }}
                        </div>
                    </div>

                    <div class="product-stat">
                        <div class="mb-2 text-xs uppercase text-gray-500">Availability</div>
                        <div class="flex items-end justify-between gap-4">
                            <div>
                                <div class="text-2xl font-bold {{ $hasAutoDelivery ? 'text-aksa-accent' : 'text-amber-300' }}">
                                    {{ $hasAutoDelivery ? $stock : 'Manual' }}
                                </div>
                                <div class="text-sm text-gray-400">
                                    {{ $needsUpdateAlerts ? 'update alerts on Discord' : ($hasAutoDelivery ? 'license ready' : 'order via Discord') }}
                                </div>
                            </div>
                            <div class="text-right text-xs text-gray-500">
                                {{ $needsUpdateAlerts ? 'Join Discord for update alerts' : ($hasAutoDelivery ? 'Auto delivery after paid' : 'Join Discord to order') }}
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
                <div class="mb-4">
                    <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">Please Read</p>
                    <h3 class="mt-1 text-xl font-semibold text-white">Important Note</h3>
                </div>
                <p class="max-w-4xl whitespace-pre-line text-sm leading-6 text-gray-300">{{ $product->important_note }}</p>
            </div>
        @endif

        <div class="discord-mini-panel mb-8 fade-up">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-sm font-semibold text-white">Need help before checkout?</h2>
                    <p class="mt-1 text-sm text-gray-400">
                        Join Discord for member vouchers, restock alerts, setup guidance, license resets, and checkout help.
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

        <div id="checkout" class="product-section mb-6 scroll-mt-28 fade-up">
            <div class="mb-4">
                <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">Checkout</p>
                <h2 class="mt-1 text-xl font-semibold text-white">Payment Method</h2>
                <p class="mt-1 text-sm text-gray-400">Choose a payment method before selecting your package.</p>
            </div>

        <div class="grid grid-cols-1 {{ $binancePayAvailable ? 'sm:grid-cols-3' : 'sm:grid-cols-2' }} gap-3 md:gap-4 mb-8">

            <div id="btnPakasir" data-payment-method="pakasir"
                class="checkout-card p-5 cursor-pointer payment-card flex flex-col gap-1">

                <div class="payment-card-heading">
                    <span class="payment-card-icon">
                        <x-ui.icon name="qr-code" class="h-5 w-5" />
                    </span>
                    <div class="font-semibold">QRIS</div>
                </div>
                <span class="text-xs text-gray-400">QRIS for Indonesia & Malaysia-supported wallets</span>

            </div>

            @if ($binancePayAvailable)
                <div id="btnBinancePay" data-payment-method="binance_pay"
                    class="checkout-card p-5 cursor-pointer payment-card flex flex-col gap-1">

                    <div class="payment-card-heading">
                        <span class="payment-card-icon">
                            <x-ui.icon name="binance" class="h-5 w-5 text-[#F0B90B]" />
                        </span>
                        <div class="font-semibold">Binance Pay</div>
                    </div>
                    <span class="text-xs text-gray-400">Binance Pay ID & QR</span>

                </div>
            @endif

            <div id="btnCrypto" data-payment-method="crypto"
                class="checkout-card p-5 cursor-pointer payment-card flex flex-col gap-1">

                <div class="payment-card-heading">
                    <span class="payment-card-icon">
                        <x-ui.icon name="wallet" class="h-5 w-5" />
                    </span>
                    <div class="font-semibold">Crypto</div>
                </div>
                <span class="text-xs text-gray-400">Crypto Wallet Address</span>

            </div>

        </div>

        @if ($binancePayAvailable)
            <div id="binancePayBox" class="hidden relative mb-6 fade-up z-10">
                <p class="mb-2 text-xs font-semibold uppercase tracking-normal text-gray-400">Coin</p>
                <div class="grid grid-cols-2 gap-2" role="group" aria-label="Select Binance Pay coin">
                    <button type="button" class="crypto-coin-option" data-binance-pay-token="usdt" aria-pressed="false">
                        <span class="crypto-coin-header">
                            <x-ui.icon name="tether" class="crypto-token-icon" />
                            <span class="crypto-token-copy">
                                <span class="crypto-token-title">USDT</span>
                                <span class="crypto-token-subtitle">Tether</span>
                            </span>
                        </span>
                    </button>
                    <button type="button" class="crypto-coin-option" data-binance-pay-token="usdc" aria-pressed="false">
                        <span class="crypto-coin-header">
                            <x-ui.icon name="usdc" class="crypto-token-icon" />
                            <span class="crypto-token-copy">
                                <span class="crypto-token-title">USDC</span>
                                <span class="crypto-token-subtitle">USD Coin</span>
                            </span>
                        </span>
                    </button>
                </div>
                <p class="mt-2 text-xs text-gray-500">Binance Pay transfer only. No blockchain network selection is needed.</p>
            </div>
        @endif

        <!-- CRYPTO -->
        <div id="cryptoBox" class="hidden relative mb-6 fade-up z-10">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <p class="mb-2 text-xs font-semibold uppercase tracking-normal text-gray-400">Coin</p>
                    <div class="grid grid-cols-2 gap-2" role="group" aria-label="Select crypto coin">
                        <button type="button" class="crypto-coin-option" data-crypto-coin="usdt" aria-pressed="false">
                            <span class="crypto-coin-header">
                                <x-ui.icon name="tether" class="crypto-token-icon" />
                                <span class="crypto-token-copy">
                                    <span class="crypto-token-title">USDT</span>
                                    <span class="crypto-token-subtitle">Tether</span>
                                </span>
                            </span>
                        </button>
                        <button type="button" class="crypto-coin-option" data-crypto-coin="usdc" aria-pressed="false">
                            <span class="crypto-coin-header">
                                <x-ui.icon name="usdc" class="crypto-token-icon" />
                                <span class="crypto-token-copy">
                                    <span class="crypto-token-title">USDC</span>
                                    <span class="crypto-token-subtitle">USD Coin</span>
                                </span>
                            </span>
                        </button>
                    </div>
                </div>

                <div class="relative">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-normal text-gray-400">Network</p>
                    <div id="networkSelectShell" class="aksa-select crypto-network-select">
                        <button type="button" data-network-toggle
                            class="aksa-select-trigger crypto-network-trigger"
                            disabled aria-expanded="false" aria-haspopup="listbox">
                            <span id="selectedNetworkText" class="aksa-select-label">Select coin first</span>
                            <x-ui.icon id="networkArrow" name="chevron-down" class="aksa-select-chevron" />
                        </button>

                        <div id="networkDropdown" class="aksa-select-panel crypto-network-panel hidden" role="listbox">
                            <button type="button"
                                class="aksa-select-option crypto-network-option"
                                data-token="usdt" data-network-value="usdtbsc"
                                data-network-text="BNB Smart Chain (BEP20)" role="option" aria-selected="false">
                                <span class="crypto-network-option-main">
                                    <span class="crypto-network-option-title">BNB Smart Chain</span>
                                    <span class="crypto-network-badge">Recommended</span>
                                </span>
                                <span class="crypto-network-option-meta">BEP20</span>
                                <span class="aksa-select-option-check" aria-hidden="true"></span>
                            </button>

                            <button type="button"
                                class="aksa-select-option crypto-network-option"
                                data-token="usdt" data-network-value="usdttrc20"
                                data-network-text="Tron (TRC20)" role="option" aria-selected="false">
                                <span class="crypto-network-option-main">
                                    <span class="crypto-network-option-title">Tron</span>
                                </span>
                                <span class="crypto-network-option-meta">TRC20</span>
                                <span class="aksa-select-option-check" aria-hidden="true"></span>
                            </button>

                            <button type="button"
                                class="aksa-select-option crypto-network-option"
                                data-token="usdc" data-network-value="usdcbsc"
                                data-network-text="BNB Smart Chain (BEP20)" role="option" aria-selected="false">
                                <span class="crypto-network-option-main">
                                    <span class="crypto-network-option-title">BNB Smart Chain</span>
                                    <span class="crypto-network-badge">Recommended</span>
                                </span>
                                <span class="crypto-network-option-meta">BEP20</span>
                                <span class="aksa-select-option-check" aria-hidden="true"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </div>

        <div class="product-section mb-6 fade-up">
            <div class="mb-4">
                <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">Packages</p>
                <h2 class="mt-1 text-xl font-semibold text-white">Select Package</h2>
                <p class="mt-1 text-sm text-gray-400">Pick the duration that matches what you need.</p>
            </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($product->packages as $p)
                @php
                    $packageStock = $p->available_license_stocks_count ?? 0;
                    $packageName = str_replace(['1 Hari', '7 Hari', '30 Hari', 'Hari'], ['1 Day', '7 Days', '30 Days', 'Days'], $p->name);
                    $saving = $packageSavings[$p->id] ?? null;
                    $badge = $bestValuePackageId === $p->id ? 'Best Value' : null;
                @endphp

                <div data-package-card data-price="{{ (float) $p->price }}" data-package-id="{{ $p->id }}"
                    data-package-name="{{ $p->name }}" data-price-usdt="{{ (float) $p->price_usdt }}"
                    data-stock="{{ $packageStock }}"
                    class="package-card p-4 relative package transition {{ $packageStock > 0 ? 'cursor-pointer' : 'cursor-not-allowed opacity-75' }}">

                    @if ($badge)
                        <div class="badge">{{ $badge }}</div>
                    @endif

                    <div class="package-card-heading">
                        <span class="package-card-icon">
                            <x-ui.icon name="calendar" class="h-4 w-4" />
                        </span>
                        <div class="min-w-0 pr-8">
                            <p class="truncate text-sm font-semibold text-white">{{ $packageName }}</p>
                            <p class="mt-0.5 text-xs text-gray-500">
                                {{ ($saving['days'] ?? null) ? $saving['days'] . ' ' . \Illuminate\Support\Str::plural('day', $saving['days']) . ' access' : 'Duration access' }}
                            </p>
                        </div>
                    </div>

                    <div class="package-price-row">
                        <div class="min-w-0">
                            <div class="text-[10px] uppercase tracking-normal text-gray-500">Price</div>
                            <p class="price-text package-price" data-idr="Rp {{ number_format($p->price) }}"
                                data-usd="{{ $formatUsdCompact($p->price_usdt) }}">
                                Rp {{ number_format($p->price) }}
                            </p>
                        </div>
                        @if (($saving['per_day'] ?? null) !== null)
                            <span class="package-per-day" data-currency-text
                                data-idr="Rp {{ number_format($saving['per_day']) }} per day"
                                data-usd="{{ $formatUsdCompact($saving['per_day_usdt']) }} per day">
                                Rp {{ number_format($saving['per_day']) }} per day
                            </span>
                        @endif
                    </div>

                    @if (($saving['saving'] ?? 0) > 0)
                        <div class="package-saving">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-xs font-semibold text-aksa-accent" data-currency-text
                                    data-idr="Save Rp {{ number_format($saving['saving']) }}"
                                    data-usd="Save {{ $formatUsdCompact($saving['saving_usdt']) }}">
                                    Save Rp {{ number_format($saving['saving']) }}
                                </p>
                                <span class="package-saving-badge" data-currency-text
                                    data-idr="{{ $saving['percent'] }}% vs daily"
                                    data-usd="{{ $saving['percent_usdt'] }}% vs daily">{{ $saving['percent'] }}% vs daily</span>
                            </div>
                        </div>
                    @endif

                    <p class="package-availability {{ $packageStock > 0 ? 'package-availability-ready' : 'package-availability-manual' }}">
                        <span class="package-availability-dot" aria-hidden="true"></span>
                        {{ $packageStock > 0 ? $packageStock . ' licenses ready' : 'Manual order via Discord' }}
                    </p>

                    @if ($packageStock <= 0)
                        <button type="button"
                            data-manual-order data-product-name="{{ $product->name }}" data-package-name="{{ $packageName }}"
                            class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-aksa-accent-35 bg-aksa-accent-10 px-3 py-2 text-xs font-semibold text-aksa-accent-soft transition hover:border-aksa-accent hover:bg-aksa-accent-20 hover:text-white">
                            <x-ui.icon name="discord" class="h-4 w-4" />
                            <span>Join Discord to Order</span>
                        </button>
                    @endif

                </div>
            @endforeach
        </div>
        </div>

        <div id="summaryBox" class="hidden product-summary-card fade-up">

            <div class="mb-4">
                <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">Ready to pay</p>
                <h3 class="mt-1 text-xl font-semibold text-white">Order Summary</h3>
            </div>

            <div class="summary-row mb-2">
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
                    <button id="quantityMinus" type="button" class="quantity-stepper-button" aria-label="Decrease quantity"
                        disabled>-</button>
                    <output id="quantityValue" class="quantity-stepper-value" aria-live="polite">1</output>
                    <button id="quantityPlus" type="button" class="quantity-stepper-button" aria-label="Increase quantity"
                        disabled>+</button>
                </div>
            </div>

            <div class="summary-row mb-2">
                <span>Subtotal</span>
                <span id="subtotalPrice">-</span>
            </div>

            <div class="my-4 rounded-xl border border-[#27272A] bg-black/15 p-4">
                <label for="voucherCode" class="mb-2 block text-xs font-semibold uppercase tracking-normal text-gray-400">
                    Voucher Code
                </label>
                <div class="grid gap-2 sm:grid-cols-[1fr_auto]">
                    <input id="voucherCode" class="search-bar min-w-0 w-full uppercase" maxlength="50"
                        placeholder="Enter voucher code" autocomplete="off" spellcheck="false">
                    <button id="applyVoucherBtn" type="button" class="btn-footer h-12">
                        <x-ui.icon name="ticket-percent" class="h-4 w-4" />
                        <span data-button-label>Apply</span>
                    </button>
                </div>
                <p class="mt-2 text-xs text-gray-500">
                    Want a voucher code?
                    <a href="{{ $discordUrl ?: '#' }}"
                        @if ($discordUrl) target="_blank" rel="noopener noreferrer" @endif
                        class="font-semibold text-aksa-accent hover:text-white {{ $discordUrl ? '' : 'pointer-events-none opacity-50' }}">
                        Join our Discord server to get promo codes.
                    </a>
                </p>
                <p id="voucherFeedback" class="mt-2 hidden text-xs"></p>
            </div>

            <div id="voucherDiscountRow" class="summary-row mb-2 hidden">
                <span id="voucherDiscountLabel">Voucher</span>
                <span id="voucherDiscountAmount" class="text-aksa-accent">-</span>
            </div>

            <div class="summary-row">
                <span>Total</span>
                <span id="totalPrice" class="font-semibold text-aksa-accent">-</span>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <button id="addToCartBtn" type="button"
                    class="btn-footer-secondary min-h-12 w-full {{ $stock <= 0 ? 'cursor-not-allowed opacity-60' : '' }}"
                    {{ $stock <= 0 ? 'disabled' : '' }}>
                    <x-ui.icon name="shopping-cart" class="h-4 w-4" />
                    <span data-button-label>{{ $stock <= 0 ? 'Unavailable' : 'Add to Cart' }}</span>
                </button>
                <button id="payMainBtn"
                    class="btn-main w-full
        {{ $stock <= 0 ? 'bg-gray-600 cursor-not-allowed opacity-60' : '' }}"
                    {{ $stock <= 0 ? 'disabled' : '' }}>

                    <x-ui.icon name="{{ $stock <= 0 ? 'discord' : (auth()->check() ? 'credit-card' : 'log-in') }}" class="h-4 w-4" />
                    <span data-button-label>{{ $stock <= 0 ? 'Join Discord to Order' : (auth()->check() ? 'Pay Now' : 'Login to Pay') }}</span>

                </button>
            </div>
            <!-- PAKASIR FORM -->
            <form id="pakasirForm" method="POST" action="/process-order/{{ $product->id }}" class="hidden">
                @csrf
                <input type="hidden" name="package_id" id="pakasir_package">
                <input type="hidden" name="quantity" id="pakasir_quantity" value="1">
                <input type="hidden" name="voucher_code" id="pakasir_voucher">
            </form>

            <!-- CRYPTO FORM -->
            <form id="cryptoForm" method="POST" action="/pay-crypto/{{ $product->id }}" class="hidden">
                @csrf
                <input type="hidden" name="package_id" id="crypto_package">
                <input type="hidden" name="quantity" id="crypto_quantity" value="1">
                <input type="hidden" name="coin" id="crypto_coin">
                <input type="hidden" name="voucher_code" id="crypto_voucher">
            </form>

            @if ($binancePayAvailable)
                <form id="binancePayForm" method="POST" action="/pay-binance/{{ $product->id }}" class="hidden">
                    @csrf
                    <input type="hidden" name="package_id" id="binance_pay_package">
                    <input type="hidden" name="quantity" id="binance_pay_quantity" value="1">
                    <input type="hidden" name="token" id="binance_pay_token">
                    <input type="hidden" name="voucher_code" id="binance_pay_voucher">
                </form>
            @endif
        </div>
    </div>

    @include('partials.pakasir-qris-modal')
    @include('partials.binance-pay-modal')
    @include('partials.direct-crypto-modal')
    @include('partials.payment-success-modal')
    @include('partials.recent-purchase-toast', ['recentPurchases' => $recentPurchases ?? collect()])

    @php $paymentError = $errors->first('payment'); @endphp
    <script nonce="{{ request()->attributes->get('csp_nonce') }}">
        let selectedPackageId = null;
        let selectedPayment = null;
        let selectedToken = null;
        let selectedCoin = null;
        let selectedBinancePayToken = null;
        let selectedPrice = 0;
        let selectedUsd = 0;
        let selectedPackageStock = 0;
        let selectedQuantity = 1;
        let voucherQuote = null;
        let appliedVoucherCode = null;
        let voucherRequestSequence = 0;
        let networkDropdownOpen = false;
        const productDetailPageController = new AbortController();
        const hasStock = @json($stock > 0);
        const isAuthenticated = @json(auth()->check());
        const loginUrl = `/auth/google?redirect=${encodeURIComponent(window.location.href)}`;
        const discordUrl = @json($discordUrl);
        let csrfToken = window.aksaCsrfToken?.() || document.querySelector('meta[name="csrf-token"]')?.content || '';
        const addToCartUrl = @json(route('cart.items.store', $product, false));

        function currentCsrfToken() {
            csrfToken = window.aksaCsrfToken?.() || document.querySelector('meta[name="csrf-token"]')?.content || csrfToken || '';

            return csrfToken;
        }

        async function fetchWithCsrf(url, options = {}) {
            if (window.aksaFetchWithCsrf) {
                const response = await window.aksaFetchWithCsrf(url, options);
                currentCsrfToken();

                return response;
            }

            const headers = new Headers(options.headers || {});
            headers.set('X-CSRF-TOKEN', currentCsrfToken());

            return fetch(url, {
                ...options,
                credentials: options.credentials || 'same-origin',
                headers,
            });
        }

        function checkoutSessionMessage(data = {}) {
            const message = data.message || '';

            return message && !message.toLowerCase().includes('csrf token mismatch') ?
                message :
                'Your secure checkout session expired. Please try again.';
        }

        window.addEventListener('aksa:before-page-swap', () => {
            productDetailPageController.abort();
        }, {
            once: true
        });

        function usesStablecoinPrice(method = selectedPayment) {
            return method === 'crypto' || method === 'binance_pay';
        }

        @if ($paymentError)
            document.addEventListener('DOMContentLoaded', () => {
                setTimeout(() => showToast('Payment failed', @json($paymentError), null, 'error'), 100);
            });
        @endif

        function showToast(title, message, redirectAfter = null, variant = 'info') {
            window.showAppToast?.(title, message, {
                redirectAfter,
                variant,
            });
        }

        function setButtonLabel(button, label) {
            const labelTarget = button?.querySelector('[data-button-label]');

            if (labelTarget) {
                labelTarget.textContent = label;
                return;
            }

            if (button) {
                button.innerText = label;
            }
        }

        function getButtonLabel(button) {
            return button?.querySelector('[data-button-label]')?.textContent || button?.innerText || '';
        }

        function requireLogin() {
            showToast('Login required', 'Please login with Google before checkout.', loginUrl, 'warning');
        }

        async function requestManualOrder(productName, packageName) {
            const message = `I want to buy ${productName} - ${packageName}. Is manual order available?`;
            const copyRequest = navigator.clipboard
                ? navigator.clipboard.writeText(message)
                : Promise.reject(new Error('Clipboard unavailable'));

            if (discordUrl) {
                window.open(discordUrl, '_blank', 'noopener');
            }

            try {
                await copyRequest;
                showToast('Message copied', 'Paste it in Discord to request a manual order.', null, 'success');
            } catch (error) {
                showToast('Manual order request', 'Join Discord and send the product plus package name.', null, 'warning');
            }
        }

        /* =========================
           PAYMENT
        ========================= */
        function selectPayment(type) {

            selectedPayment = type;

            document.querySelectorAll('.payment-card').forEach(el => {
                el.classList.remove('active');
            });

            const target = {
                crypto: 'btnCrypto',
                binance_pay: 'btnBinancePay',
                pakasir: 'btnPakasir',
            }[type];
            const el = document.getElementById(target);

            if (!el) return;

            el.classList.add('active');

            el.classList.add('is-pressing');
            setTimeout(() => el.classList.remove('is-pressing'), 150);

            document.getElementById('cryptoBox')
                .classList.toggle('hidden', type !== 'crypto');
            document.getElementById('binancePayBox')
                ?.classList.toggle('hidden', type !== 'binance_pay');

            refreshNetworkAvailability();
            updateAllPrices();
            if (appliedVoucherCode && type === 'crypto' && !selectedCoin) {
                voucherQuote = null;
                setVoucherFeedback('Select a crypto coin and network to check this voucher.', 'loading');
                updatePrice();
            } else if (appliedVoucherCode && type === 'binance_pay' && !selectedBinancePayToken) {
                voucherQuote = null;
                setVoucherFeedback('Select USDT or USDC to check this voucher.', 'loading');
                updatePrice();
            } else if (appliedVoucherCode) {
                refreshVoucher();
            } else {
                updatePrice();
            }
            showSummary();

            showToast(
                'Payment selected',
                type === 'crypto'
                    ? 'Direct stablecoin address is active. Choose a coin and network next.'
                    : (type === 'binance_pay'
                        ? 'Binance Pay is active. Choose USDT or USDC next.'
                        : 'QRIS via Pakasir is active.'),
                null,
                'success'
            );
        }

        /* =========================
           PACKAGE
        ========================= */
        function selectPackage(card) {
            const price = Number(card.dataset.price || 0);
            const id = Number(card.dataset.packageId || 0);
            const name = card.dataset.packageName || '';
            const usd = Number(card.dataset.priceUsdt || 0);
            const stock = Number(card.dataset.stock || 0);

            if (stock <= 0) {
                showToast('Manual order', 'Auto delivery is not ready for this package. Join Discord to order manually.', null, 'warning');
                return;
            }

            selectedPackageId = id;
            selectedPrice = price;
            selectedUsd = usd;
            selectedPackageStock = stock;
            selectedQuantity = 1;
            refreshQuantityOptions();

            document.querySelectorAll('.package')
                .forEach(el => el.classList.remove('active'));

            card.classList.add('active');

            document.getElementById('selectedPackage')
                .innerText = formatPackageName(name);
            refreshNetworkAvailability();
            if (appliedVoucherCode) {
                refreshVoucher();
            } else {
                updatePrice();
            }
            showSummary();

            const priceText = usesStablecoinPrice() ?
                `${formatUsd(usd)} + unique amount` :
                `Rp ${Number(price).toLocaleString()}`;

            showToast(
                'Package selected',
                `${formatPackageName(name)} - ${priceText} (${stock} left).`,
                null,
                'success'
            );
        }

        /* =========================
           PRICE SWITCH
        ========================= */
        function updateAllPrices() {
            const currency = usesStablecoinPrice() ? 'usd' : 'idr';

            document.querySelectorAll('[data-idr][data-usd]').forEach(el => {
                el.innerText = el.dataset[currency];
            });
        }

        function updatePrice() {
            let subtotal = '-';
            let total = '-';
            let discount = '-';
            const subtotalIdr = selectedPrice * selectedQuantity;
            const subtotalUsd = selectedUsd * selectedQuantity;

            if (selectedPayment === 'pakasir') {
                subtotal = formatIdr(subtotalIdr);
                total = voucherQuote ? formatIdr(voucherQuote.final_idr) : subtotal;
                discount = voucherQuote ? `-${formatIdr(voucherQuote.discount_idr)}` : '-';
            }

            if (usesStablecoinPrice()) {
                subtotal = formatUsd(subtotalUsd);
                total = `${formatUsd(voucherQuote ? voucherQuote.final_usdt : subtotalUsd)} + unique amount`;
                discount = voucherQuote ? `-${formatUsd(voucherQuote.discount_usdt)}` : '-';
            }

            document.getElementById('subtotalPrice').innerText = subtotal;
            document.getElementById('totalPrice').innerText = total;
            document.getElementById('voucherDiscountAmount').innerText = discount;
            document.getElementById('voucherDiscountRow').classList.toggle('hidden', !voucherQuote);
        }

        function formatIdr(amount) {
            return `Rp ${Number(amount).toLocaleString('id-ID')}`;
        }

        function formatUsd(amount) {
            return `$${Number(amount).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            })}`;
        }

        function refreshNetworkAvailability() {
            document.querySelectorAll('[data-network-value]').forEach(item => {
                const isVisible = item.dataset.token === selectedToken;
                const isActive = item.dataset.networkValue === selectedCoin;

                item.classList.toggle('hidden', !isVisible);
                item.classList.toggle('is-active', isVisible && isActive);
                item.setAttribute('aria-selected', String(isVisible && isActive));
                item.tabIndex = isVisible ? 0 : -1;
            });
        }

        function formatPackageName(name) {
            return name
                .replace('1 Hari', '1 Day')
                .replace('7 Hari', '7 Days')
                .replace('30 Hari', '30 Days')
                .replace('Hari', 'Days');
        }

        function refreshQuantityOptions() {
            const maxQuantity = Math.max(1, selectedPackageStock);
            const minusButton = document.getElementById('quantityMinus');
            const plusButton = document.getElementById('quantityPlus');

            selectedQuantity = Math.min(selectedQuantity, maxQuantity);
            document.getElementById('quantityValue').innerText = selectedQuantity;
            document.getElementById('quantityLimit').innerText = `Max: ${selectedPackageStock}`;
            minusButton.disabled = !selectedPackageId || selectedQuantity <= 1;
            plusButton.disabled = !selectedPackageId || selectedPackageStock <= 0 || selectedQuantity >= maxQuantity;
            document.getElementById('pakasir_quantity').value = selectedQuantity;
            document.getElementById('crypto_quantity').value = selectedQuantity;
            const binanceQuantity = document.getElementById('binance_pay_quantity');
            if (binanceQuantity) binanceQuantity.value = selectedQuantity;
        }

        function changeQuantity(change) {
            selectedQuantity = Math.max(1, Math.min(selectedPackageStock, selectedQuantity + change));
            refreshQuantityOptions();

            if (appliedVoucherCode) {
                refreshVoucher();
            } else {
                updatePrice();
            }
        }

        async function requestVoucherQuote(code) {
            if (!selectedPayment) {
                throw new Error('Select a payment method before applying a voucher.');
            }

            if (selectedPayment === 'crypto' && !selectedCoin) {
                throw new Error('Select a crypto coin and network before applying a voucher.');
            }

            if (selectedPayment === 'binance_pay' && !selectedBinancePayToken) {
                throw new Error('Select USDT or USDC before applying a voucher.');
            }

            const body = new FormData();
            body.set('code', code);
            body.set('package_id', selectedPackageId);
            body.set('payment_method', selectedPayment);
            body.set('quantity', selectedQuantity);

            if (selectedCoin) {
                body.set('coin', selectedCoin);
            } else if (selectedPayment === 'binance_pay') {
                body.set('coin', selectedBinancePayToken);
            }

            const response = await fetchWithCsrf(@json(route('vouchers.preview')), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body,
            });
            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                if (response.status === 419) {
                    throw new Error(checkoutSessionMessage(data));
                }

                throw new Error(data.message || 'Voucher could not be applied.');
            }

            return data;
        }

        function voucherExpiryText(expiresAt) {
            if (!expiresAt) return '';

            const expiry = new Date(expiresAt);

            if (Number.isNaN(expiry.getTime())) return '';

            return ` Valid until ${expiry.toLocaleString('en-GB', {
                day: '2-digit',
                month: 'short',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                timeZoneName: 'short',
            })}.`;
        }

        function voucherAppliedMessage(quote) {
            const saving = usesStablecoinPrice(quote.payment_method)
                ? `${formatUsd(quote.discount_usdt)} ${quote.token}`
                : formatIdr(quote.discount_idr);

            return `${quote.discount_percent}% voucher applied. You save ${saving}.${voucherExpiryText(quote.expires_at)}`;
        }

        function setVoucherFeedback(message, variant = 'success') {
            const feedback = document.getElementById('voucherFeedback');
            feedback.innerText = message;
            feedback.classList.remove('hidden', 'text-aksa-accent', 'text-red-300', 'text-gray-400');
            feedback.classList.add(variant === 'success' ? 'text-aksa-accent' : (variant === 'loading' ? 'text-gray-400' : 'text-red-300'));
        }

        function clearVoucher(message = null) {
            voucherRequestSequence++;
            voucherQuote = null;
            appliedVoucherCode = null;
            document.getElementById('pakasir_voucher').value = '';
            document.getElementById('crypto_voucher').value = '';
            const binanceVoucher = document.getElementById('binance_pay_voucher');
            if (binanceVoucher) binanceVoucher.value = '';
            document.getElementById('voucherDiscountLabel').innerText = 'Voucher';
            document.getElementById('voucherDiscountRow').classList.add('hidden');

            if (message) {
                setVoucherFeedback(message, 'error');
            } else {
                document.getElementById('voucherFeedback').classList.add('hidden');
            }

            updatePrice();
        }

        async function applyVoucher() {
            if (!isAuthenticated) {
                requireLogin();
                return;
            }

            if (!selectedPackageId) {
                showToast('Select package', 'Select a package before applying a voucher.', null, 'warning');
                return;
            }

            if (!selectedPayment) {
                showToast('Select payment', 'Select a payment method before applying a voucher.', null, 'warning');
                return;
            }

            if (selectedPayment === 'crypto' && !selectedCoin) {
                showToast('Select crypto network', 'Select a coin and network before applying a voucher.', null, 'warning');
                return;
            }

            if (selectedPayment === 'binance_pay' && !selectedBinancePayToken) {
                showToast('Select Binance Pay coin', 'Select USDT or USDC before applying a voucher.', null, 'warning');
                return;
            }

            const code = document.getElementById('voucherCode').value.trim().toUpperCase();

            if (!code) {
                clearVoucher('Enter a voucher code first.');
                return;
            }

            const button = document.getElementById('applyVoucherBtn');
            const requestSequence = ++voucherRequestSequence;
            const paymentSnapshot = selectedPayment;
            const coinSnapshot = selectedCoin;
            const binanceTokenSnapshot = selectedBinancePayToken;
            const quantitySnapshot = selectedQuantity;
            button.disabled = true;
            setButtonLabel(button, 'Checking...');

            try {
                const quote = await requestVoucherQuote(code);

                if (
                    requestSequence !== voucherRequestSequence ||
                    paymentSnapshot !== selectedPayment ||
                    coinSnapshot !== selectedCoin ||
                    binanceTokenSnapshot !== selectedBinancePayToken ||
                    quantitySnapshot !== selectedQuantity
                ) {
                    return;
                }

                voucherQuote = quote;
                appliedVoucherCode = voucherQuote.code;
                document.getElementById('voucherCode').value = appliedVoucherCode;
                document.getElementById('pakasir_voucher').value = appliedVoucherCode;
                document.getElementById('crypto_voucher').value = appliedVoucherCode;
                const binanceVoucher = document.getElementById('binance_pay_voucher');
                if (binanceVoucher) binanceVoucher.value = appliedVoucherCode;
                document.getElementById('voucherDiscountLabel').innerText = `Voucher ${appliedVoucherCode}`;
                setVoucherFeedback(voucherAppliedMessage(voucherQuote));
                updatePrice();
                showToast('Voucher applied', voucherAppliedMessage(voucherQuote), null, 'success');
            } catch (error) {
                if (requestSequence !== voucherRequestSequence) return;

                clearVoucher(error.message || 'Voucher could not be applied.');
            } finally {
                button.disabled = false;
                setButtonLabel(button, 'Apply');
            }
        }

        async function refreshVoucher() {
            if (!appliedVoucherCode || !selectedPackageId) return;

            if (selectedPayment === 'crypto' && !selectedCoin) {
                voucherQuote = null;
                setVoucherFeedback('Select a crypto coin and network to check this voucher.', 'loading');
                updatePrice();
                return;
            }

            if (selectedPayment === 'binance_pay' && !selectedBinancePayToken) {
                voucherQuote = null;
                setVoucherFeedback('Select USDT or USDC to check this voucher.', 'loading');
                updatePrice();
                return;
            }

            voucherQuote = null;
            setVoucherFeedback('Checking voucher for the selected package...', 'loading');
            updatePrice();
            const requestSequence = ++voucherRequestSequence;
            const paymentSnapshot = selectedPayment;
            const coinSnapshot = selectedCoin;
            const binanceTokenSnapshot = selectedBinancePayToken;
            const quantitySnapshot = selectedQuantity;

            try {
                const quote = await requestVoucherQuote(appliedVoucherCode);

                if (
                    requestSequence !== voucherRequestSequence ||
                    paymentSnapshot !== selectedPayment ||
                    coinSnapshot !== selectedCoin ||
                    binanceTokenSnapshot !== selectedBinancePayToken ||
                    quantitySnapshot !== selectedQuantity
                ) {
                    return;
                }

                voucherQuote = quote;
                document.getElementById('pakasir_voucher').value = appliedVoucherCode;
                document.getElementById('crypto_voucher').value = appliedVoucherCode;
                const binanceVoucher = document.getElementById('binance_pay_voucher');
                if (binanceVoucher) binanceVoucher.value = appliedVoucherCode;
                setVoucherFeedback(voucherAppliedMessage(voucherQuote));
                updatePrice();
            } catch (error) {
                if (requestSequence !== voucherRequestSequence) return;

                clearVoucher(error.message || 'Voucher is not available for this package.');
            }
        }

        /* =========================
           CRYPTO COIN & NETWORK
        ========================= */
        function setNetworkDropdownState(isOpen) {
            const box = document.getElementById('networkDropdown');
            const toggle = document.querySelector('[data-network-toggle]');
            const shell = document.getElementById('networkSelectShell');
            const section = document.getElementById('cryptoBox')?.closest('.product-section');

            networkDropdownOpen = isOpen;
            box?.classList.toggle('hidden', !isOpen);
            shell?.classList.toggle('is-open', isOpen);
            section?.classList.toggle('is-select-open', isOpen);
            toggle?.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }

        function toggleNetworkDropdown(e) {
            e.stopPropagation();

            if (!selectedToken) return;

            setNetworkDropdownState(!networkDropdownOpen);
        }

        function closeNetworkDropdown() {
            setNetworkDropdownState(false);
        }

        function selectCryptoCoin(token) {
            selectedToken = token;
            selectedCoin = null;

            document.querySelectorAll('[data-crypto-coin]').forEach(option => {
                const isActive = option.dataset.cryptoCoin === token;
                option.classList.toggle('active', isActive);
                option.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });

            const networkToggle = document.querySelector('[data-network-toggle]');
            networkToggle.disabled = false;
            document.getElementById('selectedNetworkText').innerText = 'Select Network';
            refreshNetworkAvailability();
            closeNetworkDropdown();

            if (appliedVoucherCode) {
                voucherQuote = null;
                setVoucherFeedback('Select a network to check this voucher for crypto.', 'loading');
                updatePrice();
            }

            showToast('Coin selected', `${token.toUpperCase()} selected. Choose its network next.`, null, 'success');
        }

        function selectNetwork(value, text) {
            selectedCoin = value;
            document.getElementById('selectedNetworkText').innerText = text;
            refreshNetworkAvailability();
            closeNetworkDropdown();

            if (appliedVoucherCode) {
                refreshVoucher();
            }

            showToast('Network selected', `${selectedToken.toUpperCase()} on ${text}`, null, 'success');
        }

        function selectBinancePayToken(token) {
            selectedBinancePayToken = token;

            document.querySelectorAll('[data-binance-pay-token]').forEach(option => {
                const isActive = option.dataset.binancePayToken === token;
                option.classList.toggle('active', isActive);
                option.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });

            const input = document.getElementById('binance_pay_token');
            if (input) input.value = token;

            if (appliedVoucherCode) {
                refreshVoucher();
            }

            showToast('Binance Pay coin selected', `${token.toUpperCase()} selected.`, null, 'success');
        }

        window.addEventListener('click', function(e) {
            if (!e.target.closest('#cryptoBox')) {
                closeNetworkDropdown();
            }
        }, {
            signal: productDetailPageController.signal
        });



        /* =========================
           SUMMARY
        ========================= */
        function showSummary() {
            if (selectedPackageId && selectedPayment) {
                document.getElementById('summaryBox').classList.remove('hidden');
            }
        }

        async function fetchPaymentJson(form) {
            const response = await fetchWithCsrf(form.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new FormData(form),
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                const isTooManyAttempts = response.status === 429;
                const message = response.status === 419 ?
                    checkoutSessionMessage(data) :
                    (response.status === 401 ?
                    'Please login with Google before checkout.' :
                    (isTooManyAttempts ?
                        (data.message || 'Too many payment attempts. Cancel unfinished payments from Orders first.') :
                        (data.message || 'Payment failed')));
                const error = new Error(message);
                error.status = response.status;
                error.redirectUrl = data.redirect_url || (isTooManyAttempts ? '/orders?payment_notice=too-many-attempts' : null);
                throw error;
            }

            return data;
        }

        function resetPayButton() {
            const btn = document.getElementById('payMainBtn');
            if (!btn || !hasStock) return;

            btn.disabled = false;
            setButtonLabel(btn, isAuthenticated ? 'Pay Now' : 'Login to Pay');
            btn.classList.remove('opacity-60', 'bg-gray-500', 'cursor-not-allowed', 'pointer-events-none');
        }

        /* =========================
           PAY BUTTON
        ========================= */
        document.querySelectorAll('[data-payment-method]').forEach((card) => {
            card.addEventListener('click', () => selectPayment(card.dataset.paymentMethod));
        });

        document.querySelectorAll('[data-crypto-coin]').forEach((option) => {
            option.addEventListener('click', () => selectCryptoCoin(option.dataset.cryptoCoin));
        });

        document.querySelectorAll('[data-binance-pay-token]').forEach((option) => {
            option.addEventListener('click', () => selectBinancePayToken(option.dataset.binancePayToken));
        });

        document.querySelector('[data-network-toggle]')?.addEventListener('click', toggleNetworkDropdown);

        document.querySelectorAll('[data-network-value]').forEach((item) => {
            item.addEventListener('click', () => selectNetwork(item.dataset.networkValue, item.dataset.networkText));
        });

        document.querySelectorAll('[data-package-card]').forEach((card) => {
            card.addEventListener('click', () => selectPackage(card));
        });

        document.querySelectorAll('[data-manual-order]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.stopPropagation();
                requestManualOrder(button.dataset.productName || '', button.dataset.packageName || '');
            });
        });

        document.getElementById('applyVoucherBtn')?.addEventListener('click', applyVoucher);
        document.getElementById('quantityMinus')?.addEventListener('click', () => changeQuantity(-1));
        document.getElementById('quantityPlus')?.addEventListener('click', () => changeQuantity(1));
        document.getElementById('voucherCode')?.addEventListener('input', (event) => {
            event.target.value = event.target.value.toUpperCase();

            if (appliedVoucherCode && event.target.value.trim() !== appliedVoucherCode) {
                clearVoucher();
            }
        });
        document.getElementById('voucherCode')?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                applyVoucher();
            }
        });

        document.getElementById('addToCartBtn')?.addEventListener('click', async function() {
            if (!isAuthenticated) {
                requireLogin();
                return;
            }

            if (!selectedPackageId) {
                showToast('Select package', 'Select a package before adding it to your cart.', null, 'warning');
                return;
            }

            if (selectedPackageStock < selectedQuantity) {
                showToast('Not enough stock', 'The selected quantity is no longer available.', null, 'warning');
                return;
            }

            const originalText = getButtonLabel(this);
            this.disabled = true;
            setButtonLabel(this, 'Adding...');
            const body = new FormData();
            body.set('package_id', selectedPackageId);
            body.set('quantity', selectedQuantity);

            try {
                const response = await fetchWithCsrf(addToCartUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body,
                });
                const data = await response.json().catch(() => ({}));

                if (!response.ok) {
                    if (response.status === 419) {
                        throw new Error(checkoutSessionMessage(data));
                    }

                    throw new Error(data.message || 'The item could not be added to your cart.');
                }

                document.querySelectorAll('[data-cart-count]').forEach((badge) => {
                    badge.innerText = data.cart_count;
                    badge.classList.toggle('hidden', Number(data.cart_count) <= 0);
                });
                showToast('Added to cart', data.message, null, 'success');
                setButtonLabel(this, 'Added');
                setTimeout(() => {
                    setButtonLabel(this, originalText);
                    this.disabled = false;
                }, 900);
            } catch (error) {
                showToast('Cart not updated', error.message, null, 'error');
                setButtonLabel(this, originalText);
                this.disabled = false;
            }
        });

        document.getElementById('payMainBtn').addEventListener('click', async function() {

            if (this.disabled) return;

            if (!isAuthenticated) {
                requireLogin();
                return;
            }

            if (!selectedPackageId) {
                showToast('Select package', 'Select a package first.', null, 'warning');
                return;
            }

            if (selectedPackageStock < selectedQuantity) {
                showToast('Not enough stock', 'The selected quantity is no longer available.', null, 'warning');
                return;
            }

            if (!selectedPayment) {
                showToast('Select payment', 'Select a payment method.', null, 'warning');
                return;
            }

            if (selectedPayment === 'crypto' && !selectedCoin) {
                const message = selectedToken ? 'Select a crypto network first.' : 'Select a coin and network first.';
                showToast('Complete crypto selection', message, null, 'warning');
                return;
            }

            if (selectedPayment === 'binance_pay' && !selectedBinancePayToken) {
                showToast('Complete Binance Pay selection', 'Select USDT or USDC first.', null, 'warning');
                return;
            }

            showToast('Checkout started', 'Preparing your payment.');

            setButtonLabel(this, 'Processing...');
            this.classList.add('opacity-60')
            this.classList.add('bg-gray-500', 'cursor-not-allowed', 'pointer-events-none')
            this.disabled = true;

            if (selectedPayment === 'pakasir') {

                document.getElementById('pakasir_package').value = selectedPackageId;
                document.getElementById('pakasir_quantity').value = selectedQuantity;

                const form = document.getElementById('pakasirForm');
                sessionStorage.setItem('last_product', window.location.href);

                try {
                    const data = await fetchPaymentJson(form);
                    const opened = await window.openAksaQrisModal?.(data);

                    if (!opened && data.payment_url) {
                        window.location.href = data.payment_url;
                        return;
                    }

                    setButtonLabel(this, 'Payment Pending');
                    showToast('QRIS ready', 'Scan the QRIS code to complete your payment.', null, 'success');
                } catch (error) {
                    if (error.redirectUrl) {
                        window.location.href = error.redirectUrl;
                        return;
                    }

                    if (error.status === 401) {
                        requireLogin();
                        return;
                    }

                    showToast('Payment failed', error.message || 'Payment failed', null, 'error');
                    resetPayButton();
                }
            }

            if (selectedPayment === 'binance_pay') {
                document.getElementById('binance_pay_package').value = selectedPackageId;
                document.getElementById('binance_pay_quantity').value = selectedQuantity;
                document.getElementById('binance_pay_token').value = selectedBinancePayToken;

                const form = document.getElementById('binancePayForm');

                try {
                    const data = await fetchPaymentJson(form);
                    const opened = await window.openAksaBinancePayModal?.(data, {
                        startPolling: true,
                    });

                    if (!opened) {
                        window.location.href = '/orders';
                        return;
                    }

                    setButtonLabel(this, 'Payment Pending');
                    showToast('Binance Pay ready', 'Send the exact amount to the Pay ID shown.', null, 'success');
                } catch (error) {
                    if (error.redirectUrl) {
                        window.location.href = error.redirectUrl;
                        return;
                    }

                    if (error.status === 401) {
                        requireLogin();
                        return;
                    }

                    showToast('Payment failed', error.message || 'Payment failed', null, 'error');
                    resetPayButton();
                }
            }

            if (selectedPayment === 'crypto') {
                document.getElementById('crypto_package').value = selectedPackageId;
                document.getElementById('crypto_quantity').value = selectedQuantity;
                document.getElementById('crypto_coin').value = selectedCoin;

                const form = document.getElementById('cryptoForm');

                try {
                    const data = await fetchPaymentJson(form);
                    const opened = await window.openAksaCryptoModal?.(data, {
                        startPolling: true,
                    });

                    if (!opened) {
                        window.location.href = '/orders';
                        return;
                    }

                    setButtonLabel(this, 'Payment Pending');
                    showToast('Crypto address ready', 'Send the exact amount shown in the modal.', null, 'success');
                } catch (error) {
                    if (error.redirectUrl) {
                        window.location.href = error.redirectUrl;
                        return;
                    }

                    if (error.status === 401) {
                        requireLogin();
                        return;
                    }

                    showToast('Payment failed', error.message || 'Payment failed', null, 'error');
                    resetPayButton();
                }
            }
        });

        window.addEventListener('pageshow', function() {
            const btn = document.getElementById('payMainBtn')
            if (btn) {
                if (!hasStock) {
                    btn.disabled = true
                    setButtonLabel(btn, 'Join Discord to Order')
                    return
                }

                btn.disabled = false
                setButtonLabel(btn, isAuthenticated ? 'Pay Now' : 'Login to Pay')
                btn.classList.remove('opacity-60', 'bg-gray-500', 'cursor-not-allowed', 'pointer-events-none')
            }
        }, {
            signal: productDetailPageController.signal
        });
    </script>
@endsection
