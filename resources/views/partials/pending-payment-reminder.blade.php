@if (($pendingOrderCount ?? 0) > 0 && $pendingOrder && ! request()->is('orders', 'orders/*'))
    <div class="page-shell pending-payment-reminder-shell">
        <a href="{{ route('orders.payment', $pendingOrder->order_id) }}" class="pending-payment-reminder">
            <span class="pending-payment-reminder-dot" aria-hidden="true"></span>
            <span class="min-w-0 flex-1 truncate text-sm text-gray-300">
                <strong class="font-semibold text-white">
                    {{ $pendingOrderCount }} {{ \Illuminate\Support\Str::plural('payment', $pendingOrderCount) }}
                    {{ $pendingOrderCount === 1 ? 'is' : 'are' }} waiting
                </strong>
                <span class="hidden text-gray-500 sm:inline"> · {{ $pendingOrder->order_id }}</span>
            </span>
            <span class="inline-flex shrink-0 items-center gap-1 text-xs font-bold text-aksa-accent-soft">
                Continue Payment
                <x-ui.icon name="arrow-right" class="h-3.5 w-3.5" />
            </span>
        </a>
    </div>
@endif
