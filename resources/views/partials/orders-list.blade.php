@php
    $now = now();
    $ordersCollection = method_exists($orders, 'getCollection') ? $orders->getCollection() : collect($orders);
    $orderStats = $orderStats ?? [
        'total' => method_exists($orders, 'total') ? $orders->total() : $ordersCollection->count(),
        'paid' => $ordersCollection->where('status', 'paid')->count(),
        'pending' => $ordersCollection->where('status', 'pending')->count(),
    ];
    $orderSummaryStats = [
        ['value' => $orderStats['total'] ?? 0, 'label' => 'Total orders'],
        ['value' => $orderStats['paid'] ?? 0, 'label' => 'Paid orders'],
        ['value' => $orderStats['pending'] ?? 0, 'label' => 'Waiting payment'],
    ];
@endphp

<div class="orders-filter-bar" data-orders-filter-bar>
    <button type="button" class="category-chip active" data-order-filter="active">Active</button>
    <button type="button" class="category-chip" data-order-filter="all">All</button>
    <button type="button" class="category-chip" data-order-filter="paid">Paid</button>
    <button type="button" class="category-chip" data-order-filter="closed">Closed</button>
    <button type="button" class="orders-previous-toggle" data-order-filter="previous">
        Previous orders
    </button>
</div>
<div class="empty-state mb-4 hidden" data-order-filter-empty>
    <span class="empty-state-title">No orders in this view</span>
    <p class="empty-state-copy">Choose another filter or open Previous orders.</p>
</div>

<div class="orders-mobile-summary lg:hidden" aria-label="Order summary">
    @foreach ($orderSummaryStats as $summaryStat)
        <div class="orders-summary-chip">
            <strong>{{ $summaryStat['value'] }}</strong>
            <span>{{ $summaryStat['label'] }}</span>
        </div>
    @endforeach
</div>

