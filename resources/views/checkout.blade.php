@extends('layouts.app')

@section('content')
    @php
        $isDirect = $checkoutMode === 'direct';
        $itemCount = $checkoutItems->count();
        $totalQuantity = (int) $checkoutItems->sum('quantity');
        $backUrl = $isDirect && $product
            ? route('products.show', $product)
            : route('cart.index');
        $checkoutConfig = [
            'voucherUrl' => $voucherUrl,
            'checkoutMode' => $checkoutMode,
            'packageId' => $package?->id,
            'quantity' => $quantity,
            'subtotalIdr' => $subtotalIdr,
            'subtotalUsdt' => $subtotalUsdt,
            'checkoutAvailable' => ! $hasUnavailableItems,
            'stablecoinPricingAvailable' => $stablecoinPricingAvailable,
        ];
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

        <form id="checkoutForm" method="POST" action="{{ $checkoutAction }}"
            data-checkout-config='@json($checkoutConfig)'>
            @csrf
            @if ($isDirect)
                <input type="hidden" name="package_id" value="{{ $package->id }}">
                <input type="hidden" name="quantity" value="{{ $quantity }}">
                <input type="hidden" name="review_signature" value="{{ $reviewSignature }}">
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

                    <section class="checkout-payment-section product-section">
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
                            <div id="checkoutBinanceOptions" class="checkout-options-reveal" aria-hidden="true" inert>
                                <div class="checkout-options-reveal-inner">
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
                            </div>
                        @endif

                        <div id="checkoutCryptoOptions" class="checkout-options-reveal" aria-hidden="true" inert>
                            <div class="checkout-options-reveal-inner">
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

                                <div id="checkoutCryptoNetworkOptions" class="checkout-network-reveal" aria-hidden="true" inert>
                                    <div class="checkout-network-reveal-inner">
                                        <div class="mb-2 flex items-center justify-between gap-3">
                                            <p class="text-xs font-semibold uppercase tracking-normal text-gray-400">Network</p>
                                            <span id="checkoutCryptoNetworkPrompt" class="text-xs text-gray-500">Select a coin first</span>
                                        </div>
                                        <div id="checkoutCryptoNetworkGrid" class="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Select crypto network">
                                    @if(filled(config('services.crypto_direct.networks.usdtavaxc.address')))
                                    <label class="crypto-coin-option cursor-pointer text-left"
                                        data-checkout-crypto-network-option data-token="usdt">
                                        <input class="sr-only" type="radio" name="coin" value="usdtavaxc" disabled>
                                        <span class="crypto-coin-header">
                                            <x-ui.icon name="tether" class="crypto-token-icon" />
                                            <span class="crypto-token-copy">
                                                <span class="crypto-token-title">Avalanche C-Chain</span>
                                                <span class="crypto-token-subtitle">AVAXC</span>
                                            </span>
                                        </span>
                                    </label>
                                    @endif
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
                            </div>
                        </div>
                    </section>
                </div>

                <aside id="checkoutFinalSummary" class="checkout-final-summary product-section lg:sticky lg:top-24">
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

                    <div id="checkoutSubmitPanel" class="checkout-submit-panel mt-5">
                        <div class="summary-row mb-2" data-checkout-secondary-total>
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
                    </div>
                </aside>
            </div>
        </form>
    </div>
@endsection
