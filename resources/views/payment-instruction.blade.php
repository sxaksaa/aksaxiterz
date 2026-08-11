@extends('layouts.app')

@section('content')
    @php
        $stateKey = $paymentState['key'];
        $statusClass = match ($stateKey) {
            'paid' => 'status-pill-paid',
            'pending' => 'status-pill-pending',
            'expired' => 'status-pill-expired',
            default => 'status-pill-cancelled',
        };
        $usesStablecoin = in_array($payment['method'], ['crypto', 'binance_pay'], true);
        $token = $payment['token'] ?? 'USDT';
        $stablecoinAmount = function ($amount) use ($token): string {
            $formatted = rtrim(rtrim(number_format((float) $amount, 6, '.', ''), '0'), '.');

            return ($formatted !== '' ? $formatted : '0').' '.$token;
        };
        $catalogSubtotal = $usesStablecoin
            ? '$'.number_format($orderSubtotalUsdt, 2)
            : 'Rp '.number_format($orderSubtotalIdr);
        $exactPayment = $usesStablecoin
            ? $stablecoinAmount($payment['amount'] ?? 0)
            : 'Rp '.number_format((int) ($payment['amount'] ?? 0));
    @endphp

    <div class="page-shell py-7 md:py-12" data-payment-instruction-page>
        <section class="orders-hero account-hero fade-up mb-6">
            <div class="account-hero-layout">
                <div class="account-hero-copy">
                    <p class="account-eyebrow">Secure Checkout</p>
                    <h1 class="account-title">Payment Instruction</h1>
                    <p class="account-copy">
                        Complete only this invoice using the exact payment details below.
                    </p>
                </div>

                <div class="flex flex-col items-start gap-2 md:items-end">
                    <span class="status-pill {{ $statusClass }}">{{ $paymentState['label'] }}</span>
                    <span class="font-mono text-xs text-gray-400">{{ $orderSummary['order_id'] }}</span>
                </div>
            </div>
        </section>

        <div class="mb-6 rounded-xl border px-4 py-3 text-sm
            {{ $stateKey === 'paid'
                ? 'border-emerald-400/30 bg-emerald-400/10 text-emerald-100'
                : ($stateKey === 'pending'
                    ? 'border-aksa-accent-30 bg-aksa-accent-10 text-aksa-accent-soft'
                    : 'border-amber-400/30 bg-amber-400/10 text-amber-100') }}">
            <div class="flex items-start gap-3">
                <x-ui.icon :name="$stateKey === 'paid' ? 'check-circle' : ($stateKey === 'pending' ? 'shield-check' : 'life-buoy')"
                    class="mt-0.5 h-5 w-5 shrink-0" />
                <div>
                    <p class="font-semibold">{{ $paymentState['label'] }}</p>
                    <p class="mt-1 leading-6 opacity-90">{{ $paymentState['message'] }}</p>
                </div>
            </div>
        </div>

        <div data-client-expired-notice
            class="mb-6 hidden rounded-xl border border-red-400/30 bg-red-400/10 px-4 py-3 text-sm text-red-100">
            This payment window has ended. The old QR code, address, or Pay ID has been hidden. Do not send a new payment.
        </div>

        <div class="grid gap-6 lg:grid-cols-[1.2fr_0.8fr] lg:items-start">
            <section class="product-section fade-up">
                @if ($paymentState['is_paid'])
                    <div class="flex flex-col items-center px-2 py-8 text-center">
                        <span class="inline-flex h-16 w-16 items-center justify-center rounded-full border border-emerald-400/30 bg-emerald-400/10 text-emerald-300">
                            <x-ui.icon name="check-circle" class="h-8 w-8" />
                        </span>
                        <h2 class="mt-5 text-2xl font-semibold text-white">Payment confirmed</h2>

                        @if ($orderSummary['delivery_pending'])
                            <p class="mt-3 max-w-lg text-sm leading-6 text-gray-400">
                                Your payment is secure. We are still preparing
                                {{ $orderSummary['quantity'] - $orderSummary['delivered_count'] }}
                                {{ \Illuminate\Support\Str::plural('license', $orderSummary['quantity'] - $orderSummary['delivered_count']) }}.
                                Check Orders again shortly.
                            </p>
                            <a href="{{ $paymentRoutes['orders'] }}" class="btn-main mt-6 inline-flex px-5 py-3">
                                <x-ui.icon name="receipt" class="h-4 w-4" />
                                <span>Open Orders</span>
                            </a>
                        @else
                            <p class="mt-3 max-w-lg text-sm leading-6 text-gray-400">
                                Your {{ \Illuminate\Support\Str::plural('license', $orderSummary['delivered_count']) }}
                                {{ $orderSummary['delivered_count'] === 1 ? 'is' : 'are' }} ready.
                            </p>
                            <a href="{{ $paymentRoutes['licenses'] }}" class="btn-main mt-6 inline-flex px-5 py-3">
                                <x-ui.icon name="key-round" class="h-4 w-4" />
                                <span>Open Licenses</span>
                            </a>
                        @endif
                    </div>
                @elseif ($paymentState['instruction_active'])
                    <div data-payment-credentials>
                        <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">
                                    {{ $payment['method_label'] }}
                                </p>
                                <h2 class="mt-1 text-xl font-semibold text-white">
                                    @if ($payment['method'] === 'gopay_qris')
                                        Scan and enter the exact amount
                                    @elseif ($payment['method'] === 'binance_pay')
                                        Send with Binance Pay
                                    @else
                                        Send to the selected network
                                    @endif
                                </h2>
                            </div>

                            @if ($paymentState['expires_at'])
                                <div class="text-right">
                                    <p class="text-[11px] uppercase tracking-normal text-gray-500">Time remaining</p>
                                    <p data-payment-countdown
                                        class="mt-1 font-mono text-sm font-semibold text-aksa-accent-soft">
                                        Calculating...
                                    </p>
                                    <p class="mt-1 text-[11px] text-gray-500">Status checks automatically every 15 seconds.</p>
                                </div>
                            @endif
                        </div>

                        @if ($payment['method'] === 'gopay_qris')
                            <div class="grid gap-6 md:grid-cols-[minmax(240px,320px)_1fr] md:items-start">
                                <div>
                                    <div class="qris-canvas-wrap qris-canvas-wrap--styled mx-auto">
                                        <div id="paymentInstructionQris" class="qris-styled-target"
                                            role="img" aria-label="QRIS payment code"></div>
                                    </div>
                                    <p class="mt-3 text-center text-xs leading-5 text-gray-500">
                                        Confirm the merchant name <strong class="text-gray-300">Aksa Xiterz</strong>.
                                    </p>
                                </div>

                                <div class="grid gap-3">
                                    <div class="rounded-xl border border-amber-400/30 bg-amber-400/10 p-4 text-sm leading-6 text-amber-100">
                                        Scan this QRIS, then manually enter the exact total shown. A different amount cannot be matched automatically.
                                    </div>
                                    <div class="qris-detail-row">
                                        <span>Base invoice</span>
                                        <span class="font-semibold text-gray-200">Rp {{ number_format($payment['base_amount']) }}</span>
                                    </div>
                                    <div class="qris-detail-row">
                                        <span>Platform fee</span>
                                        <span class="font-semibold text-gray-200">Rp {{ number_format($payment['platform_fee']) }}</span>
                                    </div>
                                    <div class="qris-detail-row">
                                        <span>Unique amount</span>
                                        <span class="font-semibold text-gray-200">Rp {{ number_format($payment['unique_amount']) }}</span>
                                    </div>
                                    <div class="qris-detail-row qris-total-row">
                                        <span class="min-w-0">Exact amount to enter</span>
                                        <span class="flex shrink-0 items-center gap-2">
                                            <strong class="qris-amount-value whitespace-nowrap">Rp {{ number_format($payment['amount']) }}</strong>
                                            <button type="button" class="order-action shrink-0 px-2 py-1 text-[11px]"
                                                data-copy-payment="{{ $payment['amount'] }}"
                                                data-copy-label="Amount">
                                                <x-ui.icon name="copy" class="h-3.5 w-3.5" />
                                                <span>Copy</span>
                                            </button>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        @elseif ($payment['method'] === 'binance_pay')
                            <div class="grid gap-6 md:grid-cols-[minmax(220px,280px)_1fr] md:items-start">
                                @if (filled($payment['qr_content']))
                                    <div class="qris-canvas-wrap mx-auto">
                                        <canvas id="paymentInstructionBinanceQr" width="256" height="256"
                                            aria-label="Binance Pay receive code"></canvas>
                                    </div>
                                @endif

                                <div class="grid gap-3 {{ filled($payment['qr_content']) ? '' : 'md:col-span-2' }}">
                                    <div class="rounded-xl border border-amber-400/30 bg-amber-400/10 p-4 text-sm leading-6 text-amber-100">
                                        Use Binance Pay or Send—not an on-chain withdrawal. Select {{ $token }} and send the exact amount.
                                    </div>
                                    <div class="qris-detail-row qris-total-row">
                                        <span>Exact amount</span>
                                        <span class="flex items-center gap-2">
                                            <strong class="qris-amount-value font-mono">{{ $stablecoinAmount($payment['amount']) }}</strong>
                                            <button type="button" class="order-action shrink-0 px-2 py-1 text-[11px]"
                                                data-copy-payment="{{ $payment['amount'] }}"
                                                data-copy-label="Amount">
                                                <x-ui.icon name="copy" class="h-3.5 w-3.5" />
                                                <span>Copy</span>
                                            </button>
                                        </span>
                                    </div>
                                    <div class="qris-detail-row">
                                        <span>Binance Pay ID</span>
                                        <span class="flex min-w-0 items-center gap-2">
                                            <strong class="truncate font-mono text-sm text-gray-200">{{ $payment['pay_id'] }}</strong>
                                            <button type="button" class="order-action shrink-0 px-2 py-1 text-[11px]"
                                                data-copy-payment="{{ $payment['pay_id'] }}"
                                                data-copy-label="Pay ID">
                                                <x-ui.icon name="copy" class="h-3.5 w-3.5" />
                                                <span>Copy</span>
                                            </button>
                                        </span>
                                    </div>
                                    <div class="qris-detail-row">
                                        <span>Token</span>
                                        <span class="font-semibold text-gray-200">{{ $token }}</span>
                                    </div>
                                </div>
                            </div>
                        @else
                            <div class="grid gap-3">
                                <div class="rounded-xl border border-amber-400/30 bg-amber-400/10 p-4 text-sm leading-6 text-amber-100">
                                    Send only {{ $token }} on <strong>{{ $payment['network_label'] }}</strong>.
                                    A transfer on another network cannot be verified automatically. Exchange or network fees must not reduce the received amount.
                                </div>
                                <div class="qris-detail-row">
                                    <span>Network</span>
                                    <span class="font-semibold text-gray-200">{{ $payment['network_label'] }}</span>
                                </div>
                                <div class="qris-detail-row qris-total-row">
                                    <span>Exact amount</span>
                                    <span class="flex items-center gap-2">
                                        <strong class="qris-amount-value font-mono">{{ $stablecoinAmount($payment['amount']) }}</strong>
                                        <button type="button" class="order-action shrink-0 px-2 py-1 text-[11px]"
                                            data-copy-payment="{{ $payment['amount'] }}"
                                            data-copy-label="Amount">
                                            <x-ui.icon name="copy" class="h-3.5 w-3.5" />
                                            <span>Copy</span>
                                        </button>
                                    </span>
                                </div>
                                <div class="qris-detail-row">
                                    <span>Wallet address</span>
                                    <span class="flex min-w-0 items-center gap-2">
                                        <strong class="break-all text-right font-mono text-xs text-gray-200">{{ $payment['address'] }}</strong>
                                        <button type="button" class="order-action shrink-0 px-2 py-1 text-[11px]"
                                            data-copy-payment="{{ $payment['address'] }}"
                                            data-copy-label="Address">
                                            <x-ui.icon name="copy" class="h-3.5 w-3.5" />
                                            <span>Copy</span>
                                        </button>
                                    </span>
                                </div>
                                @if (filled($payment['contract']))
                                    <div class="qris-detail-row">
                                        <span>Token contract</span>
                                        <span class="break-all text-right font-mono text-[11px] text-gray-500">
                                            {{ $payment['contract'] }}
                                        </span>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                @else
                    <div class="px-2 py-6">
                        <div class="flex items-start gap-4">
                            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-amber-400/30 bg-amber-400/10 text-amber-200">
                                <x-ui.icon name="life-buoy" class="h-5 w-5" />
                            </span>
                            <div>
                                <h2 class="text-xl font-semibold text-white">Payment instructions are closed</h2>
                                <p class="mt-2 text-sm leading-6 text-gray-400">
                                    The old QR code, address, or Pay ID is intentionally hidden. Do not use a screenshot or previously copied payment detail for a new transfer.
                                </p>
                            </div>
                        </div>

                        @if (! $paymentState['payload_supported'])
                            <div class="mt-5 rounded-xl border border-red-400/30 bg-red-400/10 p-4 text-sm leading-6 text-red-100">
                                Secure payment details are unavailable for this order. Do not send payment. Open Orders or contact support.
                            </div>
                        @elseif ($paymentState['within_recovery'])
                            <div class="mt-5 rounded-xl border border-aksa-accent-30 bg-aksa-accent-10 p-4 text-sm leading-6 text-aksa-accent-soft">
                                @if ($paymentState['automatic_recovery'])
                                    If you paid the exact QRIS amount before expiry, a matching merchant notification can still recover this order automatically until
                                    <strong>{{ $paymentState['recovery_ends_at_label'] }}</strong>.
                                @elseif ($paymentState['self_service_recovery'])
                                    If you already sent the exact payment before expiry, use “Verify Sent Payment” below before
                                    <strong>{{ $paymentState['self_service_ends_at_label'] }}</strong>.
                                @else
                                    Automatic recovery remains available until
                                    <strong>{{ $paymentState['recovery_ends_at_label'] }}</strong>,
                                    but self-service verification has ended. Contact support with the transaction receipt if payment was already sent.
                                @endif
                            </div>
                        @endif
                    </div>
                @endif

                @if (! $paymentState['is_paid'])
                    <div class="mt-6 grid gap-3 border-t border-white/10 pt-5 sm:grid-cols-2">
                        @if ($paymentState['can_sync'] && $paymentRoutes['sync'])
                            <button type="button" data-check-payment class="order-action min-h-12 w-full justify-center">
                                <x-ui.icon name="refresh-cw" class="h-4 w-4" />
                                <span data-button-label>
                                    {{ $paymentState['instruction_active'] ? 'Check Payment' : 'Verify Sent Payment' }}
                                </span>
                            </button>
                        @endif

                        @if ($paymentState['can_cancel'])
                            <form method="POST" action="{{ $paymentRoutes['cancel'] }}" data-no-soft-nav>
                                @csrf
                                <button type="submit" class="order-action min-h-12 w-full justify-center border-red-400/30 text-red-200">
                                    <x-ui.icon name="x" class="h-4 w-4" />
                                    <span>Cancel Checkout</span>
                                </button>
                            </form>
                        @endif

                        @if (! $paymentState['can_sync'] && ! $paymentState['can_cancel'])
                            <a href="{{ $paymentRoutes['orders'] }}" class="order-action min-h-12 w-full justify-center sm:col-span-2">
                                <x-ui.icon name="receipt" class="h-4 w-4" />
                                <span>Open Order Center</span>
                            </a>
                        @endif
                    </div>
                @endif
            </section>

            <aside class="product-section fade-up">
                <div class="mb-5">
                    <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">Order Review</p>
                    <h2 class="mt-1 text-xl font-semibold text-white">
                        {{ $orderSummary['item_count'] }}
                        {{ \Illuminate\Support\Str::plural('package', $orderSummary['item_count']) }}
                        · {{ $orderSummary['quantity'] }}
                        {{ \Illuminate\Support\Str::plural('license', $orderSummary['quantity']) }}
                    </h2>
                </div>

                <div class="grid gap-3">
                    @foreach ($orderItems as $item)
                        <article class="rounded-xl border border-white/10 bg-black/15 p-4">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <h3 class="truncate font-semibold text-white">{{ $item['product_name'] }}</h3>
                                    <p class="mt-1 text-sm text-gray-400">
                                        {{ $item['package_name'] }} · Qty {{ $item['quantity'] }}
                                    </p>
                                </div>
                                <span class="shrink-0 text-sm font-semibold text-gray-200">
                                    {{ $usesStablecoin
                                        ? '$'.number_format($item['line_total_usdt'], 2)
                                        : 'Rp '.number_format($item['line_total_idr']) }}
                                </span>
                            </div>
                        </article>
                    @endforeach
                </div>

                <div class="mt-5 grid gap-2 border-t border-white/10 pt-5 text-sm">
                    <div class="summary-row">
                        <span>Catalog subtotal</span>
                        <span>{{ $catalogSubtotal }}</span>
                    </div>
                    @if ($orderSummary['voucher_code'])
                        <div class="summary-row">
                            <span>Voucher</span>
                            <span class="font-mono text-aksa-accent">{{ $orderSummary['voucher_code'] }}</span>
                        </div>
                    @endif
                    <div class="summary-row">
                        <span>Payment method</span>
                        <span>{{ $orderSummary['payment_method_label'] }}</span>
                    </div>
                    <div class="summary-row qris-total-row">
                        <span>{{ $paymentState['instruction_active'] ? 'Exact payment' : 'Invoice total' }}</span>
                        <span class="font-semibold text-aksa-accent">{{ $exactPayment }}</span>
                    </div>
                </div>

                <dl class="mt-5 grid gap-3 border-t border-white/10 pt-5 text-xs">
                    <div class="flex items-start justify-between gap-4">
                        <dt class="text-gray-500">Order ID</dt>
                        <dd class="flex min-w-0 items-center gap-2 text-right font-mono text-gray-300">
                            <span class="truncate">{{ $orderSummary['order_id'] }}</span>
                            <button type="button" class="order-action shrink-0 px-2 py-1"
                                data-copy-payment="{{ $orderSummary['order_id'] }}"
                                data-copy-label="Order ID">
                                <x-ui.icon name="copy" class="h-3.5 w-3.5" />
                                <span class="sr-only">Copy order ID</span>
                            </button>
                        </dd>
                    </div>
                    <div class="flex items-start justify-between gap-4">
                        <dt class="text-gray-500">Created</dt>
                        <dd class="text-right text-gray-300">{{ $orderSummary['created_at'] ?? '-' }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-4">
                        <dt class="text-gray-500">Payment deadline</dt>
                        <dd class="text-right text-gray-300">{{ $orderSummary['expired_at'] ?? '-' }}</dd>
                    </div>
                </dl>

                <a href="{{ $paymentRoutes['orders'] }}"
                    class="btn-footer-secondary mt-5 inline-flex min-h-12 w-full items-center justify-center gap-2">
                    <x-ui.icon name="arrow-left" class="h-4 w-4" />
                    <span>Back to Orders</span>
                </a>
            </aside>
        </div>
    </div>
@endsection

@push('scripts')
    <script nonce="{{ request()->attributes->get('csp_nonce') }}">
        (() => {
            const page = document.querySelector('[data-payment-instruction-page]');
            if (!page) return;

            const context = {{ \Illuminate\Support\Js::from([
                'orderId' => $orderSummary['order_id'],
                'method' => $payment['method'],
                'syncUrl' => $paymentRoutes['sync'],
                'licensesUrl' => $paymentRoutes['licenses'],
                'expiresAt' => $paymentState['expires_at'],
                'remainingSeconds' => $paymentState['remaining_seconds'],
                'instructionActive' => $paymentState['instruction_active'],
                'qrisPayload' => $payment['qr_payload'] ?? '',
                'binanceQrContent' => $payment['qr_content'] ?? '',
            ]) }};
            const pageController = new AbortController();
            let countdownTimer = null;
            let statusPollTimer = null;
            let paymentCheckInFlight = false;
            let expiryHandled = false;

            function toast(title, message, variant = 'info') {
                window.showAppToast?.(title, message, { variant });
            }

            function setButtonLabel(button, label) {
                const target = button?.querySelector('[data-button-label]');
                if (target) target.textContent = label;
            }

            async function copyText(value) {
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(value);
                    return;
                }

                const textarea = document.createElement('textarea');
                textarea.value = value;
                textarea.setAttribute('readonly', '');
                textarea.style.position = 'fixed';
                textarea.style.left = '-9999px';
                document.body.appendChild(textarea);
                textarea.select();

                try {
                    document.execCommand('copy');
                } finally {
                    textarea.remove();
                }
            }

            function hideExpiredCredentials() {
                if (expiryHandled) return;
                expiryHandled = true;
                page.querySelector('[data-payment-credentials]')?.classList.add('hidden');
                page.querySelector('[data-client-expired-notice]')?.classList.remove('hidden');
                page.querySelectorAll('[data-copy-payment]').forEach(button => {
                    if (button.dataset.copyLabel !== 'Order ID') button.disabled = true;
                });
            }

            function startCountdown() {
                const output = page.querySelector('[data-payment-countdown]');
                if (!output || !context.expiresAt) return;

                const seconds = Math.max(0, Number(context.remainingSeconds || 0));
                const deadline = performance.now() + (seconds * 1000);
                const update = () => {
                    const difference = deadline - performance.now();

                    if (difference <= 0) {
                        output.textContent = 'Expired';
                        output.classList.add('text-red-300');
                        hideExpiredCredentials();
                        if (countdownTimer) clearInterval(countdownTimer);
                        countdownTimer = null;
                        return;
                    }

                    const totalSeconds = Math.floor(difference / 1000);
                    const hours = Math.floor(totalSeconds / 3600);
                    const minutes = Math.floor((totalSeconds % 3600) / 60);
                    const remaining = totalSeconds % 60;
                    output.textContent = hours > 0
                        ? `${hours}h ${minutes}m ${remaining}s`
                        : `${minutes}m ${remaining}s`;
                };

                update();
                countdownTimer = setInterval(update, 1000);
            }

            async function renderPaymentQr() {
                if (!context.instructionActive) return;

                if (context.method === 'gopay_qris' && context.qrisPayload) {
                    await window.renderAksaStyledQrCode?.('#paymentInstructionQris', context.qrisPayload, {
                        width: 320,
                        logoUrl: '/images/brand/aksa-xiterz-mark.png',
                        darkColor: '#171120',
                        lightColor: '#eee7ff',
                    });
                }

                if (context.method === 'binance_pay' && context.binanceQrContent) {
                    await window.renderAksaQrCode?.('#paymentInstructionBinanceQr', context.binanceQrContent, {
                        width: 280,
                    });
                }
            }

            async function fallbackSync() {
                const response = await window.aksaFetchWithCsrf(context.syncUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const data = await response.json().catch(() => ({}));

                if (!response.ok && response.status !== 202) {
                    const error = new Error(data.message || data.error || `Payment check failed (${response.status})`);
                    error.status = response.status;
                    throw error;
                }

                return data;
            }

            async function syncPayment() {
                if (context.method === 'gopay_qris' && window.syncAksaGopayQrisOrder) {
                    return window.syncAksaGopayQrisOrder(context.orderId, context.syncUrl);
                }
                if (context.method === 'crypto' && window.syncAksaCryptoOrder) {
                    return window.syncAksaCryptoOrder(context.orderId);
                }
                if (context.method === 'binance_pay' && window.syncAksaBinancePayOrder) {
                    return window.syncAksaBinancePayOrder(context.orderId);
                }

                return fallbackSync();
            }

            function handlePaymentResult(result, notifyPending = false) {
                if (result?.status === 'paid') {
                    toast('Payment verified', result.message || 'Your payment has been confirmed.', 'success');
                    window.location.reload();
                    return;
                }

                if (result?.status && result.status !== 'pending') {
                    toast('Checkout closed', result.message || 'This payment window is closed.', 'warning');
                    window.location.reload();
                    return;
                }

                if (notifyPending) {
                    toast('Still waiting', result?.message || 'No matching payment has been found yet.', 'warning');
                }
            }

            async function pollPaymentStatus() {
                if (paymentCheckInFlight || document.hidden || !context.syncUrl) return;

                paymentCheckInFlight = true;

                try {
                    handlePaymentResult(await syncPayment());
                } catch (error) {
                    // Keep the invoice usable and retry on the next interval.
                } finally {
                    paymentCheckInFlight = false;
                }
            }

            function startStatusPolling() {
                if (!context.instructionActive || !context.syncUrl || statusPollTimer) return;

                statusPollTimer = setInterval(pollPaymentStatus, 15000);
            }

            page.querySelectorAll('[data-copy-payment]').forEach(button => {
                button.addEventListener('click', async () => {
                    const value = button.dataset.copyPayment || '';
                    if (!value || button.disabled) return;

                    try {
                        await copyText(value);
                        toast(`${button.dataset.copyLabel || 'Value'} copied`, 'Paste it exactly as shown.', 'success');
                    } catch (error) {
                        toast('Copy failed', 'Please select and copy the value manually.', 'error');
                    }
                }, { signal: pageController.signal });
            });

            page.querySelector('[data-check-payment]')?.addEventListener('click', async event => {
                const button = event.currentTarget;
                const originalLabel = button.querySelector('[data-button-label]')?.textContent || 'Check Payment';

                if (paymentCheckInFlight) {
                    toast('Already checking', 'Payment verification is already in progress.', 'warning');
                    return;
                }

                paymentCheckInFlight = true;
                button.disabled = true;
                setButtonLabel(button, 'Checking...');

                try {
                    handlePaymentResult(await syncPayment(), true);
                } catch (error) {
                    toast('Payment check failed', error.message || 'Please try again in a moment.', 'error');
                } finally {
                    paymentCheckInFlight = false;
                    button.disabled = false;
                    setButtonLabel(button, originalLabel);
                }
            }, { signal: pageController.signal });

            const initialize = () => {
                void renderPaymentQr();
                startCountdown();
                startStatusPolling();
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initialize, {
                    once: true,
                    signal: pageController.signal,
                });
            } else {
                initialize();
            }

            window.addEventListener('aksa:before-page-swap', () => {
                pageController.abort();
                if (countdownTimer) clearInterval(countdownTimer);
                if (statusPollTimer) clearInterval(statusPollTimer);
            }, {
                once: true,
                signal: pageController.signal,
            });
        })();
    </script>
@endpush
