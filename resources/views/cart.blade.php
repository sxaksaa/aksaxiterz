@extends('layouts.app')

@section('content')
    <div class="page-shell py-6 md:py-10">
        <section class="product-hero mb-6 fade-up">
            <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">Custom Bundle</p>
                    <h1 class="mt-2 text-3xl font-bold text-white md:text-4xl">Your cart</h1>
                    <p class="mt-2 max-w-2xl text-sm text-gray-400">
                        Combine different products and packages into one payment. Stock is reserved only after checkout starts.
                    </p>
                </div>
                @if ($cartItems->isNotEmpty())
                    <form method="POST" action="{{ route('cart.clear') }}">
                        @csrf
                        @method('DELETE')
                        <button class="btn-footer-secondary" type="submit">
                            <x-ui.icon name="trash-2" class="h-4 w-4" />
                            <span>Clear Cart</span>
                        </button>
                    </form>
                @endif
            </div>
        </section>

        @if ($errors->has('cart'))
            <div class="mb-5 rounded-xl border border-red-500/30 bg-red-500/10 p-4 text-sm text-red-200">
                {{ $errors->first('cart') }}
            </div>
        @endif

        @if ($cartItems->isEmpty())
            <section class="empty-state fade-up">
                <span class="empty-state-icon">
                    <x-ui.icon name="shopping-cart" class="h-6 w-6" />
                </span>
                <h2 class="text-xl font-semibold text-white">Your cart is empty</h2>
                <p class="mt-2 text-sm text-gray-400">Choose a product and package to start building a bundle.</p>
                <a href="/" class="btn-main mt-5 inline-flex px-5 py-3">
                    <x-ui.icon name="box" class="h-4 w-4" />
                    <span>Browse Products</span>
                </a>
            </section>
        @else
            <div class="grid gap-6 lg:grid-cols-[1.35fr_0.85fr] lg:items-start">
                <section class="grid gap-4">
                    @foreach ($cartItems as $item)
                        @php
                            $otherCartQuantity = $cartItems->sum('quantity') - $item->quantity;
                            $remainingCartCapacity = max(1, \App\Services\CartService::MAX_TOTAL_QUANTITY - $otherCartQuantity);
                            $maxItemQuantity = min($item->available_stock, $remainingCartCapacity);
                        @endphp
                        <article class="panel-card motion-card p-5" data-cart-item="{{ $item->id }}">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <p class="text-xs uppercase tracking-normal text-aksa-accent">{{ $item->product->category?->name ?? 'Product' }}</p>
                                    <h2 class="mt-1 truncate text-lg font-semibold text-white">{{ $item->product->name }}</h2>
                                    <p class="mt-1 text-sm text-gray-400">{{ $item->package->name }}</p>
                                    <p class="mt-2 text-xs {{ $item->available_stock >= $item->quantity ? 'text-aksa-accent' : 'text-red-300' }}">
                                        {{ $item->available_stock }} keys currently available
                                    </p>
                                </div>

                                <div class="flex flex-wrap items-center gap-3 sm:justify-end">
                                    <div class="text-right">
                                        <div class="font-semibold text-white" data-cart-line-idr>
                                            Rp {{ number_format($item->package->price * $item->quantity) }}
                                        </div>
                                        <div class="text-xs text-gray-400" data-cart-line-usdt>
                                            ${{ number_format($item->package->price_usdt * $item->quantity, 2) }}
                                        </div>
                                    </div>

                                    <form method="POST" action="{{ route('cart.items.update', $item) }}"
                                        class="quantity-stepper" data-cart-quantity-form
                                        aria-label="Quantity for {{ $item->product->name }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" name="quantity" value="{{ $item->quantity - 1 }}"
                                            class="quantity-stepper-button" aria-label="Decrease {{ $item->product->name }} quantity"
                                            @disabled($item->quantity <= 1)>−</button>
                                        <output class="quantity-stepper-value" aria-live="polite">{{ $item->quantity }}</output>
                                        <button type="submit" name="quantity" value="{{ $item->quantity + 1 }}"
                                            class="quantity-stepper-button" aria-label="Increase {{ $item->product->name }} quantity"
                                            @disabled($item->quantity >= $maxItemQuantity)>+</button>
                                    </form>

                                    <form method="POST" action="{{ route('cart.items.destroy', $item) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="h-11 rounded-xl border border-red-500/30 px-3 text-sm text-red-300 transition hover:bg-red-500/10">
                                            <span class="inline-flex items-center gap-2">
                                                <x-ui.icon name="trash-2" class="h-4 w-4" />
                                                <span>Remove</span>
                                            </span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </section>

                <aside class="product-section fade-up">
                    <div class="mb-5">
                        <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">Bundle Checkout</p>
                        <h2 id="cartBundleCount" class="mt-1 text-xl font-semibold text-white">{{ $cartItems->count() }} packages · {{ $cartItems->sum('quantity') }} keys</h2>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span class="support-pill">
                                <x-ui.icon name="shield-check" class="h-4 w-4" />
                                <span>Secure checkout</span>
                            </span>
                            <span class="support-pill">
                                <x-ui.icon name="key-round" class="h-4 w-4" />
                                <span>Instant delivery</span>
                            </span>
                        </div>
                    </div>

                    <p class="mb-2 text-xs font-semibold uppercase tracking-normal text-gray-400">Payment Method</p>
                    <div class="grid gap-2">
                        <button type="button" class="checkout-card payment-card p-4 text-left" data-cart-payment="pakasir">
                            <span class="payment-card-heading">
                                <span class="payment-card-icon">
                                    <x-ui.icon name="qr-code" class="h-5 w-5" />
                                </span>
                                <span class="font-semibold text-white">QRIS</span>
                            </span>
                            <span class="mt-1 block text-xs text-gray-400">Pay the bundle total in IDR</span>
                        </button>
                        @if ($binancePayAvailable)
                            <button type="button" class="checkout-card payment-card p-4 text-left" data-cart-payment="binance_pay">
                                <span class="payment-card-heading">
                                    <span class="payment-card-icon">
                                        <x-ui.icon name="binance" class="h-5 w-5 text-[#F0B90B]" />
                                    </span>
                                    <span class="font-semibold text-white">Binance Pay</span>
                                </span>
                                <span class="mt-1 block text-xs text-gray-400">Choose USDT or USDC</span>
                            </button>
                        @endif
                        <button type="button" class="checkout-card payment-card p-4 text-left" data-cart-payment="crypto">
                            <span class="payment-card-heading">
                                <span class="payment-card-icon">
                                    <x-ui.icon name="wallet" class="h-5 w-5" />
                                </span>
                                <span class="font-semibold text-white">Crypto Address</span>
                            </span>
                            <span class="mt-1 block text-xs text-gray-400">One coin and network for the whole bundle</span>
                        </button>
                    </div>

                    @if ($binancePayAvailable)
                        <div id="cartBinanceTokens" class="mt-4 hidden">
                            <p class="mb-2 text-xs font-semibold uppercase tracking-normal text-gray-400">Binance Pay Coin</p>
                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" class="crypto-coin-option" data-cart-binance-token="usdt">
                                    <span class="crypto-coin-header">
                                        <x-ui.icon name="tether" class="crypto-token-icon" />
                                        <span class="crypto-token-copy">
                                            <span class="crypto-token-title">USDT</span>
                                            <span class="crypto-token-subtitle">Tether</span>
                                        </span>
                                    </span>
                                </button>
                                <button type="button" class="crypto-coin-option" data-cart-binance-token="usdc">
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
                    @endif

                    <div id="cartCryptoOptions" class="mt-4 hidden">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-normal text-gray-400">Coin & Network</p>
                        <div class="grid gap-2">
                            <button type="button" class="crypto-coin-option text-left" data-cart-coin="usdtbsc">
                                <span class="crypto-coin-header">
                                    <x-ui.icon name="tether" class="crypto-token-icon" />
                                    <span class="crypto-token-copy">
                                        <span class="crypto-token-title">USDT</span>
                                        <span class="crypto-token-subtitle">BNB Smart Chain (BEP20)</span>
                                    </span>
                                </span>
                            </button>
                            <button type="button" class="crypto-coin-option text-left" data-cart-coin="usdttrc20">
                                <span class="crypto-coin-header">
                                    <x-ui.icon name="tether" class="crypto-token-icon" />
                                    <span class="crypto-token-copy">
                                        <span class="crypto-token-title">USDT</span>
                                        <span class="crypto-token-subtitle">Tron (TRC20)</span>
                                    </span>
                                </span>
                            </button>
                            <button type="button" class="crypto-coin-option text-left" data-cart-coin="usdcbsc">
                                <span class="crypto-coin-header">
                                    <x-ui.icon name="usdc" class="crypto-token-icon" />
                                    <span class="crypto-token-copy">
                                        <span class="crypto-token-title">USDC</span>
                                        <span class="crypto-token-subtitle">BNB Smart Chain (BEP20)</span>
                                    </span>
                                </span>
                            </button>
                        </div>
                    </div>

                    <div class="my-5 rounded-xl border border-[#27272A] bg-black/15 p-4">
                        <label for="cartVoucherCode" class="mb-2 block text-xs font-semibold uppercase tracking-normal text-gray-400">
                            Voucher Code
                        </label>
                        <div class="grid gap-2 sm:grid-cols-[1fr_auto] lg:grid-cols-1 xl:grid-cols-[1fr_auto]">
                            <input id="cartVoucherCode" class="search-bar min-w-0 uppercase" maxlength="50"
                                placeholder="Enter voucher code" autocomplete="off">
                            <button id="cartApplyVoucher" type="button" class="btn-footer h-12">
                                <x-ui.icon name="ticket-percent" class="h-4 w-4" />
                                <span>Apply</span>
                            </button>
                        </div>
                        <p id="cartVoucherFeedback" class="mt-2 hidden text-xs"></p>
                    </div>

                    <div class="summary-row mb-2">
                        <span>Subtotal</span>
                        <span id="cartSubtotal">Select payment</span>
                    </div>
                    <div id="cartDiscountRow" class="summary-row mb-2 hidden">
                        <span>Voucher</span>
                        <span id="cartDiscount" class="text-aksa-accent">-</span>
                    </div>
                    <div class="summary-row">
                        <span>Total</span>
                        <span id="cartTotal" class="font-semibold text-aksa-accent">Select payment</span>
                    </div>

                    <button id="cartCheckoutButton" type="button" class="btn-main mt-5 w-full">
                        <x-ui.icon name="credit-card" class="h-4 w-4" />
                        <span data-button-label>Choose Payment Method</span>
                    </button>
                    <p class="mt-3 text-xs leading-5 text-gray-500">
                        All cart items must still be available when checkout starts. One failed item cancels the whole invoice.
                    </p>
                </aside>
            </div>
        @endif
    </div>

    @include('partials.pakasir-qris-modal')
    @include('partials.binance-pay-modal')
    @include('partials.direct-crypto-modal')
    @include('partials.payment-success-modal')
