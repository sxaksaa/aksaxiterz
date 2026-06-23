@php
    $now = now();
@endphp

<div class="space-y-4 md:hidden">
    @forelse ($orders as $order)
        @php
            $orderDate = $order->created_at?->timezone(config('app.timezone'));
            $isPaid = $order->status === 'paid';
            $paidDate = ($order->paid_at ?: ($isPaid ? $order->updated_at : null))?->timezone(config('app.timezone'));
            $isCrypto = $order->payment_method === 'crypto';
            $isBinancePay = $order->payment_method === 'binance_pay';
            $isPakasir = ! $isCrypto && ! $isBinancePay;
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
            $isExpired = $order->status === 'pending' && $order->expired_at && $now->gte($order->expired_at);
            $isPending = $order->status === 'pending' && ! $isExpired;
            $isAutoVerifying = $isCryptoInvoiceActive || $isBinancePayInvoiceActive;
            $statusLabel = $isPaid ? 'Paid' : ($hasCryptoMismatch ? 'Amount mismatch' : ($isAutoVerifying ? 'Verifying' : ($isExpired ? 'Expired' : ($isPending ? 'Pending' : 'Cancelled'))));
            $statusClass = $isPaid ? 'status-pill-paid' : ($hasCryptoMismatch ? 'status-pill-warning' : ($isAutoVerifying ? 'status-pill-pending' : ($isExpired ? 'status-pill-expired' : ($isPending ? 'status-pill-pending' : 'status-pill-cancelled'))));
            $methodLabel = $isBinancePay ? 'Binance Pay' : ($isCrypto ? ($isDirectCrypto ? $cryptoToken . ' Address' : 'Crypto') : 'QRIS');
            $methodClass = $isPakasir ? 'method-pill-pakasir' : '';
            $cryptoAmount = (string) ($cryptoPayload['amount'] ?? $order->price);
            $priceLabel = ($isCrypto || $isBinancePay)
                ? rtrim(rtrim(number_format((float) $cryptoAmount, 6, '.', ''), '0'), '.') . ' ' . $cryptoToken
                : 'Rp ' . number_format($order->price);
            $canContinueCrypto = $isPending && $isCrypto && ! $isDirectCrypto && $order->payment_url && $order->expired_at && $now->lt($order->expired_at);
            $canOpenCryptoAddress = $isCryptoInvoiceActive && filled($cryptoPayload['address'] ?? null);
            $canSyncPakasir = $isPending && $isPakasir && (bool) $order->order_id;
            $canContinuePakasir = $isPending && $isPakasir && $order->payment_url && $order->expired_at && $now->lt($order->expired_at);
            $pakasirPayload = is_array($order->payment_payload) ? $order->payment_payload : [];
            $pakasirCheckout = [
                'method' => 'pakasir',
                'order_id' => $order->order_id,
                'payment_url' => $order->payment_url,
                'pakasir_payment' => [
                    'amount' => (int) ($pakasirPayload['amount'] ?? $order->price),
                    'fee' => (int) ($pakasirPayload['fee'] ?? 0),
                    'total_payment' => (int) ($pakasirPayload['total_payment'] ?? $pakasirPayload['amount'] ?? $order->price),
                    'payment_method' => (string) ($pakasirPayload['payment_method'] ?? 'qris'),
                    'payment_number' => (string) ($pakasirPayload['payment_number'] ?? ''),
                    'expired_at' => $order->expired_at?->toIso8601String() ?: (string) ($pakasirPayload['expired_at'] ?? ''),
                    'remaining_seconds' => $order->expired_at ? max(0, (int) now()->diffInSeconds($order->expired_at, false)) : 0,
                ],
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
            $canOpenPakasirQris = $canContinuePakasir && filled($pakasirCheckout['pakasir_payment']['payment_number']);
            $canCancel = $order->status === 'pending';
            $hasPaymentAction = $canOpenCryptoAddress || $canSyncCrypto || $canContinueCrypto ||
                $canOpenBinancePay || $canSyncBinancePay ||
                $canSyncPakasir || $canContinuePakasir || $canCancel;
            $paymentHint = $isPaid
                ? 'Payment confirmed. Open Licenses to view delivered keys.'
                : ($hasCryptoMismatch
                    ? 'Payment amount needs support review. Keep this Order ID ready.'
                    : ($isPending
                        ? 'Keep the invoice open and use Check Payment after sending payment.'
                        : ($isExpired
                            ? 'This invoice is closed. Start a new checkout when you are ready.'
                            : 'This checkout was cancelled. No payment action is needed.')));
            $paymentHintIcon = $isPaid
                ? 'key-round'
                : ($hasCryptoMismatch ? 'life-buoy' : ($isPending ? 'refresh-cw' : 'receipt'));
        @endphp

        <article class="order-mobile-card motion-card">
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
                <div class="text-right">
                    <span class="status-pill {{ $statusClass }}">{{ $statusLabel }}</span>
                    @if ($hasCryptoMismatch)
                        <div class="mt-1 text-xs text-red-300">Contact support</div>
                    @endif
                    @if ($isPending && ! $canSyncCrypto && ! $canSyncBinancePay && $order->expired_at)
                        <div class="mt-1 text-xs text-gray-400">
                            <span class="countdown animate-pulse text-yellow-400" data-remaining="{{ max(0, (int) now()->diffInSeconds($order->expired_at, false)) }}"></span>
                        </div>
                    @endif
                </div>
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
                    <span class="font-semibold text-[#D8B4FE]">{{ $priceLabel }}</span>
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

            @if ($hasPaymentAction || $isPaid)
                <div class="mt-4 flex flex-col gap-2">
                    @if ($isPaid && ! $hasPaymentAction)
                        <a href="/licenses" class="order-action w-full">
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
                    @elseif ($canSyncPakasir)
                        @if ($canOpenPakasirQris)
                            <button type="button" class="order-action open-pakasir-qris-button w-full" data-pakasir-checkout='@json($pakasirCheckout)'>
                                <x-ui.icon name="qr-code" class="h-4 w-4" />
                                <span>View QRIS</span>
                            </button>
                        @elseif ($canContinuePakasir)
                            <a href="{{ $order->payment_url }}" target="_blank" rel="noopener" class="order-action w-full">
                                <x-ui.icon name="external-link" class="h-4 w-4" />
                                <span>Open QRIS Page</span>
                            </a>
                        @endif
                        <form action="/sync-pakasir-order/{{ $order->order_id }}" method="POST" class="sync-pakasir-form">
                            @csrf
                            <button type="submit" class="order-action sync-pakasir-button w-full" data-order-id="{{ $order->order_id }}">
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

<div class="orders-table-wrap hidden md:block">
    <div class="flex items-center justify-between gap-3 border-b border-[#27272A] px-4 py-4">
        <div>
            <h2 class="text-sm font-semibold text-white">Recent Orders</h2>
        </div>
        <span class="rounded-lg border border-[#9333EA]/30 bg-[#9333EA]/10 px-3 py-1 text-xs font-semibold text-[#C084FC]">
            {{ method_exists($orders, 'total') ? $orders->total() : $orders->count() }} records
        </span>
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
                    <th class="p-4 text-left">Status</th>
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
                        $isPakasir = ! $isCrypto && ! $isBinancePay;
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
                        $isExpired = $order->status === 'pending' && $order->expired_at && $now->gte($order->expired_at);
                        $isPending = $order->status === 'pending' && ! $isExpired;
                        $isAutoVerifying = $isCryptoInvoiceActive || $isBinancePayInvoiceActive;
                        $statusLabel = $isPaid ? 'Paid' : ($hasCryptoMismatch ? 'Amount mismatch' : ($isAutoVerifying ? 'Verifying' : ($isExpired ? 'Expired' : ($isPending ? 'Pending' : 'Cancelled'))));
                        $statusClass = $isPaid ? 'status-pill-paid' : ($hasCryptoMismatch ? 'status-pill-warning' : ($isAutoVerifying ? 'status-pill-pending' : ($isExpired ? 'status-pill-expired' : ($isPending ? 'status-pill-pending' : 'status-pill-cancelled'))));
                        $methodLabel = $isBinancePay ? 'Binance Pay' : ($isCrypto ? ($isDirectCrypto ? $cryptoToken . ' Address' : 'Crypto') : 'QRIS');
                        $methodClass = $isPakasir ? 'method-pill-pakasir' : '';
                        $cryptoAmount = (string) ($cryptoPayload['amount'] ?? $order->price);
                        $priceLabel = ($isCrypto || $isBinancePay)
                            ? rtrim(rtrim(number_format((float) $cryptoAmount, 6, '.', ''), '0'), '.') . ' ' . $cryptoToken
                            : 'Rp ' . number_format($order->price);
                        $canContinueCrypto = $isPending && $isCrypto && ! $isDirectCrypto && $order->payment_url && $order->expired_at && $now->lt($order->expired_at);
                        $canOpenCryptoAddress = $isCryptoInvoiceActive && filled($cryptoPayload['address'] ?? null);
                        $canSyncPakasir = $isPending && $isPakasir && (bool) $order->order_id;
                        $canContinuePakasir = $isPending && $isPakasir && $order->payment_url && $order->expired_at && $now->lt($order->expired_at);
                        $pakasirPayload = is_array($order->payment_payload) ? $order->payment_payload : [];
                        $pakasirCheckout = [
                            'method' => 'pakasir',
                            'order_id' => $order->order_id,
                            'payment_url' => $order->payment_url,
                            'pakasir_payment' => [
                                'amount' => (int) ($pakasirPayload['amount'] ?? $order->price),
                                'fee' => (int) ($pakasirPayload['fee'] ?? 0),
                                'total_payment' => (int) ($pakasirPayload['total_payment'] ?? $pakasirPayload['amount'] ?? $order->price),
                                'payment_method' => (string) ($pakasirPayload['payment_method'] ?? 'qris'),
                                'payment_number' => (string) ($pakasirPayload['payment_number'] ?? ''),
                                'expired_at' => $order->expired_at?->toIso8601String() ?: (string) ($pakasirPayload['expired_at'] ?? ''),
                                'remaining_seconds' => $order->expired_at ? max(0, (int) now()->diffInSeconds($order->expired_at, false)) : 0,
                            ],
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
                        $canOpenPakasirQris = $canContinuePakasir && filled($pakasirCheckout['pakasir_payment']['payment_number']);
                        $canCancel = $order->status === 'pending';
                        $hasPaymentAction = $canOpenCryptoAddress || $canSyncCrypto || $canContinueCrypto ||
                            $canOpenBinancePay || $canSyncBinancePay ||
                            $canSyncPakasir || $canContinuePakasir || $canCancel;
                        $paymentHint = $isPaid
                            ? 'Payment confirmed. Open Licenses to view delivered keys.'
                            : ($hasCryptoMismatch
                                ? 'Payment amount needs support review. Keep this Order ID ready.'
                                : ($isPending
                                    ? 'Keep the invoice open and use Check Payment after sending payment.'
                                    : ($isExpired
                                        ? 'This invoice is closed. Start a new checkout when you are ready.'
                                        : 'This checkout was cancelled. No payment action is needed.')));
                        $paymentHintIcon = $isPaid
                            ? 'key-round'
                            : ($hasCryptoMismatch ? 'life-buoy' : ($isPending ? 'refresh-cw' : 'receipt'));
                    @endphp

                    <tr class="orders-table-row">
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
                        <td class="p-4 font-semibold text-[#D8B4FE]">{{ $priceLabel }}</td>
                        <td class="p-4 whitespace-nowrap text-xs text-gray-300">
                            <div>{{ $orderDate?->format('d M Y') ?? '-' }}</div>
                            <div class="mt-1 text-gray-500">{{ $orderDate ? $orderDate->format('H:i:s') . ' WIB' : '-' }}</div>
                            @if ($isPaid)
                                <div class="mt-2 text-[10px] uppercase tracking-normal text-[#C084FC]">Paid at</div>
                                <div class="mt-1">{{ $paidDate?->format('d M Y') ?? '-' }}</div>
                                <div class="mt-1 text-gray-500">{{ $paidDate ? $paidDate->format('H:i:s') . ' WIB' : '-' }}</div>
                            @endif
                        </td>
                        <td class="p-4">
                            <span class="status-pill {{ $statusClass }}">{{ $statusLabel }}</span>
                            @if ($hasCryptoMismatch)
                                <div class="mt-1 text-xs text-red-300">Contact support</div>
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
                                        <a href="/licenses" class="order-action">
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
                                @elseif ($canSyncPakasir)
                                    @if ($canOpenPakasirQris)
                                        <button type="button" class="order-action open-pakasir-qris-button" data-pakasir-checkout='@json($pakasirCheckout)'>
                                            <x-ui.icon name="qr-code" class="h-4 w-4" />
                                            <span>QRIS</span>
                                        </button>
                                    @elseif ($canContinuePakasir)
                                        <a href="{{ $order->payment_url }}" target="_blank" rel="noopener" class="order-action">
                                            <x-ui.icon name="external-link" class="h-4 w-4" />
                                            <span>QRIS</span>
                                        </a>
                                    @endif
                                    <form action="/sync-pakasir-order/{{ $order->order_id }}" method="POST" class="sync-pakasir-form inline">
                                        @csrf
                                        <button type="submit" class="order-action sync-pakasir-button" data-order-id="{{ $order->order_id }}">
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