<div class="space-y-4 lg:hidden">
    @forelse ($orders as $order)
        @php
            $orderDate = $order->created_at?->timezone(config('app.timezone'));
            $isPaid = $order->status === 'paid';
            $paidDate = ($order->paid_at ?: ($isPaid ? $order->updated_at : null))?->timezone(config('app.timezone'));
            $isCrypto = $order->payment_method === 'crypto';
            $isBinancePay = $order->payment_method === 'binance_pay';
            $isPakasir = $order->payment_method === 'pakasir';
            $isGopayQris = $order->payment_method === 'gopay_qris';
            $isQris = $isPakasir || $isGopayQris;
            $cryptoPayload = is_array($order->payment_payload) ? $order->payment_payload : [];
            $isDirectCrypto = $isCrypto && ($cryptoPayload['type'] ?? null) === 'direct_crypto';
            $cryptoToken = strtoupper((string) ($cryptoPayload['token'] ?? 'USDT'));
            $isBinancePayCheckout = $isBinancePay && ($cryptoPayload['type'] ?? null) === 'binance_pay_personal';
            $hasCryptoMismatch = $isDirectCrypto && is_array($cryptoPayload['amount_mismatch'] ?? null);
            $cryptoRecoveryEndsAt = $isDirectCrypto && $order->expired_at
                ? $order->expired_at->copy()->addHours(max(1, (int) config('services.crypto_direct.recovery_hours', 24)))
                : null;
            $cryptoSelfServiceVerifyEndsAt = $isDirectCrypto && $order->expired_at
                ? $order->expired_at->copy()->addMinutes(max(0, (int) config('services.crypto_direct.self_service_verify_minutes', 60)))
                : null;
            $isCryptoInvoiceActive = $isDirectCrypto &&
                $order->status === 'pending' &&
                (! $order->expired_at || $now->lt($order->expired_at));
            $isCryptoRecoverable = $isDirectCrypto &&
                in_array($order->status, ['pending', 'cancelled'], true) &&
                $cryptoRecoveryEndsAt &&
                $now->lt($cryptoRecoveryEndsAt);
            $canSelfServiceVerifyCrypto = $isCryptoRecoverable &&
                $cryptoSelfServiceVerifyEndsAt &&
                $now->lt($cryptoSelfServiceVerifyEndsAt);
            $canSyncCrypto = $isCryptoInvoiceActive || $canSelfServiceVerifyCrypto;
            $binancePayRecoveryEndsAt = $isBinancePayCheckout && $order->expired_at
                ? $order->expired_at->copy()->addHours(max(1, (int) config('services.binance.pay.recovery_hours', 24)))
                : null;
            $binancePaySelfServiceEndsAt = $isBinancePayCheckout && $order->expired_at
                ? $order->expired_at->copy()->addMinutes(max(0, (int) config('services.binance.pay.self_service_verify_minutes', 60)))
                : null;
            $isBinancePayInvoiceActive = $isBinancePayCheckout &&
                $order->status === 'pending' &&
                (! $order->expired_at || $now->lt($order->expired_at));
            $isBinancePayRecoverable = $isBinancePayCheckout &&
                in_array($order->status, ['pending', 'cancelled'], true) &&
                $binancePayRecoveryEndsAt &&
                $now->lt($binancePayRecoveryEndsAt);
            $canSyncBinancePay = $isBinancePayInvoiceActive || (
                $isBinancePayRecoverable &&
                $binancePaySelfServiceEndsAt &&
                $now->lt($binancePaySelfServiceEndsAt)
            );
            $wasExpiredBySystem = $order->status === 'cancelled' &&
                $order->expired_at &&
                $order->updated_at &&
                $order->updated_at->gte($order->expired_at);
            $isExpired = $order->status === 'expired' ||
                ($order->status === 'pending' && $order->expired_at && $now->gte($order->expired_at)) ||
                $wasExpiredBySystem;
            $isPending = $order->status === 'pending' && ! $isExpired;
            $methodLabel = $isBinancePay ? 'Binance Pay' : ($isCrypto ? ($isDirectCrypto ? $cryptoToken . ' Address' : 'Crypto') : 'QRIS');
            $methodClass = $isQris ? 'method-pill-qris' : '';
            $cryptoAmount = (string) ($cryptoPayload['amount'] ?? $order->price);
            $priceLabel = ($isCrypto || $isBinancePay)
                ? rtrim(rtrim(number_format((float) $cryptoAmount, 6, '.', ''), '0'), '.') . ' ' . $cryptoToken
                : 'Rp ' . number_format($order->price);
            $canContinueCrypto = $isPending && $isCrypto && ! $isDirectCrypto && $order->payment_url && $order->expired_at && $now->lt($order->expired_at);
            $canOpenCryptoAddress = $isCryptoInvoiceActive && filled($cryptoPayload['address'] ?? null);
            $canSyncQris = $isPending && $isGopayQris && (bool) $order->order_id;
            $qrisPayload = is_array($order->payment_payload) ? $order->payment_payload : [];
            $publicQrisPayload = [
                'qr_payload' => (string) ($qrisPayload['qr_payload'] ?? $qrisPayload['payment_number'] ?? ''),
                'payment_number' => (string) ($qrisPayload['payment_number'] ?? $qrisPayload['qr_payload'] ?? ''),
                'base_amount' => (int) ($qrisPayload['base_amount'] ?? $qrisPayload['amount'] ?? $order->price),
                'platform_fee' => (int) ($qrisPayload['platform_fee'] ?? $qrisPayload['fee'] ?? 0),
                'unique_amount' => (int) ($qrisPayload['unique_amount'] ?? 0),
                'amount' => (int) ($qrisPayload['total_payment'] ?? $qrisPayload['amount'] ?? $order->price),
                'total_payment' => (int) ($qrisPayload['total_payment'] ?? $qrisPayload['amount'] ?? $order->price),
                'expired_at' => $order->expired_at?->toIso8601String() ?: (string) ($qrisPayload['expires_at'] ?? $qrisPayload['expired_at'] ?? ''),
                'remaining_seconds' => $order->expired_at ? max(0, (int) now()->diffInSeconds($order->expired_at, false)) : 0,
            ];
            $qrisCheckout = [
                'method' => 'gopay_qris',
                'order_id' => $order->order_id,
                'status_url' => url('/sync-gopay-qris-order/'.$order->order_id),
                'qris_payment' => $publicQrisPayload,
            ];
            $cryptoCheckout = [
                'method' => 'crypto',
                'order_id' => $order->order_id,
                'payment_url' => $order->payment_url,
                'crypto_payment' => [
                    'token' => (string) ($cryptoPayload['token'] ?? 'USDT'),
                    'network' => (string) ($cryptoPayload['network'] ?? ''),
                    'network_label' => (string) ($cryptoPayload['network_label'] ?? 'USDT'),
                    'network_short_label' => (string) ($cryptoPayload['network_short_label'] ?? ''),
                    'address' => (string) ($cryptoPayload['address'] ?? ''),
                    'contract' => (string) ($cryptoPayload['contract'] ?? ''),
                    'amount' => (string) ($cryptoPayload['amount'] ?? $order->price),
                    'base_amount' => (string) ($cryptoPayload['base_amount'] ?? ''),
                    'unique_amount' => (string) ($cryptoPayload['unique_amount'] ?? ''),
                    'expired_at' => $order->expired_at?->toIso8601String() ?: (string) ($cryptoPayload['expires_at'] ?? ''),
                    'remaining_seconds' => $order->expired_at ? max(0, (int) now()->diffInSeconds($order->expired_at, false)) : 0,
                ],
            ];
            $binancePayCheckout = [
                'method' => 'binance_pay',
                'order_id' => $order->order_id,
                'binance_pay_payment' => [
                    'token' => (string) ($cryptoPayload['token'] ?? 'USDT'),
                    'pay_id' => (string) ($cryptoPayload['pay_id'] ?? ''),
                    'qr_content' => (string) ($cryptoPayload['qr_content'] ?? ''),
                    'amount' => (string) ($cryptoPayload['amount'] ?? $order->price),
                    'base_amount' => (string) ($cryptoPayload['base_amount'] ?? ''),
                    'unique_amount' => (string) ($cryptoPayload['unique_amount'] ?? ''),
                    'expired_at' => $order->expired_at?->toIso8601String() ?: (string) ($cryptoPayload['expires_at'] ?? ''),
                    'remaining_seconds' => $order->expired_at ? max(0, (int) now()->diffInSeconds($order->expired_at, false)) : 0,
                ],
            ];
            $canOpenBinancePay = $isBinancePayInvoiceActive && filled($cryptoPayload['pay_id'] ?? null);
            $canOpenQris = $isPending && $isGopayQris && filled($publicQrisPayload['payment_number']);
            $canCancel = $order->status === 'pending';
            $hasPaymentAction = $canOpenCryptoAddress || $canSyncCrypto || $canContinueCrypto ||
                $canOpenBinancePay || $canSyncBinancePay ||
                $canSyncQris || $canCancel;
            $canVerifySentPayment = ($canSyncCrypto && ! $isCryptoInvoiceActive) ||
                ($canSyncBinancePay && ! $isBinancePayInvoiceActive);
            $paymentHint = $isPaid
                ? 'Payment confirmed. Open Licenses to view delivered keys.'
                : ($hasCryptoMismatch
                    ? 'Payment amount needs support review. Keep this Order ID ready.'
                    : ($canVerifySentPayment
                        ? 'Invoice closed. If payment was already sent, use Verify Sent Payment before verification access ends.'
                        : ($isPending
                            ? 'Keep the invoice open and use Check Payment after sending payment.'
                            : ($isExpired
                                ? 'This invoice is closed. Start a new checkout when you are ready.'
                                : 'This checkout was cancelled. No payment action is needed.'))));
            $paymentHintIcon = $isPaid
                ? 'key-round'
                : ($hasCryptoMismatch ? 'life-buoy' : (($isPending || $canVerifySentPayment) ? 'refresh-cw' : 'receipt'));
            $licenseTargetUrl = filled($order->order_id)
                ? '/licenses?order=' . rawurlencode((string) $order->order_id) . '#license-' . rawurlencode((string) $order->order_id)
                : '/licenses';
        @endphp

        <article class="order-mobile-card motion-card" data-order-entry
            data-order-status="{{ $isPending ? 'pending' : ($isPaid ? 'paid' : 'closed') }}">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-[10px] uppercase tracking-normal text-gray-500">Order ID</div>
                    <div class="mt-1 flex min-w-0 items-center gap-2">
                        <span class="truncate font-mono text-xs text-gray-300">{{ $order->order_id }}</span>
                        <button type="button" class="copy-order-button" data-copy-order-id="{{ $order->order_id }}" aria-label="Copy order ID {{ $order->order_id }}">
                            <x-ui.icon name="copy" class="h-3.5 w-3.5" />
                        </button>
                    </div>
                </div>
                @if ($hasCryptoMismatch || ($isPending && ! $canSyncCrypto && ! $canSyncBinancePay && $order->expired_at))
                <div class="text-right">
                    @if ($hasCryptoMismatch)
                        <div class="text-xs text-red-300">Contact support</div>
                    @endif
                    @if ($isPending && ! $canSyncCrypto && ! $canSyncBinancePay && $order->expired_at)
                        <div class="mt-1 text-xs text-gray-400">
                            <span class="countdown animate-pulse text-yellow-400" data-remaining="{{ max(0, (int) now()->diffInSeconds($order->expired_at, false)) }}"></span>
                        </div>
                    @endif
                </div>
                @endif
            </div>

            <div class="mt-4">
                @include('partials.order-items-summary', ['order' => $order])
            </div>

            <div class="mt-4 grid gap-3 rounded-xl border border-[#27272A] bg-black/20 p-4 text-sm">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-xs text-gray-500">Method</span>
                    <span class="method-pill {{ $methodClass }}">{{ $methodLabel }}</span>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-xs text-gray-500">Price</span>
                    <span class="font-semibold text-aksa-accent-soft">{{ $priceLabel }}</span>
                </div>
                <div class="flex items-start justify-between gap-3">
                    <span class="text-xs text-gray-500">Created at</span>
                    <span class="text-right text-xs text-gray-300">
                        {{ $orderDate?->format('d M Y') ?? '-' }}
                        <span class="block text-gray-500">{{ $orderDate ? $orderDate->format('H:i:s') . ' WIB' : '-' }}</span>
                    </span>
                </div>
                @if ($isPaid)
                    <div class="flex items-start justify-between gap-3">
                        <span class="text-xs text-gray-500">Paid at</span>
                        <span class="text-right text-xs text-gray-300">
                            {{ $paidDate?->format('d M Y') ?? '-' }}
                            <span class="block text-gray-500">{{ $paidDate ? $paidDate->format('H:i:s') . ' WIB' : '-' }}</span>
                        </span>
                    </div>
                @endif
            </div>

            @include('partials.order-status-timeline', ['order' => $order])

            <div class="order-reassurance">
                <x-ui.icon :name="$paymentHintIcon" class="h-4 w-4" />
                <span>{{ $paymentHint }}</span>
            </div>

            @if ($hasPaymentAction || $isPaid || (! $isPending && ! $isPaid))
                <div class="mt-4 flex flex-col gap-2">
                    @if ($isPaid && ! $hasPaymentAction)
                        <a href="{{ $licenseTargetUrl }}" class="order-action w-full">
                            <x-ui.icon name="key-round" class="h-4 w-4" />
                            <span>Open Licenses</span>
                        </a>
                    @elseif ($canOpenCryptoAddress)
                        <button type="button" class="order-action open-crypto-address-button w-full" data-crypto-checkout='@json($cryptoCheckout)'>
                            <x-ui.icon name="wallet" class="h-4 w-4" />
                            <span>View Address</span>
                        </button>
                    @endif

                    @if ($canOpenBinancePay)
                        <button type="button" class="order-action open-binance-pay-button w-full" data-binance-pay-checkout='@json($binancePayCheckout)'>
                            <x-ui.icon name="binance" class="h-4 w-4 text-[#F0B90B]" />
                            <span>View Binance Pay</span>
                        </button>
                    @endif

                    @if ($canSyncCrypto)
                        <form action="/sync-crypto-order/{{ $order->order_id }}" method="POST" class="sync-crypto-form">
                            @csrf
                            <button type="submit" class="order-action sync-crypto-button w-full" data-order-id="{{ $order->order_id }}">
                                <x-ui.icon name="refresh-cw" class="h-4 w-4" />
                                <span data-button-label>{{ $isCryptoInvoiceActive ? 'Verify Payment' : 'Verify Sent Payment' }}</span>
                            </button>
                        </form>
                    @elseif ($canSyncBinancePay)
                        <form action="/sync-binance-pay-order/{{ $order->order_id }}" method="POST" class="sync-binance-pay-form">
                            @csrf
                            <button type="submit" class="order-action sync-binance-pay-button w-full" data-order-id="{{ $order->order_id }}">
                                <x-ui.icon name="refresh-cw" class="h-4 w-4" />
                                <span data-button-label>{{ $isBinancePayInvoiceActive ? 'Check Payment' : 'Verify Sent Payment' }}</span>
                            </button>
                        </form>
                    @elseif ($canContinueCrypto)
                        <a href="{{ $order->payment_url }}" target="_blank" rel="noopener" class="order-action w-full">
                            <x-ui.icon name="external-link" class="h-4 w-4" />
                            <span>Continue Payment</span>
                        </a>
                    @elseif ($canSyncQris)
                        @if ($canOpenQris)
                            <button type="button" class="order-action open-gopay-qris-button w-full" data-qris-checkout='@json($qrisCheckout)'>
                                <x-ui.icon name="qr-code" class="h-4 w-4" />
                                <span>View QRIS</span>
                            </button>
                        @endif
                        <form action="/sync-gopay-qris-order/{{ $order->order_id }}" method="POST" class="sync-qris-form">
                            @csrf
                            <button type="submit" class="order-action sync-qris-button w-full" data-order-id="{{ $order->order_id }}">
                                <x-ui.icon name="refresh-cw" class="h-4 w-4" />
                                <span data-button-label>Check Payment</span>
                            </button>
                        </form>
                    @endif

                    @if ($canCancel)
                        <form action="/cancel-order/{{ $order->id }}" method="POST" class="cancel-order-form">
                            @csrf
                            <button type="submit" class="order-action order-action-danger cancel-order-button w-full">
                                <x-ui.icon name="x" class="h-4 w-4" />
                                <span data-button-label>Cancel Order</span>
                            </button>
                        </form>
                    @endif

                    @if (! $isPending && ! $isPaid && ! $canVerifySentPayment)
                        <form action="/pay-again/{{ $order->id }}" method="POST" class="pay-again-form">
                            @csrf
                            <button type="submit" class="order-action w-full">
                                <x-ui.icon name="refresh-cw" class="h-4 w-4" />
                                <span data-button-label>Buy Again</span>
                            </button>
                        </form>
                    @endif
                </div>
            @endif
        </article>
    @empty
        <div class="empty-state">
            <span class="empty-state-icon">
                <x-ui.icon name="receipt" class="h-6 w-6" />
            </span>
            <span class="empty-state-title">No orders yet</span>
            <p class="empty-state-copy">Your invoices and payment progress will appear here after checkout.</p>
        </div>
    @endforelse
