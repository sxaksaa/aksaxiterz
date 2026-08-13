@extends('layouts.app')

@section('content')
    @php
        $isDirect = $checkoutMode === 'direct';
        $itemCount = $checkoutItems->count();
        $totalQuantity = (int) $checkoutItems->sum('quantity');
        $backUrl = $isDirect && $product
            ? route('products.show', $product)
            : route('cart.index');
    @endphp

    <div class="page-shell py-6 md:py-10">
        <section class="product-hero mb-6 fade-up">
            <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                <div>
                    <a href="{{ $backUrl }}" class="text-sm text-aksa-accent transition hover:text-white">
                        {{ $isDirect ? 'Back to product' : 'Back to cart' }}
                    </a>
                    <p class="mt-4 text-xs font-semibold uppercase tracking-normal text-aksa-accent">Secure Checkout</p>
                    <h1 class="mt-2 text-3xl font-bold text-white md:text-4xl">Review and pay</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-gray-400">
                        Your package is selected. Choose one payment method, review the exact total, then continue to the order page for payment instructions.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="support-pill">
                        <x-ui.icon name="package-check" class="h-4 w-4" />
                        <span>{{ $itemCount }} {{ \Illuminate\Support\Str::plural('package', $itemCount) }}</span>
                    </span>
                    <span class="support-pill">
                        <x-ui.icon name="key-round" class="h-4 w-4" />
                        <span>{{ $totalQuantity }} {{ \Illuminate\Support\Str::plural('license', $totalQuantity) }}</span>
                    </span>
                </div>
            </div>
        </section>

        @if ($errors->any())
            <div class="mb-5 rounded-xl border border-red-500/30 bg-red-500/10 p-4 text-sm text-red-200">
                {{ $errors->first() }}
            </div>
        @endif

        @if ($hasUnavailableItems)
            <div class="mb-5 rounded-xl border border-amber-400/30 bg-amber-400/10 p-4 text-sm text-amber-100">
                Stock or product availability changed before payment. Return to {{ $isDirect ? 'the product' : 'your cart' }} and review the selection again.
            </div>
        @endif

        <form id="checkoutForm" method="POST" action="{{ $checkoutAction }}">
            @csrf
            @if ($isDirect)
                <input type="hidden" name="package_id" value="{{ $package->id }}">
                <input type="hidden" name="quantity" value="{{ $quantity }}">
            @elseif ($cartSignature)
                <input type="hidden" name="cart_signature" value="{{ $cartSignature }}">
            @endif
            <input id="checkoutVoucherValue" type="hidden" name="voucher_code" value="">

            <div class="grid gap-6 lg:grid-cols-[1.1fr_0.9fr] lg:items-start">
                <div class="grid gap-6">
                    <section class="checkout-order-review product-section fade-up">
                        <button id="checkoutOrderReviewToggle" type="button" class="checkout-order-review-toggle"
                            aria-expanded="false" aria-controls="checkoutOrderReviewItems">
                            <span class="text-left">
                                <span class="block text-xs font-semibold uppercase tracking-normal text-aksa-accent">Step 1</span>
                                <span class="mt-1 block text-left text-xl font-semibold text-white">Order review</span>
                            </span>
                            <span class="inline-flex items-center gap-2 text-sm text-gray-400">
                                <span>{{ $itemCount }} {{ \Illuminate\Support\Str::plural('package', $itemCount) }}</span>
                                <x-ui.icon name="chevron-down" class="checkout-order-review-chevron h-4 w-4" />
                            </span>
                        </button>

                        <div id="checkoutOrderReviewItems" class="checkout-order-review-items mt-4 gap-3">
                            @foreach ($checkoutItems->groupBy('product_slug') as $productItems)
                                <article class="checkout-product-group rounded-xl border border-[#27272A] bg-black/15">
                                    <div class="border-b border-white/5 px-4 py-3">
                                        <h3 class="truncate font-semibold text-white">{{ $productItems->first()['product_name'] }}</h3>
                                        <p class="mt-1 text-xs text-gray-400">
                                            {{ $productItems->count() }} {{ \Illuminate\Support\Str::plural('package', $productItems->count()) }}
                                        </p>
                                    </div>
                                    <div class="divide-y divide-white/5">
                                        @foreach ($productItems as $item)
                                            <div class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                                <div class="min-w-0">
                                                    <p class="text-sm font-semibold text-white">
                                                        {{ $item['package_name'] }} · {{ $item['quantity'] }}
                                                        {{ \Illuminate\Support\Str::plural('license', $item['quantity']) }}
                                                    </p>
                                                    <p class="mt-1 text-xs {{ $item['is_available'] ? 'text-aksa-accent' : 'text-amber-200' }}">
                                                        {{ $item['is_available'] ? $item['available_stock'].' available · Auto delivery' : 'Selection needs to be reviewed' }}
                                                    </p>
                                                </div>
                                                <div class="font-semibold text-white" data-display-price
                                                    data-price-idr="{{ (int) $item['line_total_idr'] }}"
                                                    data-price-usd="{{ ($item['has_usd_price'] ?? false) ? (float) $item['line_total_usdt'] : '' }}">
                                                    Rp {{ number_format($item['line_total_idr'], 0, ',', '.') }}
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </section>

                    <section class="product-section fade-up">
                        <div class="mb-4">
                            <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">Step 2</p>
                            <h2 class="mt-1 text-xl font-semibold text-white">Payment method</h2>
                            <p class="mt-1 text-sm text-gray-400">The selected method determines the final payment currency.</p>
                        </div>

                        <div class="grid gap-3 {{ $binancePayAvailable ? 'sm:grid-cols-3' : 'sm:grid-cols-2' }}">
                            <label class="checkout-card payment-card cursor-pointer p-4 {{ ! $qrisAvailable || $hasUnavailableItems ? 'cursor-not-allowed opacity-60' : '' }}"
                                data-checkout-payment-card>
                                <input class="sr-only" type="radio" name="payment_method" value="gopay_qris"
                                    @disabled(! $qrisAvailable || $hasUnavailableItems)>
                                <span class="payment-card-heading">
                                    <span class="payment-card-icon">
                                        <x-ui.icon name="qr-code" class="h-5 w-5" />
                                    </span>
                                    <span class="font-semibold text-white">QRIS</span>
                                </span>
                                <span class="mt-1 block text-xs text-gray-400">
                                    {{ $qrisAvailable ? 'Pay in Indonesian rupiah' : 'Temporarily unavailable' }}
                                </span>
                            </label>

                            @if ($binancePayAvailable)
                                <label class="checkout-card payment-card cursor-pointer p-4 {{ $hasUnavailableItems || ! $stablecoinPricingAvailable ? 'cursor-not-allowed opacity-60' : '' }}"
                                    data-checkout-payment-card>
                                    <input class="sr-only" type="radio" name="payment_method" value="binance_pay"
                                        @disabled($hasUnavailableItems || ! $stablecoinPricingAvailable)>
                                    <span class="payment-card-heading">
                                        <span class="payment-card-icon">
                                            <x-ui.icon name="binance" class="h-5 w-5 text-[#F0B90B]" />
                                        </span>
                                        <span class="font-semibold text-white">Binance Pay</span>
                                    </span>
                                    <span class="mt-1 block text-xs text-gray-400">
                                        {{ $stablecoinPricingAvailable ? 'Pay with USDT or USDC' : 'USD price unavailable for this selection' }}
                                    </span>
                                </label>
                            @endif

                            <label class="checkout-card payment-card cursor-pointer p-4 {{ $hasUnavailableItems || ! $stablecoinPricingAvailable ? 'cursor-not-allowed opacity-60' : '' }}"
                                data-checkout-payment-card>
                                <input class="sr-only" type="radio" name="payment_method" value="crypto"
                                    @disabled($hasUnavailableItems || ! $stablecoinPricingAvailable)>
                                <span class="payment-card-heading">
                                    <span class="payment-card-icon">
                                        <x-ui.icon name="wallet" class="h-5 w-5" />
                                    </span>
                                    <span class="font-semibold text-white">Crypto Address</span>
                                </span>
                                <span class="mt-1 block text-xs text-gray-400">
                                    {{ $stablecoinPricingAvailable ? 'Send USDT or USDC on-chain' : 'USD price unavailable for this selection' }}
                                </span>
                            </label>
                        </div>

                        @if ($binancePayAvailable)
                            <div id="checkoutBinanceOptions" class="mt-5 hidden">
                                <p class="mb-2 text-xs font-semibold uppercase tracking-normal text-gray-400">Binance Pay coin</p>
                                <div class="grid grid-cols-2 gap-2">
                                    <label class="crypto-coin-option cursor-pointer">
                                        <input class="sr-only" type="radio" name="token" value="usdt">
                                        <span class="crypto-coin-header">
                                            <x-ui.icon name="tether" class="crypto-token-icon" />
                                            <span class="crypto-token-copy">
                                                <span class="crypto-token-title">USDT</span>
                                                <span class="crypto-token-subtitle">Tether</span>
                                            </span>
                                        </span>
                                    </label>
                                    <label class="crypto-coin-option cursor-pointer">
                                        <input class="sr-only" type="radio" name="token" value="usdc">
                                        <span class="crypto-coin-header">
                                            <x-ui.icon name="usdc" class="crypto-token-icon" />
                                            <span class="crypto-token-copy">
                                                <span class="crypto-token-title">USDC</span>
                                                <span class="crypto-token-subtitle">USD Coin</span>
                                            </span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        @endif

                        <div id="checkoutCryptoOptions" class="mt-5 hidden">
                            <div>
                                <p class="mb-2 text-xs font-semibold uppercase tracking-normal text-gray-400">Coin</p>
                                <div class="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Select crypto coin">
                                    <label class="crypto-coin-option cursor-pointer" data-checkout-crypto-token-option>
                                        <input class="sr-only" type="radio" name="crypto_token" value="usdt">
                                        <span class="crypto-coin-header">
                                            <x-ui.icon name="tether" class="crypto-token-icon" />
                                            <span class="crypto-token-copy">
                                                <span class="crypto-token-title">USDT</span>
                                                <span class="crypto-token-subtitle">Tether</span>
                                            </span>
                                        </span>
                                    </label>
                                    <label class="crypto-coin-option cursor-pointer" data-checkout-crypto-token-option>
                                        <input class="sr-only" type="radio" name="crypto_token" value="usdc">
                                        <span class="crypto-coin-header">
                                            <x-ui.icon name="usdc" class="crypto-token-icon" />
                                            <span class="crypto-token-copy">
                                                <span class="crypto-token-title">USDC</span>
                                                <span class="crypto-token-subtitle">USD Coin</span>
                                            </span>
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <div id="checkoutCryptoNetworkOptions" class="mt-4 hidden">
                                <div class="mb-2 flex items-center justify-between gap-3">
                                    <p class="text-xs font-semibold uppercase tracking-normal text-gray-400">Network</p>
                                    <span id="checkoutCryptoNetworkPrompt" class="text-xs text-gray-500">Select a coin first</span>
                                </div>
                                <div class="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Select crypto network">
                                    <label class="crypto-coin-option cursor-pointer text-left"
                                        data-checkout-crypto-network-option data-token="usdt">
                                        <input class="sr-only" type="radio" name="coin" value="usdtbsc" disabled>
                                        <span class="crypto-coin-header">
                                            <x-ui.icon name="tether" class="crypto-token-icon" />
                                            <span class="crypto-token-copy">
                                                <span class="crypto-token-title">BNB Smart Chain</span>
                                                <span class="crypto-token-subtitle">BEP20 · Recommended</span>
                                            </span>
                                        </span>
                                    </label>
                                    <label class="crypto-coin-option cursor-pointer text-left"
                                        data-checkout-crypto-network-option data-token="usdt">
                                        <input class="sr-only" type="radio" name="coin" value="usdttrc20" disabled>
                                        <span class="crypto-coin-header">
                                            <x-ui.icon name="tether" class="crypto-token-icon" />
                                            <span class="crypto-token-copy">
                                                <span class="crypto-token-title">Tron</span>
                                                <span class="crypto-token-subtitle">TRC20</span>
                                            </span>
                                        </span>
                                    </label>
                                    <label class="crypto-coin-option cursor-pointer text-left"
                                        data-checkout-crypto-network-option data-token="usdc">
                                        <input class="sr-only" type="radio" name="coin" value="usdcbsc" disabled>
                                        <span class="crypto-coin-header">
                                            <x-ui.icon name="usdc" class="crypto-token-icon" />
                                            <span class="crypto-token-copy">
                                                <span class="crypto-token-title">BNB Smart Chain</span>
                                                <span class="crypto-token-subtitle">BEP20 · Recommended</span>
                                            </span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <aside id="checkoutFinalSummary" class="product-section fade-up lg:sticky lg:top-24">
                    <div class="mb-5">
                        <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">Step 3</p>
                        <h2 class="mt-1 text-xl font-semibold text-white">Final summary</h2>
                    </div>

                    <div id="checkoutVoucherPanel" class="checkout-voucher-panel rounded-xl border border-[#27272A] bg-black/15 p-4">
                        <label for="checkoutVoucherCode"
                            class="mb-2 block text-xs font-semibold uppercase tracking-normal text-gray-400">
                            Voucher code
                        </label>
                        <div class="grid gap-2 sm:grid-cols-[1fr_auto] lg:grid-cols-1 xl:grid-cols-[1fr_auto]">
                            <input id="checkoutVoucherCode" class="search-bar min-w-0 uppercase" maxlength="50"
                                placeholder="Enter voucher code" autocomplete="off" @disabled($hasUnavailableItems)>
                            <button id="checkoutApplyVoucher" type="button" class="btn-footer h-12" data-voucher-action
                                @disabled($hasUnavailableItems)>
                                <x-ui.icon name="ticket-percent" class="h-4 w-4" />
                                <span data-button-label>Apply</span>
                            </button>
                        </div>
                        <p id="checkoutVoucherFeedback" class="mt-2 hidden text-xs"></p>
                    </div>

                    <div class="mt-5 summary-row mb-2">
                        <span id="checkoutSubtotalLabel">Subtotal</span>
                        <span id="checkoutSubtotal">Select payment</span>
                    </div>
                    <div id="checkoutDiscountRow" class="summary-row mb-2 hidden">
                        <span>Voucher</span>
                        <span id="checkoutDiscount" class="text-aksa-accent">-</span>
                    </div>
                    <div class="summary-row">
                        <span id="checkoutTotalLabel">Total</span>
                        <span id="checkoutTotal" class="font-semibold text-aksa-accent">Select payment</span>
                    </div>
                    <p id="checkoutCurrencyHint" class="mt-3 text-xs leading-5 text-gray-500">
                        Choose a payment method to see the final payment currency.
                    </p>

                    <button id="checkoutSubmitButton" type="submit"
                        class="btn-main mt-5 w-full {{ $hasUnavailableItems ? 'cursor-not-allowed opacity-60' : '' }}"
                        @disabled($hasUnavailableItems)>
                        <x-ui.icon name="lock-keyhole" class="h-4 w-4" />
                        <span data-button-label>{{ $hasUnavailableItems ? 'Checkout Paused' : 'Choose Payment Method' }}</span>
                    </button>

                    <p class="mt-3 text-xs leading-5 text-gray-500">
                        Stock is reserved only after the invoice is created. Payment instructions and live status appear on the next page.
                    </p>
                </aside>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
    <script nonce="{{ request()->attributes->get('csp_nonce') }}">
        (() => {
            const form = document.getElementById('checkoutForm');
            if (!form) return;

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const voucherUrl = @json($voucherUrl);
            const checkoutMode = @json($checkoutMode);
            const packageId = @json($package?->id);
            const quantity = @json($quantity);
            const subtotalIdr = @json($subtotalIdr);
            const subtotalUsdt = @json($subtotalUsdt);
            const checkoutAvailable = @json(! $hasUnavailableItems);
            const stablecoinPricingAvailable = @json($stablecoinPricingAvailable);
            let voucherQuote = null;
            let appliedVoucherCode = null;
            let voucherRequestController = null;
            let voucherRequestSequence = 0;
            let voucherRequestPending = false;
            let voucherMotionTimer = null;

            const formatIdr = value => `Rp ${Number(value).toLocaleString('id-ID')}`;
            const formatUsd = value => `$${Number(value).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 4,
            })}`;
            const formatStablecoin = (value, token) => `${Number(value).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 6,
            })} ${String(token || 'USD').toUpperCase()}`;

            const paymentMethod = () => form.querySelector('input[name="payment_method"]:checked')?.value || null;
            const selectedToken = () => form.querySelector('input[name="token"]:checked')?.value || null;
            const selectedCryptoToken = () => form.querySelector('input[name="crypto_token"]:checked')?.value || null;
            const selectedCoin = () => form.querySelector('input[name="coin"]:checked')?.value || null;
            const selectedStablecoin = () => paymentMethod() === 'binance_pay'
                ? selectedToken()
                : (paymentMethod() === 'crypto' ? selectedCoin() : null);
            const selectedPaymentToken = () => paymentMethod() === 'binance_pay'
                ? selectedToken()
                : (paymentMethod() === 'crypto' ? selectedCryptoToken() : null);

            function setButtonLabel(button, label) {
                const target = button?.querySelector('[data-button-label]');
                if (target) target.textContent = label;
            }

            function revealFinalSummaryWhenReady() {
                if (!window.matchMedia('(max-width: 1023px)').matches) return;

                const method = paymentMethod();
                const selectionReady = method === 'gopay_qris' ||
                    (method === 'binance_pay' && Boolean(selectedToken())) ||
                    (method === 'crypto' && Boolean(selectedCoin()));

                if (!selectionReady) return;

                window.requestAnimationFrame(() => {
                    document.getElementById('checkoutFinalSummary')?.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start',
                    });
                });
            }

            function voucherFeedback(message, variant = 'success') {
                const element = document.getElementById('checkoutVoucherFeedback');
                element.textContent = message;
                element.classList.remove('hidden', 'text-aksa-accent', 'text-red-300', 'text-gray-400', 'voucher-feedback-in');
                element.classList.add(
                    variant === 'success' ? 'text-aksa-accent' :
                    (variant === 'loading' ? 'text-gray-400' : 'text-red-300')
                );

                if (variant !== 'loading') {
                    void element.offsetWidth;
                    element.classList.add('voucher-feedback-in');
                }
            }

            function resetVoucherMotion() {
                clearTimeout(voucherMotionTimer);
                const panel = document.getElementById('checkoutVoucherPanel');
                const input = document.getElementById('checkoutVoucherCode');
                const button = document.getElementById('checkoutApplyVoucher');

                panel?.classList.remove('is-voucher-applied');
                input?.classList.remove('is-voucher-applied');
                button?.classList.remove('is-voucher-applied');
            }

            function showVoucherAppliedMotion() {
                resetVoucherMotion();
                const panel = document.getElementById('checkoutVoucherPanel');
                const input = document.getElementById('checkoutVoucherCode');
                const button = document.getElementById('checkoutApplyVoucher');
                const discountRow = document.getElementById('checkoutDiscountRow');

                void panel?.offsetWidth;
                panel?.classList.add('is-voucher-applied');
                input?.classList.add('is-voucher-applied');
                button?.classList.add('is-voucher-applied');
                setButtonLabel(button, 'Applied');
                discountRow?.classList.remove('voucher-discount-in');
                if (discountRow) void discountRow.offsetWidth;
                discountRow?.classList.add('voucher-discount-in');

                voucherMotionTimer = setTimeout(() => {
                    panel?.classList.remove('is-voucher-applied');
                    discountRow?.classList.remove('voucher-discount-in');
                }, 1050);
            }

            function clearVoucherFeedback() {
                abortVoucherRequest();
                voucherQuote = null;
                appliedVoucherCode = null;
                document.getElementById('checkoutVoucherValue').value = '';
                document.getElementById('checkoutVoucherFeedback').classList.add('hidden');
                resetVoucherMotion();
                setButtonLabel(document.getElementById('checkoutApplyVoucher'), 'Apply');
            }

            function abortVoucherRequest() {
                voucherRequestSequence += 1;
                voucherRequestController?.abort();
                voucherRequestController = null;
                voucherRequestPending = false;
            }

            function handleVoucherRefreshError(error) {
                if (error?.name === 'AbortError') return;

                resetVoucherMotion();
                setButtonLabel(document.getElementById('checkoutApplyVoucher'), 'Apply');
                voucherFeedback(error.message || 'Voucher could not be applied.', 'error');
                updateTotals();
            }

            function refreshCryptoNetworkOptions() {
                const token = selectedCryptoToken();
                const networkOptions = document.getElementById('checkoutCryptoNetworkOptions');
                const prompt = document.getElementById('checkoutCryptoNetworkPrompt');

                networkOptions?.classList.toggle('hidden', !token);

                if (prompt) {
                    prompt.textContent = token ? `Choose a network for ${token.toUpperCase()}` : 'Select a coin first';
                }

                form.querySelectorAll('[data-checkout-crypto-network-option]').forEach(option => {
                    const input = option.querySelector('input[name="coin"]');
                    const matchesToken = option.dataset.token === token;

                    option.classList.toggle('hidden', !matchesToken);
                    input.disabled = !matchesToken;

                    if (!matchesToken) {
                        input.checked = false;
                        option.classList.remove('active');
                    }
                });
            }

            function updateTotals() {
                const method = paymentMethod();
                const stablecoin = method === 'crypto' || method === 'binance_pay';
                const stablecoinReady = stablecoin && Boolean(selectedStablecoin());
                const paymentToken = selectedPaymentToken();
                const qris = method === 'gopay_qris';
                const displayCurrency = window.getAksaDisplayCurrency?.() ||
                    document.documentElement.dataset.displayCurrency ||
                    'idr';
                const displaySubtotal = displayCurrency === 'usd'
                    ? (stablecoinPricingAvailable ? formatUsd(subtotalUsdt) : 'USD unavailable')
                    : formatIdr(subtotalIdr);
                const subtotal = stablecoin
                    ? formatUsd(subtotalUsdt)
                    : (qris ? formatIdr(subtotalIdr) : displaySubtotal);
                const total = stablecoin
                    ? (stablecoinReady
                        ? `${formatStablecoin(voucherQuote ? voucherQuote.final_usdt : subtotalUsdt, paymentToken)} + unique amount`
                        : 'Complete coin selection')
                    : (qris
                        ? `${formatIdr(voucherQuote ? voucherQuote.final_idr : subtotalIdr)} base + platform fee + unique amount`
                        : 'Select payment');
                const discount = stablecoin
                    ? `-${formatStablecoin(voucherQuote?.discount_usdt || 0, paymentToken)}`
                    : `-${formatIdr(voucherQuote?.discount_idr || 0)}`;

                window.animateAksaValue?.(document.getElementById('checkoutSubtotal'), subtotal);
                window.animateAksaValue?.(document.getElementById('checkoutTotal'), total);
                document.getElementById('checkoutSubtotalLabel').textContent =
                    qris || stablecoin ? 'Catalog subtotal' : 'Subtotal';
                document.getElementById('checkoutTotalLabel').textContent =
                    qris || stablecoin ? 'Invoice amount' : 'Total';
                window.animateAksaValue?.(document.getElementById('checkoutDiscount'), discount);
                document.getElementById('checkoutDiscountRow').classList.toggle('hidden', !voucherQuote);

                const hint = document.getElementById('checkoutCurrencyHint');
                hint.textContent = qris
                    ? 'Final payment is charged in IDR through QRIS.'
                    : (stablecoin
                        ? (stablecoinReady
                            ? `Final payment uses ${String(paymentToken).toUpperCase()}. Send the exact invoice amount.`
                            : 'Choose a coin and network to calculate the final stablecoin amount.')
                        : `Catalog prices are shown in ${displayCurrency.toUpperCase()}. Choose a payment method to see the final payment currency.`);

                const submit = document.getElementById('checkoutSubmitButton');
                submit.disabled = !checkoutAvailable ||
                    voucherRequestPending ||
                    !method ||
                    (stablecoin && !stablecoinPricingAvailable) ||
                    (stablecoin && !stablecoinReady);
                setButtonLabel(
                    submit,
                    !checkoutAvailable ? 'Checkout Paused' :
                    (voucherRequestPending ? 'Checking Voucher...' :
                        (!method ? 'Choose Payment Method' :
                            (stablecoin && !stablecoinReady ? 'Complete Payment Selection' : 'Create Secure Invoice')))
                );
            }

            async function refreshVoucher() {
                if (!appliedVoucherCode) return;

                const method = paymentMethod();
                const stablecoin = selectedStablecoin();

                if (!method || ((method === 'crypto' || method === 'binance_pay') && !stablecoin)) {
                    throw new Error('Complete the payment selection before applying a voucher.');
                }

                const requestSequence = ++voucherRequestSequence;
                voucherRequestController?.abort();
                voucherRequestController = new AbortController();
                const requestController = voucherRequestController;
                voucherRequestPending = true;
                resetVoucherMotion();
                setButtonLabel(document.getElementById('checkoutApplyVoucher'), 'Checking...');
                voucherFeedback('Checking voucher...', 'loading');
                updateTotals();
                const body = new FormData();
                body.set('code', appliedVoucherCode);
                body.set('payment_method', method);
                if (stablecoin) body.set('coin', stablecoin);

                if (checkoutMode === 'direct') {
                    body.set('package_id', String(packageId));
                    body.set('quantity', String(quantity));
                }

                try {
                    const response = await fetch(voucherUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body,
                        signal: requestController.signal,
                    });
                    const data = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        throw new Error(data.message || 'Voucher could not be applied.');
                    }

                    if (
                        requestSequence !== voucherRequestSequence ||
                        method !== paymentMethod() ||
                        stablecoin !== selectedStablecoin()
                    ) {
                        return false;
                    }

                    voucherQuote = data;
                    appliedVoucherCode = data.code;
                    document.getElementById('checkoutVoucherCode').value = appliedVoucherCode;
                    document.getElementById('checkoutVoucherValue').value = appliedVoucherCode;
                    voucherFeedback(`${data.discount_percent}% voucher applied to this checkout.`, 'success');
                    updateTotals();
                    showVoucherAppliedMotion();

                    return true;
                } finally {
                    if (requestSequence === voucherRequestSequence) {
                        voucherRequestController = null;
                        voucherRequestPending = false;
                        setButtonLabel(
                            document.getElementById('checkoutApplyVoucher'),
                            voucherQuote ? 'Applied' : 'Apply'
                        );
                        updateTotals();
                    }
                }
            }

            form.querySelectorAll('input[name="payment_method"]').forEach(input => {
                input.addEventListener('change', () => {
                    abortVoucherRequest();
                    form.querySelectorAll('[data-checkout-payment-card]').forEach(card => {
                        card.classList.toggle('active', card.contains(input));
                    });
                    document.getElementById('checkoutBinanceOptions')?.classList.toggle(
                        'hidden',
                        paymentMethod() !== 'binance_pay'
                    );
                    document.getElementById('checkoutCryptoOptions').classList.toggle(
                        'hidden',
                        paymentMethod() !== 'crypto'
                    );
                    voucherQuote = null;
                    document.getElementById('checkoutVoucherValue').value = '';
                    updateTotals();

                    if (appliedVoucherCode) {
                        refreshVoucher().catch(handleVoucherRefreshError);
                    }

                    revealFinalSummaryWhenReady();
                });
            });

            form.querySelectorAll('input[name="token"]').forEach(input => {
                input.addEventListener('change', () => {
                    abortVoucherRequest();
                    document.getElementById('checkoutBinanceOptions')
                        ?.querySelectorAll('.crypto-coin-option')
                        .forEach(option => option.classList.toggle(
                            'active',
                            option.contains(input) && input.checked
                        ));
                    voucherQuote = null;
                    document.getElementById('checkoutVoucherValue').value = '';
                    updateTotals();

                    if (appliedVoucherCode) {
                        refreshVoucher().catch(handleVoucherRefreshError);
                    }

                    revealFinalSummaryWhenReady();
                });
            });

            form.querySelectorAll('input[name="crypto_token"]').forEach(input => {
                input.addEventListener('change', () => {
                    abortVoucherRequest();
                    form.querySelectorAll('[data-checkout-crypto-token-option]').forEach(option => {
                        option.classList.toggle('active', option.contains(input) && input.checked);
                    });
                    form.querySelectorAll('input[name="coin"]').forEach(networkInput => {
                        networkInput.checked = false;
                        networkInput.closest('[data-checkout-crypto-network-option]')?.classList.remove('active');
                    });
                    refreshCryptoNetworkOptions();
                    voucherQuote = null;
                    document.getElementById('checkoutVoucherValue').value = '';
                    updateTotals();

                    if (appliedVoucherCode) {
                        voucherFeedback('Choose a network to recheck this voucher.', 'loading');
                    }
                });
            });

            form.querySelectorAll('input[name="coin"]').forEach(input => {
                input.addEventListener('change', () => {
                    abortVoucherRequest();
                    form.querySelectorAll('[data-checkout-crypto-network-option]').forEach(option => {
                        option.classList.toggle('active', option.contains(input) && input.checked);
                    });
                    voucherQuote = null;
                    document.getElementById('checkoutVoucherValue').value = '';
                    updateTotals();

                    if (appliedVoucherCode) {
                        refreshVoucher().catch(handleVoucherRefreshError);
                    }

                    revealFinalSummaryWhenReady();
                });
            });

            document.getElementById('checkoutOrderReviewToggle')?.addEventListener('click', function() {
                const review = this.closest('.checkout-order-review');
                const expanded = this.getAttribute('aria-expanded') === 'true';
                this.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                review?.classList.toggle('is-open', !expanded);
            });

            document.getElementById('checkoutApplyVoucher')?.addEventListener('click', async function() {
                appliedVoucherCode = document.getElementById('checkoutVoucherCode').value.trim().toUpperCase();

                if (!appliedVoucherCode) {
                    voucherFeedback('Enter a voucher code first.', 'error');
                    return;
                }

                this.disabled = true;
                setButtonLabel(this, 'Checking...');

                try {
                    if (await refreshVoucher()) {
                        window.showAppToast?.('Voucher applied', 'Checkout total was updated.', {
                            variant: 'success',
                        });
                    }
                } catch (error) {
                    if (error?.name !== 'AbortError') {
                        voucherQuote = null;
                        document.getElementById('checkoutVoucherValue').value = '';
                        resetVoucherMotion();
                        voucherFeedback(error.message, 'error');
                        updateTotals();
                    }
                } finally {
                    this.disabled = false;
                    setButtonLabel(this, voucherQuote ? 'Applied' : 'Apply');
                    this.classList.toggle('is-voucher-applied', Boolean(voucherQuote));
                }
            });

            document.getElementById('checkoutVoucherCode')?.addEventListener('input', function() {
                this.value = this.value.toUpperCase();

                if (appliedVoucherCode && this.value.trim() !== appliedVoucherCode) {
                    clearVoucherFeedback();
                    updateTotals();
                }
            });

            form.addEventListener('submit', async event => {
                const method = paymentMethod();

                if (form.dataset.checkoutSubmitting === 'true') {
                    event.preventDefault();
                    return;
                }

                if (voucherRequestPending) {
                    event.preventDefault();
                    window.showAppToast?.('Voucher is still checking', 'Wait for the voucher result before creating the invoice.', {
                        variant: 'warning',
                    });
                    return;
                }

                if (!checkoutAvailable || !method) {
                    event.preventDefault();
                    window.showAppToast?.('Select payment', 'Choose one payment method first.', {
                        variant: 'warning',
                    });
                    return;
                }

                if (method === 'crypto' && !selectedCryptoToken()) {
                    event.preventDefault();
                    window.showAppToast?.('Select coin', 'Choose USDT or USDC first.', {
                        variant: 'warning',
                    });
                    return;
                }

                if (method === 'crypto' && !selectedCoin()) {
                    event.preventDefault();
                    window.showAppToast?.('Select network', 'Choose a coin and blockchain network.', {
                        variant: 'warning',
                    });
                    return;
                }

                if (method === 'binance_pay' && !selectedToken()) {
                    event.preventDefault();
                    window.showAppToast?.('Select coin', 'Choose USDT or USDC for Binance Pay.', {
                        variant: 'warning',
                    });
                    return;
                }

                event.preventDefault();
                form.dataset.checkoutSubmitting = 'true';
                const submit = document.getElementById('checkoutSubmitButton');
                submit.disabled = true;
                setButtonLabel(submit, 'Creating Invoice...');

                try {
                    const response = await window.aksaFetchWithCsrf(form.action, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: new FormData(form),
                    });
                    const data = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        if (data.redirect_url) {
                            window.showAppToast?.('Existing payment found', data.message || 'Continue your active payment.', {
                                variant: 'warning',
                            });
                            window.setTimeout(() => window.location.assign(data.redirect_url), 650);
                            return;
                        }

                        throw new Error(data.message || 'The invoice could not be created.');
                    }

                    const summary = document.getElementById('checkoutFinalSummary');
                    await window.animateAksaCheckoutSuccess?.(submit, summary);
                    window.location.assign(data.instruction_url || data.redirect_url || '/orders');
                } catch (error) {
                    form.dataset.checkoutSubmitting = 'false';
                    submit.classList.remove('checkout-submit-success');
                    window.showAppToast?.('Checkout not created', error.message || 'Review the checkout and try again.', {
                        variant: 'error',
                    });
                    updateTotals();
                }
            });

            window.addEventListener('aksa:currency-change', updateTotals);
            window.addEventListener('aksa:before-page-swap', () => {
                abortVoucherRequest();
                window.removeEventListener('aksa:currency-change', updateTotals);
            }, {
                once: true,
            });

            refreshCryptoNetworkOptions();
            updateTotals();
        })();
    </script>
@endpush
