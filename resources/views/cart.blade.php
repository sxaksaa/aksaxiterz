@extends('layouts.app')

@section('content')
    <div class="page-shell py-6 md:py-10">
        <section class="product-hero mb-6 fade-up">
            <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">Custom Bundle</p>
                    <h1 class="mt-2 text-3xl font-bold text-white md:text-4xl">Your cart</h1>
                    <p class="mt-2 max-w-2xl text-sm text-gray-400">
                        Review products and quantities here. Payment method, voucher, and final currency are handled on the next checkout step.
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

        @if (session('info'))
            <div class="mb-5 rounded-xl border border-aksa-accent-35 bg-aksa-accent-10 p-4 text-sm text-aksa-accent-soft">
                {{ session('info') }}
            </div>
        @endif

        @if ($hasUnavailableItems)
            <div data-cart-checkout-paused
                class="mb-5 rounded-xl border border-amber-400/30 bg-amber-400/10 p-4 text-sm text-amber-100">
                One or more products are updating, out of stock, or unavailable. Review those items before continuing.
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
            <div class="grid gap-6 lg:grid-cols-[1.35fr_0.65fr] lg:items-start">
                <section class="grid gap-4">
                    @foreach ($cartItems as $item)
                        @php
                            $otherCartQuantity = $cartItems->sum('quantity') - $item->quantity;
                            $remainingCartCapacity = max(1, \App\Services\CartService::MAX_TOTAL_QUANTITY - $otherCartQuantity);
                            $maxItemQuantity = min($item->available_stock, $remainingCartCapacity);
                            $itemCheckoutAvailable = (bool) $item->is_checkout_available &&
                                $item->available_stock >= $item->quantity;
                            $decreaseQuantityTarget = max(
                                1,
                                min((int) $item->quantity - 1, (int) $item->available_stock)
                            );
                            $canDecreaseQuantity = $item->product?->isReadyForAutomaticCheckout() &&
                                $item->quantity > 1 &&
                                $item->available_stock >= 1;
                        @endphp
                        <article class="panel-card motion-card p-5 {{ $itemCheckoutAvailable ? '' : 'border-amber-400/30' }}"
                            data-cart-item="{{ $item->id }}"
                            data-cart-item-checkout-ready="{{ $itemCheckoutAvailable ? 'true' : 'false' }}">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <p class="text-xs uppercase tracking-normal text-aksa-accent">
                                        {{ $item->product->category?->name ?? 'Product' }}
                                    </p>
                                    <a href="{{ route('products.show', $item->product) }}"
                                        class="mt-1 block truncate text-lg font-semibold text-white transition hover:text-aksa-accent">
                                        {{ $item->product->name }}
                                    </a>
                                    <p class="mt-1 text-sm text-gray-400">{{ $item->package->name }}</p>
                                    <p class="mt-2 text-xs {{ $itemCheckoutAvailable ? 'text-aksa-accent' : 'text-amber-200' }}">
                                        {{ $itemCheckoutAvailable ? $item->available_stock.' keys currently available' : 'This selection needs to be reviewed' }}
                                    </p>
                                </div>

                                <div class="flex flex-wrap items-center gap-3 sm:justify-end">
                                    <div class="text-right">
                                        <div class="font-semibold text-white" data-display-price
                                            data-price-idr="{{ (int) $item->package->price * (int) $item->quantity }}"
                                            data-price-usd="{{ $item->package->price_usdt !== null && (float) $item->package->price_usdt > 0 ? (float) $item->package->price_usdt * (int) $item->quantity : '' }}">
                                            Rp {{ number_format($item->package->price * $item->quantity, 0, ',', '.') }}
                                        </div>
                                    </div>

                                    <form method="POST" action="{{ route('cart.items.update', $item) }}"
                                        class="quantity-stepper" aria-label="Quantity for {{ $item->product->name }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" name="quantity" value="{{ $decreaseQuantityTarget }}"
                                            class="quantity-stepper-button"
                                            aria-label="Decrease {{ $item->product->name }} quantity"
                                            @disabled(! $canDecreaseQuantity)>−</button>
                                        <output class="quantity-stepper-value" aria-live="polite">{{ $item->quantity }}</output>
                                        <button type="submit" name="quantity" value="{{ $item->quantity + 1 }}"
                                            class="quantity-stepper-button"
                                            aria-label="Increase {{ $item->product->name }} quantity"
                                            @disabled(! $itemCheckoutAvailable || $item->quantity >= $maxItemQuantity)>+</button>
                                    </form>

                                    <form method="POST" action="{{ route('cart.items.destroy', $item) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                            class="h-11 rounded-xl border border-red-500/30 px-3 text-sm text-red-300 transition hover:bg-red-500/10">
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

                <aside class="product-section fade-up lg:sticky lg:top-24">
                    <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">Cart Summary</p>
                    <h2 id="cartBundleCount" class="mt-1 text-xl font-semibold text-white">
                        {{ $cartItems->count() }} packages · {{ $cartItems->sum('quantity') }} keys
                    </h2>

                    <div class="mt-5 summary-row">
                        <span>Subtotal</span>
                        <span class="font-semibold text-white" data-display-price
                            data-price-idr="{{ (int) $subtotalIdr }}"
                            data-price-usd="{{ $stablecoinPricingAvailable ? (float) $subtotalUsdt : '' }}">
                            Rp {{ number_format($subtotalIdr, 0, ',', '.') }}
                        </span>
                    </div>

                    @if ($hasUnavailableItems)
                        <button type="button" class="btn-main mt-5 w-full cursor-not-allowed opacity-60" disabled>
                            <x-ui.icon name="alert-triangle" class="h-4 w-4" />
                            <span>Review Unavailable Items</span>
                        </button>
                    @else
                        <a href="{{ route('checkout.cart') }}" class="btn-main mt-5 flex w-full">
                            <x-ui.icon name="arrow-right" class="h-4 w-4" />
                            <span>Continue to Checkout</span>
                        </a>
                    @endif

                    <p class="mt-3 text-xs leading-5 text-gray-500">
                        You will choose QRIS, Binance Pay, or crypto and apply vouchers on the next page.
                    </p>
                </aside>
            </div>
        @endif
    </div>
@endsection