</div>

<div class="orders-table-wrap hidden lg:block">
    <div class="orders-table-header">
        <div class="orders-table-heading">
            <h2>Recent Orders</h2>
            <p>Latest invoices and payment progress.</p>
        </div>
        <div class="orders-summary-chips" aria-label="Order summary">
            @foreach ($orderSummaryStats as $summaryStat)
                <div class="orders-summary-chip">
                    <strong>{{ $summaryStat['value'] }}</strong>
                    <span>{{ $summaryStat['label'] }}</span>
                </div>
            @endforeach
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full min-w-[920px] text-sm">
            <thead class="bg-[#111115] text-xs uppercase tracking-normal text-gray-500">
                <tr>
                    <th class="p-4 text-left">Order</th>
                    <th class="p-4 text-left">Product</th>
                    <th class="p-4 text-left">Method</th>
                    <th class="p-4 text-left">Price</th>
                    <th class="p-4 text-left">Created at</th>
                    <th class="p-4 text-left">Progress</th>
                    <th class="p-4 text-right">Payment</th>
                </tr>
            </thead>

            <tbody id="ordersTable">
                @forelse ($orders as $order)
                    @php
                        $orderDate = $order->created_at?->timezone(config('app.timezone'));
                        $isPaid = $order->status === 'paid';
                        $paidDate = ($order->paid_at ?: ($isPaid ? $order->updated_at : null))?->timezone(config('app.timezone'));
                        $isCrypto = $order->payment_method === 'crypto';
                        $isBinancePay = $order->payment_method === 'binance_pay';
                        $isPakasir = $order->payment_method === 'pakasir';
                        $isGopayQris = $order->payment_method === 'gopay_qris';
                        $isQris = $isPakasir || $isGopayQris;
                        $cryptoPayload = is_array($order->payment_payload) ? $order->payment_payload : [];
                        $isDirectCrypto = $isCrypto && ($cryptoPayload['type'] ?? null) === 'direct_crypto';
                        $cryptoToken = strtoupper((string) ($cryptoPayload['token'] ?? 'USDT'));
                        $isBinancePayCheckout = $isBinancePay && ($cryptoPayload['type'] ?? null) === 'binance_pay_personal';
                        $hasCryptoMismatch = $isDirectCrypto && is_array($cryptoPayload['amount_mismatch'] ?? null);
                        $cryptoRecoveryEndsAt = $isDirectCrypto && $order->expired_at
                            ? $order->expired_at->copy()->addHours(max(1, (int) config('services.crypto_direct.recovery_hours', 24)))
                            : null;
                        $cryptoSelfServiceVerifyEndsAt = $isDirectCrypto && $order->expired_at
                            ? $order->expired_at->copy()->addMinutes(max(0, (int) config('services.crypto_direct.self_service_verify_minutes', 60)))
                            : null;
                        $isCryptoInvoiceActive = $isDirectCrypto &&
                            $order->status === 'pending' &&
                            (! $order->expired_at || $now->lt($order->expired_at));
                        $isCryptoRecoverable = $isDirectCrypto &&
                            in_array($order->status, ['pending', 'cancelled'], true) &&
                            $cryptoRecoveryEndsAt &&
                            $now->lt($cryptoRecoveryEndsAt);
                        $canSelfServiceVerifyCrypto = $isCryptoRecoverable &&
                            $cryptoSelfServiceVerifyEndsAt &&
                            $now->lt($cryptoSelfServiceVerifyEndsAt);
                        $canSyncCrypto = $isCryptoInvoiceActive || $canSelfServiceVerifyCrypto;
                        $binancePayRecoveryEndsAt = $isBinancePayCheckout && $order->expired_at
                            ? $order->expired_at->copy()->addHours(max(1, (int) config('services.binance.pay.recovery_hours', 24)))
                            : null;
                        $binancePaySelfServiceEndsAt = $isBinancePayCheckout && $order->expired_at
                            ? $order->expired_at->copy()->addMinutes(max(0, (int) config('services.binance.pay.self_service_verify_minutes', 60)))
                            : null;
                        $isBinancePayInvoiceActive = $isBinancePayCheckout &&
                            $order->status === 'pending' &&
                            (! $order->expired_at || $now->lt($order->expired_at));
                        $isBinancePayRecoverable = $isBinancePayCheckout &&
                            in_array($order->status, ['pending', 'cancelled'], true) &&
                            $binancePayRecoveryEndsAt &&
                            $now->lt($binancePayRecoveryEndsAt);
                        $canSyncBinancePay = $isBinancePayInvoiceActive || (
                            $isBinancePayRecoverable &&
                            $binancePaySelfServiceEndsAt &&
                            $now->lt($binancePaySelfServiceEndsAt)
                        );
                        $wasExpiredBySystem = $order->status === 'cancelled' &&
                            $order->expired_at &&
                            $order->updated_at &&
                            $order->updated_at->gte($order->expired_at);
                        $isExpired = $order->status === 'expired' ||
                            ($order->status === 'pending' && $order->expired_at && $now->gte($order->expired_at)) ||
                            $wasExpiredBySystem;
                        $isPending = $order->status === 'pending' && ! $isExpired;
                        $methodLabel = $isBinancePay ? 'Binance Pay' : ($isCrypto ? ($isDirectCrypto ? $cryptoToken . ' Address' : 'Crypto') : 'QRIS');
                        $methodClass = $isQris ? 'method-pill-qris' : '';
                        $cryptoAmount = (string) ($cryptoPayload['amount'] ?? $order->price);
                        $priceLabel = ($isCrypto || $isBinancePay)
                            ? rtrim(rtrim(number_format((float) $cryptoAmount, 6, '.', ''), '0'), '.') . ' ' . $cryptoToken
                            : 'Rp ' . number_format($order->price);
                        $canContinueCrypto = $isPending && $isCrypto && ! $isDirectCrypto && $order->payment_url && $order->expired_at && $now->lt($order->expired_at);
                        $canOpenCryptoAddress = $isCryptoInvoiceActive && filled($cryptoPayload['address'] ?? null);
                        $canSyncQris = $isPending && $isGopayQris && (bool) $order->order_id;
                        $qrisPayload = is_array($order->payment_payload) ? $order->payment_payload : [];
                        $publicQrisPayload = [
                            'qr_payload' => (string) ($qrisPayload['qr_payload'] ?? $qrisPayload['payment_number'] ?? ''),
                            'payment_number' => (string) ($qrisPayload['payment_number'] ?? $qrisPayload['qr_payload'] ?? ''),
                            'base_amount' => (int) ($qrisPayload['base_amount'] ?? $qrisPayload['amount'] ?? $order->price),
                            'platform_fee' => (int) ($qrisPayload['platform_fee'] ?? $qrisPayload['fee'] ?? 0),
                            'unique_amount' => (int) ($qrisPayload['unique_amount'] ?? 0),
                            'amount' => (int) ($qrisPayload['total_payment'] ?? $qrisPayload['amount'] ?? $order->price),
                            'total_payment' => (int) ($qrisPayload['total_payment'] ?? $qrisPayload['amount'] ?? $order->price),
                            'expired_at' => $order->expired_at?->toIso8601String() ?: (string) ($qrisPayload['expires_at'] ?? $qrisPayload['expired_at'] ?? ''),
                            'remaining_seconds' => $order->expired_at ? max(0, (int) now()->diffInSeconds($order->expired_at, false)) : 0,
                        ];
                        $qrisCheckout = [
                            'method' => 'gopay_qris',
                            'order_id' => $order->order_id,
                            'status_url' => url('/sync-gopay-qris-order/'.$order->order_id),
                            'qris_payment' => $publicQrisPayload,
                        ];
                        $cryptoCheckout = [
                            'method' => 'crypto',
                            'order_id' => $order->order_id,
                            'payment_url' => $order->payment_url,
                            'crypto_payment' => [
                                'token' => (string) ($cryptoPayload['token'] ?? 'USDT'),
                                'network' => (string) ($cryptoPayload['network'] ?? ''),
                                'network_label' => (string) ($cryptoPayload['network_label'] ?? 'USDT'),
                                'network_short_label' => (string) ($cryptoPayload['network_short_label'] ?? ''),
                                'address' => (string) ($cryptoPayload['address'] ?? ''),
                                'contract' => (string) ($cryptoPayload['contract'] ?? ''),
                                'amount' => (string) ($cryptoPayload['amount'] ?? $order->price),
                                'base_amount' => (string) ($cryptoPayload['base_amount'] ?? ''),
                                'unique_amount' => (string) ($cryptoPayload['unique_amount'] ?? ''),
                                'expired_at' => $order->expired_at?->toIso8601String() ?: (string) ($cryptoPayload['expires_at'] ?? ''),
                                'remaining_seconds' => $order->expired_at ? max(0, (int) now()->diffInSeconds($order->expired_at, false)) : 0,
                            ],
                        ];
                        $binancePayCheckout = [
                            'method' => 'binance_pay',
                            'order_id' => $order->order_id,
                            'binance_pay_payment' => [
                                'token' => (string) ($cryptoPayload['token'] ?? 'USDT'),
                                'pay_id' => (string) ($cryptoPayload['pay_id'] ?? ''),
                                'qr_content' => (string) ($cryptoPayload['qr_content'] ?? ''),
                                'amount' => (string) ($cryptoPayload['amount'] ?? $order->price),
                                'base_amount' => (string) ($cryptoPayload['base_amount'] ?? ''),
                                'unique_amount' => (string) ($cryptoPayload['unique_amount'] ?? ''),
                                'expired_at' => $order->expired_at?->toIso8601String() ?: (string) ($cryptoPayload['expires_at'] ?? ''),
                                'remaining_seconds' => $order->expired_at ? max(0, (int) now()->diffInSeconds($order->expired_at, false)) : 0,
                            ],
                        ];
                        $canOpenBinancePay = $isBinancePayInvoiceActive && filled($cryptoPayload['pay_id'] ?? null);
                        $canOpenQris = $isPending && $isGopayQris && filled($publicQrisPayload['payment_number']);
                        $canCancel = $order->status === 'pending';
                        $hasPaymentAction = $canOpenCryptoAddress || $canSyncCrypto || $canContinueCrypto ||
                            $canOpenBinancePay || $canSyncBinancePay ||
                            $canSyncQris || $canCancel;
                        $canVerifySentPayment = ($canSyncCrypto && ! $isCryptoInvoiceActive) ||
                            ($canSyncBinancePay && ! $isBinancePayInvoiceActive);
                        $paymentHint = $isPaid
                            ? 'Payment confirmed. Open Licenses to view delivered keys.'
                            : ($hasCryptoMismatch
                                ? 'Payment amount needs support review. Keep this Order ID ready.'
                                : ($canVerifySentPayment
                                    ? 'Invoice closed. If payment was already sent, use Verify Sent Payment before verification access ends.'
                                    : ($isPending
                                        ? 'Keep the invoice open and use Check Payment after sending payment.'
                                        : ($isExpired
                                            ? 'This invoice is closed. Start a new checkout when you are ready.'
                                            : 'This checkout was cancelled. No payment action is needed.'))));
                        $paymentHintIcon = $isPaid
                            ? 'key-round'
                            : ($hasCryptoMismatch ? 'life-buoy' : (($isPending || $canVerifySentPayment) ? 'refresh-cw' : 'receipt'));
                        $licenseTargetUrl = filled($order->order_id)
                            ? '/licenses?order=' . rawurlencode((string) $order->order_id) . '#license-' . rawurlencode((string) $order->order_id)
                            : '/licenses';
                    @endphp

                    <tr class="orders-table-row" data-order-entry
                        data-order-status="{{ $isPending ? 'pending' : ($isPaid ? 'paid' : 'closed') }}">
                        <td class="p-4">
                            <div class="flex max-w-[210px] items-center gap-2">
                                <span class="truncate font-mono text-xs text-gray-300">{{ $order->order_id }}</span>
                                <button type="button" class="copy-order-button" data-copy-order-id="{{ $order->order_id }}" aria-label="Copy order ID {{ $order->order_id }}">
                                    <x-ui.icon name="copy" class="h-3.5 w-3.5" />
                                </button>
                            </div>
                            <div class="mt-1 text-[10px] uppercase tracking-normal text-gray-500">Invoice</div>
                        </td>
                        <td class="p-4">
                            @include('partials.order-items-summary', ['order' => $order, 'compact' => true])
                        </td>
                        <td class="p-4">
                            <span class="method-pill {{ $methodClass }}">{{ $methodLabel }}</span>
                        </td>
                        <td class="p-4 font-semibold text-aksa-accent-soft">{{ $priceLabel }}</td>
                        <td class="p-4 whitespace-nowrap text-xs text-gray-300">
                            <div>{{ $orderDate?->format('d M Y') ?? '-' }}</div>
                            <div class="mt-1 text-gray-500">{{ $orderDate ? $orderDate->format('H:i:s') . ' WIB' : '-' }}</div>
                            @if ($isPaid)
                                <div class="mt-2 text-[10px] uppercase tracking-normal text-aksa-accent">Paid at</div>
                                <div class="mt-1">{{ $paidDate?->format('d M Y') ?? '-' }}</div>
                                <div class="mt-1 text-gray-500">{{ $paidDate ? $paidDate->format('H:i:s') . ' WIB' : '-' }}</div>
                            @endif
                        </td>
                        <td class="p-4">
                            @if ($hasCryptoMismatch)
                                <div class="text-xs text-red-300">Contact support</div>
                            @endif
                            @if ($isPending && ! $canSyncCrypto && ! $canSyncBinancePay && $order->expired_at)
                                <div class="mt-1 text-xs text-gray-400">
                                    <span class="countdown animate-pulse text-yellow-400" data-remaining="{{ max(0, (int) now()->diffInSeconds($order->expired_at, false)) }}"></span>
                                </div>
                            @endif
                            @include('partials.order-status-timeline', ['order' => $order])
                            <div class="order-reassurance order-reassurance-compact">
                                <x-ui.icon :name="$paymentHintIcon" class="h-3.5 w-3.5" />
                                <span>{{ $paymentHint }}</span>
                            </div>
                        </td>
                        <td class="p-4 text-right">
                            <div class="inline-flex flex-wrap justify-end gap-2">
                                @if (! $hasPaymentAction)
                                    @if ($isPaid)
                                        <a href="{{ $licenseTargetUrl }}" class="order-action">
                                            <x-ui.icon name="key-round" class="h-4 w-4" />
                                            <span>Licenses</span>
                                        </a>
                                    @else
                                        <span class="text-xs text-gray-600">-</span>
                                    @endif
                                @elseif ($canSyncCrypto)
                                    @if ($canOpenCryptoAddress)
                                        <button type="button" class="order-action open-crypto-address-button" data-crypto-checkout='@json($cryptoCheckout)'>
                                            <x-ui.icon name="wallet" class="h-4 w-4" />
                                            <span>Address</span>
                                        </button>
                                    @endif
                                    <form action="/sync-crypto-order/{{ $order->order_id }}" method="POST" class="sync-crypto-form inline">
                                        @csrf
                                        <button type="submit" class="order-action sync-crypto-button" data-order-id="{{ $order->order_id }}">
                                            <x-ui.icon name="refresh-cw" class="h-4 w-4" />
                                            <span data-button-label>{{ $isCryptoInvoiceActive ? 'Verify' : 'Verify Sent' }}</span>
                                        </button>
                                    </form>
                                @elseif ($canSyncBinancePay)
                                    @if ($canOpenBinancePay)
                                        <button type="button" class="order-action open-binance-pay-button" data-binance-pay-checkout='@json($binancePayCheckout)'>
                                            <x-ui.icon name="binance" class="h-4 w-4 text-[#F0B90B]" />
                                            <span>Binance Pay</span>
                                        </button>
                                    @endif
                                    <form action="/sync-binance-pay-order/{{ $order->order_id }}" method="POST" class="sync-binance-pay-form inline">
                                        @csrf
                                        <button type="submit" class="order-action sync-binance-pay-button" data-order-id="{{ $order->order_id }}">
                                            <x-ui.icon name="refresh-cw" class="h-4 w-4" />
                                            <span data-button-label>{{ $isBinancePayInvoiceActive ? 'Check' : 'Verify Sent' }}</span>
                                        </button>
                                    </form>
                                @elseif ($canContinueCrypto)
                                    <a href="{{ $order->payment_url }}" target="_blank" rel="noopener" class="order-action">
                                        <x-ui.icon name="external-link" class="h-4 w-4" />
                                        <span>Continue</span>
                                    </a>
                                @elseif ($canSyncQris)
                                    @if ($canOpenQris)
                                        <button type="button" class="order-action open-gopay-qris-button" data-qris-checkout='@json($qrisCheckout)'>
                                            <x-ui.icon name="qr-code" class="h-4 w-4" />
                                            <span>QRIS</span>
                                        </button>
                                    @endif
                                    <form action="/sync-gopay-qris-order/{{ $order->order_id }}" method="POST" class="sync-qris-form inline">
                                        @csrf
                                        <button type="submit" class="order-action sync-qris-button" data-order-id="{{ $order->order_id }}">
                                            <x-ui.icon name="refresh-cw" class="h-4 w-4" />
                                            <span data-button-label>Check</span>
                                        </button>
                                    </form>
                                @endif

                                @if ($canCancel)
                                    <form action="/cancel-order/{{ $order->id }}" method="POST" class="cancel-order-form inline">
                                        @csrf
                                        <button type="submit" class="order-action order-action-danger cancel-order-button">
                                            <x-ui.icon name="x" class="h-4 w-4" />
                                            <span data-button-label>Cancel</span>
                                        </button>
                                    </form>
                                @endif

                                @if (! $isPending && ! $isPaid && ! $canVerifySentPayment)
                                    <form action="/pay-again/{{ $order->id }}" method="POST" class="pay-again-form inline">
                                        @csrf
                                        <button type="submit" class="order-action">
                                            <x-ui.icon name="refresh-cw" class="h-4 w-4" />
                                            <span data-button-label>Buy Again</span>
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="p-8">
                            <div class="empty-state">
                                <span class="empty-state-icon">
                                    <x-ui.icon name="receipt" class="h-6 w-6" />
                                </span>
                                <span class="empty-state-title">No orders yet</span>
                                <p class="empty-state-copy">Your invoices and payment progress will appear here after checkout.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@include('partials.pagination', [
    'paginator' => $orders,
    'label' => 'Order pagination',
    'itemLabel' => 'orders',
])