@endsection

@if ($cartItems->isNotEmpty())
    @push('scripts')
        <script nonce="{{ request()->attributes->get('csp_nonce') }}">
            (() => {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const checkoutUrl = @json(route('cart.checkout', [], false));
                const voucherUrl = @json(route('cart.vouchers.preview', [], false));
                let subtotalIdr = @json($subtotalIdr);
                let subtotalUsdt = @json($subtotalUsdt);
                let paymentMethod = null;
                let coin = null;
                let binanceToken = null;
                let voucherQuote = null;
                let voucherCode = null;

                const formatIdr = value => `Rp ${Number(value).toLocaleString('id-ID')}`;
                const formatUsd = value => `$${Number(value).toLocaleString(undefined, {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                })}`;

                function showToast(title, message, variant = 'info') {
                    window.showAppToast?.(title, message, { variant });
                }

                function selectedStablecoin() {
                    if (paymentMethod === 'binance_pay') return binanceToken;
                    if (paymentMethod === 'crypto') return coin;
                    return null;
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

                function updateTotals() {
                    const stablecoin = paymentMethod === 'crypto' || paymentMethod === 'binance_pay';
                    const subtotal = stablecoin ? formatUsd(subtotalUsdt) : (paymentMethod === 'pakasir' ? formatIdr(subtotalIdr) : 'Select payment');
                    const total = stablecoin
                        ? `${formatUsd(voucherQuote ? voucherQuote.final_usdt : subtotalUsdt)} + unique amount`
                        : (paymentMethod === 'pakasir' ? formatIdr(voucherQuote ? voucherQuote.final_idr : subtotalIdr) : 'Select payment');
                    const discount = stablecoin
                        ? `-${formatUsd(voucherQuote?.discount_usdt || 0)}`
                        : `-${formatIdr(voucherQuote?.discount_idr || 0)}`;

                    document.getElementById('cartSubtotal').innerText = subtotal;
                    document.getElementById('cartTotal').innerText = total;
                    document.getElementById('cartDiscount').innerText = discount;
                    document.getElementById('cartDiscountRow').classList.toggle('hidden', !voucherQuote);

                    const button = document.getElementById('cartCheckoutButton');
                    setButtonLabel(button, paymentMethod ? 'Pay Bundle' : 'Choose Payment Method');
                }

                function voucherFeedback(message, variant = 'success') {
                    const element = document.getElementById('cartVoucherFeedback');
                    element.innerText = message;
                    element.classList.remove('hidden', 'text-aksa-accent', 'text-red-300', 'text-gray-400');
                    element.classList.add(variant === 'success' ? 'text-aksa-accent' : (variant === 'loading' ? 'text-gray-400' : 'text-red-300'));
                }

                document.querySelectorAll('[data-cart-quantity-form]').forEach(form => {
                    form.addEventListener('submit', async event => {
                        event.preventDefault();

                        const submitter = event.submitter;
                        const quantity = Number(submitter?.value);
                        if (!submitter || !Number.isInteger(quantity) || quantity < 1) return;

                        const buttons = [...form.querySelectorAll('button[type="submit"]')];
                        const previousDisabledStates = buttons.map(button => button.disabled);
                        buttons.forEach(button => button.disabled = true);

                        const body = new FormData();
                        body.set('_method', 'PATCH');
                        body.set('quantity', String(quantity));

                        try {
                            const response = await fetch(form.action, {
                                method: 'POST',
                                headers: {
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-TOKEN': csrfToken,
                                },
                                body,
                            });
                            const data = await response.json().catch(() => ({}));
                            if (!response.ok) throw new Error(data.message || 'Cart quantity could not be updated.');

                            const card = form.closest('[data-cart-item]');
                            const output = form.querySelector('output');
                            const [decreaseButton, increaseButton] = buttons;

                            output.value = data.item.quantity;
                            output.textContent = data.item.quantity;
                            decreaseButton.value = data.item.quantity - 1;
                            increaseButton.value = data.item.quantity + 1;
                            decreaseButton.disabled = data.item.quantity <= 1;
                            increaseButton.disabled = data.item.quantity >= data.item.max_quantity;
                            card.querySelector('[data-cart-line-idr]').textContent = formatIdr(data.item.line_total_idr);
                            card.querySelector('[data-cart-line-usdt]').textContent = formatUsd(data.item.line_total_usdt);

                            subtotalIdr = data.cart.subtotal_idr;
                            subtotalUsdt = data.cart.subtotal_usdt;
                            document.getElementById('cartBundleCount').textContent =
                                `${data.cart.distinct_items} packages · ${data.cart.quantity} keys`;
                            document.querySelectorAll('[data-cart-count]').forEach(badge => {
                                badge.textContent = data.cart.quantity;
                                badge.classList.toggle('hidden', data.cart.quantity < 1);
                            });
                            data.cart.item_limits.forEach(limit => {
                                const quantityForm = document.querySelector(
                                    `[data-cart-item="${limit.id}"] [data-cart-quantity-form]`
                                );
                                if (!quantityForm) return;

                                const quantityOutput = quantityForm.querySelector('output');
                                const currentQuantity = Number(quantityOutput.value || quantityOutput.textContent);
                                const [decrease, increase] = quantityForm.querySelectorAll('button[type="submit"]');
                                decrease.value = currentQuantity - 1;
                                increase.value = currentQuantity + 1;
                                decrease.disabled = currentQuantity <= 1;
                                increase.disabled = currentQuantity >= limit.max_quantity;
                            });

                            voucherQuote = null;
                            updateTotals();

                            if (voucherCode) {
                                try {
                                    await refreshVoucher();
                                } catch (voucherError) {
                                    voucherQuote = null;
                                    voucherFeedback(voucherError.message, 'error');
                                    updateTotals();
                                }
                            }
                        } catch (error) {
                            showToast('Cart update failed', error.message, 'error');
                            buttons.forEach((button, index) => button.disabled = previousDisabledStates[index]);
                        }
                    });
                });

                async function refreshVoucher() {
                    if (!voucherCode) return;
                    const selectedCoin = selectedStablecoin();

                    if (!paymentMethod || ((paymentMethod === 'crypto' || paymentMethod === 'binance_pay') && !selectedCoin)) {
                        voucherQuote = null;
                        voucherFeedback('Complete the payment selection to check this voucher.', 'error');
                        updateTotals();
                        throw new Error('Complete the payment selection before applying a voucher.');
                    }

                    voucherFeedback('Checking voucher...', 'loading');
                    const body = new FormData();
                    body.set('code', voucherCode);
                    body.set('payment_method', paymentMethod);
                    if (selectedCoin) body.set('coin', selectedCoin);

                    const response = await fetch(voucherUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body,
                    });
                    const data = await response.json().catch(() => ({}));

                    if (!response.ok) throw new Error(data.message || 'Voucher could not be applied.');
                    voucherQuote = data;
                    voucherCode = data.code;
                    document.getElementById('cartVoucherCode').value = voucherCode;
                    const itemLabel = data.discount_units === 1 ? 'license' : 'licenses';
                    voucherFeedback(`${data.discount_percent}% voucher applied with a cap per license (${data.discount_units} ${itemLabel}).`, 'success');
                    updateTotals();
                }

                document.querySelectorAll('[data-cart-payment]').forEach(button => {
                    button.addEventListener('click', () => {
                        paymentMethod = button.dataset.cartPayment;
                        document.querySelectorAll('[data-cart-payment]').forEach(option => option.classList.toggle('active', option === button));
                        document.getElementById('cartBinanceTokens')?.classList.toggle('hidden', paymentMethod !== 'binance_pay');
                        document.getElementById('cartCryptoOptions')?.classList.toggle('hidden', paymentMethod !== 'crypto');
                        voucherQuote = null;
                        updateTotals();
                        if (voucherCode) refreshVoucher().catch(error => voucherFeedback(error.message, 'error'));
                    });
                });

                document.querySelectorAll('[data-cart-binance-token]').forEach(button => {
                    button.addEventListener('click', () => {
                        binanceToken = button.dataset.cartBinanceToken;
                        document.querySelectorAll('[data-cart-binance-token]').forEach(option => option.classList.toggle('active', option === button));
                        voucherQuote = null;
                        updateTotals();
                        if (voucherCode) refreshVoucher().catch(error => voucherFeedback(error.message, 'error'));
                    });
                });

                document.querySelectorAll('[data-cart-coin]').forEach(button => {
                    button.addEventListener('click', () => {
                        coin = button.dataset.cartCoin;
                        document.querySelectorAll('[data-cart-coin]').forEach(option => option.classList.toggle('active', option === button));
                        voucherQuote = null;
                        updateTotals();
                        if (voucherCode) refreshVoucher().catch(error => voucherFeedback(error.message, 'error'));
                    });
                });

                document.getElementById('cartApplyVoucher').addEventListener('click', async function() {
                    voucherCode = document.getElementById('cartVoucherCode').value.trim().toUpperCase();
                    if (!voucherCode) {
                        voucherFeedback('Enter a voucher code first.', 'error');
                        return;
                    }

                    this.disabled = true;
                    try {
                        await refreshVoucher();
                        showToast('Voucher applied', 'The discount cap applies to each license quantity.', 'success');
                    } catch (error) {
                        voucherQuote = null;
                        voucherFeedback(error.message, 'error');
                        updateTotals();
                    } finally {
                        this.disabled = false;
                    }
                });

                document.getElementById('cartVoucherCode').addEventListener('input', function() {
                    this.value = this.value.toUpperCase();

                    if (voucherCode && this.value.trim() !== voucherCode) {
                        voucherCode = null;
                        voucherQuote = null;
                        document.getElementById('cartVoucherFeedback').classList.add('hidden');
                        updateTotals();
                    }
                });

                document.getElementById('cartCheckoutButton').addEventListener('click', async function() {
                    if (!paymentMethod) {
                        showToast('Select payment', 'Choose one payment method for the bundle.', 'warning');
                        return;
                    }
                    if (paymentMethod === 'crypto' && !coin) {
                        showToast('Select coin and network', 'Choose one crypto network for the bundle.', 'warning');
                        return;
                    }
                    if (paymentMethod === 'binance_pay' && !binanceToken) {
                        showToast('Select Binance Pay coin', 'Choose USDT or USDC.', 'warning');
                        return;
                    }

                    const body = new FormData();
                    body.set('payment_method', paymentMethod);
                    if (coin) body.set('coin', coin);
                    if (binanceToken) body.set('token', binanceToken);
                    if (voucherCode && voucherQuote) body.set('voucher_code', voucherCode);

                    this.disabled = true;
                    setButtonLabel(this, 'Preparing Bundle...');

                    try {
                        const response = await fetch(checkoutUrl, {
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
                            const error = new Error(data.message || 'Bundle checkout failed.');
                            error.redirectUrl = data.redirect_url;
                            throw error;
                        }

                        let opened = false;
                        if (paymentMethod === 'pakasir') opened = await window.openAksaQrisModal?.(data);
                        if (paymentMethod === 'binance_pay') opened = await window.openAksaBinancePayModal?.(data, { startPolling: true });
                        if (paymentMethod === 'crypto') opened = await window.openAksaCryptoModal?.(data, { startPolling: true });

                        document.querySelectorAll('[data-cart-count]').forEach(badge => badge.classList.add('hidden'));

                        if (!opened) {
                            window.location.href = data.payment_url || '/orders';
                            return;
                        }

                        setButtonLabel(this, 'Payment Pending');
                        showToast('Bundle invoice ready', 'Pay the exact amount shown to receive every license key.', 'success');
                    } catch (error) {
                        if (error.redirectUrl) {
                            window.location.href = error.redirectUrl;
                            return;
                        }

                        showToast('Checkout failed', error.message, 'error');
                        this.disabled = false;
                        setButtonLabel(this, 'Pay Bundle');
                    }
                });

                updateTotals();
            })();
        </script>
    @endpush
@endif
