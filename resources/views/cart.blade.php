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
                    <form method="POST" action="{{ route('cart.clear') }}"
                        data-confirm="Clear every item from your cart?">
                        @csrf
                        @method('DELETE')
                        <button class="cart-clear-button" type="submit">
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
                    @foreach ($cartItems->groupBy('product_id') as $productItems)
                        @php
                            $groupProduct = $productItems->first()->product;
                            $groupNeedsReview = $productItems->contains(fn ($groupItem) =>
                                ! $groupItem->is_checkout_available || $groupItem->available_stock < $groupItem->quantity
                            );
                        @endphp
                        <article class="panel-card motion-card overflow-hidden {{ $groupNeedsReview ? 'border-amber-400/30' : '' }}"
                            data-cart-product-group>
                            <div class="border-b border-white/5 px-5 py-4">
                                <p class="text-xs uppercase tracking-normal text-aksa-accent">
                                    {{ $groupProduct->category?->name ?? 'Product' }}
                                </p>
                                <a href="{{ route('products.show', $groupProduct) }}"
                                    class="mt-1 block truncate text-lg font-semibold text-white transition hover:text-aksa-accent">
                                    {{ $groupProduct->name }}
                                </a>
                                <p class="mt-1 text-xs text-gray-400" data-cart-group-count>
                                    {{ $productItems->count() }} {{ \Illuminate\Support\Str::plural('package', $productItems->count()) }} selected
                                </p>
                            </div>

                            <div class="divide-y divide-white/5">
                                @foreach ($productItems as $item)
                                    @include('partials.cart-package-row', ['cartItems' => $cartItems, 'item' => $item])
                                @endforeach
                            </div>
                        </article>
                    @endforeach
                </section>

                <aside class="cart-summary-sticky product-section fade-up lg:sticky lg:top-24">
                    <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">Cart Summary</p>
                    <h2 id="cartBundleCount" class="mt-1 text-xl font-semibold text-white"
                        data-cart-distinct-items="{{ $cartItems->count() }}"
                        data-cart-total-quantity="{{ $cartItems->sum('quantity') }}">
                        {{ $cartItems->count() }} packages · {{ $cartItems->sum('quantity') }} licenses
                    </h2>

                    <div class="mt-5 summary-row">
                        <span>Subtotal</span>
                        <span class="font-semibold text-white" data-display-price data-cart-subtotal
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
