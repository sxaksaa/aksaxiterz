@php
    $otherCartQuantity = $cartItems->sum('quantity') - $item->quantity;
    $remainingCartCapacity = max(1, \App\Services\CartService::MAX_TOTAL_QUANTITY - $otherCartQuantity);
    $maxItemQuantity = min($item->available_stock, $remainingCartCapacity);
    $itemCheckoutAvailable = (bool) $item->is_checkout_available &&
        $item->available_stock >= $item->quantity;
    $decreaseQuantityTarget = max(1, min((int) $item->quantity - 1, (int) $item->available_stock));
    $canDecreaseQuantity = $item->product?->isReadyForAutomaticCheckout() &&
        $item->quantity > 1 && $item->available_stock >= 1;
@endphp
<div class="cart-package-row px-5 py-4"
    data-cart-item="{{ $item->id }}"
    data-cart-item-quantity="{{ $item->quantity }}"
    data-cart-package-id="{{ $item->package_id }}"
    data-cart-restore-url="{{ route('cart.items.store', $item->product) }}"
    data-cart-item-checkout-ready="{{ $itemCheckoutAvailable ? 'true' : 'false' }}">
    <div class="min-w-0">
        <p class="font-semibold text-white">{{ $item->package->name }}</p>
        <p class="mt-1 text-xs {{ $itemCheckoutAvailable ? 'text-aksa-accent' : 'text-amber-200' }}">
            {{ $itemCheckoutAvailable ? $item->available_stock.' available · Auto delivery' : 'This selection needs to be reviewed' }}
        </p>
    </div>

    <div class="cart-package-actions">
        <div class="font-semibold text-white" data-display-price data-cart-line-total
            data-price-idr="{{ (int) $item->package->price * (int) $item->quantity }}"
            data-price-usd="{{ $item->package->price_usdt !== null && (float) $item->package->price_usdt > 0 ? (float) $item->package->price_usdt * (int) $item->quantity : '' }}">
            Rp {{ number_format($item->package->price * $item->quantity, 0, ',', '.') }}
        </div>

        <form method="POST" action="{{ route('cart.items.update', $item) }}"
            class="quantity-stepper" data-cart-quantity-form
            aria-label="Quantity for {{ $item->product->name }} {{ $item->package->name }}">
            @csrf
            @method('PATCH')
            <button type="submit" name="quantity" value="{{ $decreaseQuantityTarget }}"
                class="quantity-stepper-button" data-cart-quantity-direction="down"
                aria-label="Decrease {{ $item->product->name }} {{ $item->package->name }} quantity"
                @disabled(! $canDecreaseQuantity)>−</button>
            <output class="quantity-stepper-value" aria-live="polite">{{ $item->quantity }}</output>
            <button type="submit" name="quantity" value="{{ $item->quantity + 1 }}"
                class="quantity-stepper-button" data-cart-quantity-direction="up"
                aria-label="Increase {{ $item->product->name }} {{ $item->package->name }} quantity"
                @disabled(! $itemCheckoutAvailable || $item->quantity >= $maxItemQuantity)>+</button>
        </form>

        <form method="POST" action="{{ route('cart.items.destroy', $item) }}"
            data-cart-remove-form>
            @csrf
            @method('DELETE')
            <button type="submit" class="cart-remove-button" aria-label="Remove {{ $item->package->name }}">
                <x-ui.icon name="trash-2" class="h-4 w-4" />
                <span class="sr-only">Remove</span>
            </button>
        </form>
    </div>
</div>
