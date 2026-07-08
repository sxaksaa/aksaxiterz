@extends('layouts.app')

@section('content')
    @php
        $isPaid = $order->status === 'paid';
        $statusClass = $isPaid ? 'status-pill-paid' : ($order->status === 'pending' ? 'status-pill-pending' : 'status-pill-cancelled');
        $payload = is_array($order->payment_payload) ? $order->payment_payload : [];
        $isDirectCrypto = $order->payment_method === 'crypto' && ($payload['type'] ?? null) === 'direct_crypto';
        $isBinancePay = $order->payment_method === 'binance_pay' && ($payload['type'] ?? null) === 'binance_pay_personal';
        $cryptoToken = strtoupper((string) ($payload['token'] ?? 'USDT'));
        $methodLabel = match ($order->payment_method) {
            'binance_pay' => 'Binance Pay',
            'crypto' => $isDirectCrypto ? $cryptoToken . ' Address' : 'Crypto',
            'pakasir' => 'QRIS',
            default => ucfirst($order->payment_method ?: 'Legacy'),
        };
        $cryptoAmount = (string) ($payload['amount'] ?? $order->price);
        $amountMismatch = is_array($payload['amount_mismatch'] ?? null) ? $payload['amount_mismatch'] : null;
        $binanceDiagnostics = is_array($payload['binance_diagnostics'] ?? null) ? $payload['binance_diagnostics'] : null;
        $binanceClosestRecord = is_array($binanceDiagnostics['closest_record'] ?? null) ? $binanceDiagnostics['closest_record'] : null;
        $paidAt = ($order->paid_at ?: ($isPaid ? $order->updated_at : null))?->timezone(config('app.timezone'));
        $createdAt = $order->created_at?->timezone(config('app.timezone'));
        $expiresAt = $order->expired_at?->timezone(config('app.timezone'));
        $canMarkPaidManually = ! $isPaid;
        $quantity = max(1, (int) $order->quantity);
        $deliveredCount = $order->licenses->count();
        $isDeliveryComplete = $deliveredCount >= $quantity;
        $canResyncLicense = $isPaid && ! $isDeliveryComplete;
    @endphp

    <div class="page-shell py-6 md:py-10">
        <section class="orders-hero fade-up mb-6">
            <div>
                <div>
                    <p class="mb-2 text-sm font-semibold text-aksa-accent">Admin Order Detail</p>
                    <h1 class="break-all text-2xl font-bold tracking-normal md:text-4xl">{{ $order->order_id }}</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                        Verify customer payment state, license delivery, and provider references before helping support.
                    </p>
                </div>
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ ucfirst($order->status) }}</div>
                    <div class="mt-1 text-xs text-gray-400">Status</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $methodLabel }}</div>
                    <div class="mt-1 text-xs text-gray-400">Payment method</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $deliveredCount }} / {{ $quantity }}</div>
                    <div class="mt-1 text-xs text-gray-400">License state</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">
                        {{ $paidAt ? $paidAt->format('H:i:s') : '-' }}
                    </div>
                    <div class="mt-1 text-xs text-gray-400">Paid time</div>
                </div>
            </div>
        </section>

        @if (session('info'))
            <div class="mb-4 rounded-xl border border-aksa-accent-30 bg-aksa-accent-10 px-4 py-3 text-sm text-aksa-accent-soft">
                {{ session('info') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                {{ $errors->first() }}
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-[1.15fr_0.85fr]">
            <section class="product-section fade-up">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">Order</p>
                        <h2 class="mt-1 text-xl font-semibold text-white">Payment Record</h2>
                    </div>
                    <span class="status-pill {{ $statusClass }}">{{ ucfirst($order->status) }}</span>
                </div>

                <div class="grid gap-3 text-sm">
                    <div class="qris-detail-row">
                        <span>Customer</span>
                        <span class="text-right text-gray-200">{{ $order->user->name ?? '-' }}</span>
                    </div>
                    <div class="qris-detail-row">
                        <span>Email</span>
                        <span class="break-all text-right text-gray-200">{{ $order->user->email ?? '-' }}</span>
                    </div>
                    <div class="qris-detail-row">
                        <span>Bundle</span>
                        <span class="text-right text-gray-200">{{ $order->item_count }} package(s)</span>
                    </div>
                    <div class="qris-detail-row">
                        <span>Total quantity</span>
                        <span class="text-right text-gray-200">{{ $quantity }} {{ $quantity === 1 ? 'key' : 'keys' }}</span>
                    </div>
                    <div class="qris-detail-row">
                        <span>Amount</span>
                        <span class="font-semibold text-aksa-accent-soft">
                            {{ ($order->payment_method === 'crypto' || $isBinancePay) ? rtrim(rtrim(number_format((float) $cryptoAmount, 6, '.', ''), '0'), '.') . ' ' . $cryptoToken : 'Rp ' . number_format($order->price) }}
                        </span>
                    </div>
                    <div class="qris-detail-row">
                        <span>Created</span>
                        <span class="text-right text-gray-200">{{ $createdAt ? $createdAt->format('d M Y, H:i:s') . ' WIB' : '-' }}</span>
                    </div>
                    <div class="qris-detail-row">
                        <span>Expires</span>
                        <span class="text-right text-gray-200">{{ $expiresAt ? $expiresAt->format('d M Y, H:i:s') . ' WIB' : '-' }}</span>
                    </div>
                    <div class="qris-detail-row qris-total-row">
                        <span>Paid at</span>
                        <span class="text-right font-semibold text-aksa-accent-soft">{{ $paidAt ? $paidAt->format('d M Y, H:i:s') . ' WIB' : '-' }}</span>
                    </div>
                </div>

                <div class="mt-5">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-normal text-gray-500">Order Items</p>
                    @include('partials.order-items-summary', ['order' => $order])
                </div>

                <div class="mt-5 flex flex-wrap gap-2">
                    @if ($canMarkPaidManually)
                        <form action="{{ route('admin.orders.mark-paid', $order) }}" method="POST"
                            data-confirm="Mark this order as paid and deliver {{ $quantity }} license {{ $quantity === 1 ? 'key' : 'keys' }}?">
                            @csrf
                            <button class="order-action">
                                <x-ui.icon name="check-circle" class="h-4 w-4" />
                                <span>{{ ($isDirectCrypto || $isBinancePay) ? 'Mark Paid Manually' : 'Mark Paid' }}</span>
                            </button>
                        </form>
                    @endif

                    @if ($canResyncLicense)
                        <form action="{{ route('admin.orders.resync-license', $order) }}" method="POST">
                            @csrf
                            <button class="order-action">
                                <x-ui.icon name="refresh-cw" class="h-4 w-4" />
                                <span>Resync License</span>
                            </button>
                        </form>
                    @endif

                    @if ($order->payment_url)
                        <a href="{{ $order->payment_url }}" target="_blank" rel="noopener noreferrer" class="order-action">
                            <x-ui.icon name="external-link" class="h-4 w-4" />
                            <span>Open Payment</span>
                        </a>
                    @endif
                </div>

                @if (($isDirectCrypto || $isBinancePay) && ! $isPaid)
                    <div class="crypto-payment-warning mt-5">
                        <p class="text-[11px] font-semibold uppercase tracking-normal text-white">Manual Override</p>
                        <p class="mt-1 text-xs leading-5 text-gray-300">
                            Use Mark Paid Manually only after checking the matching Binance transaction yourself. This will deliver all license keys even if the automatic scanner has not matched the payment.
                        </p>
                    </div>
                @endif
            </section>

            <div class="grid gap-6">
                <section class="product-section fade-up">
                    <div class="mb-4">
                        <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">Delivery</p>
                        <h2 class="mt-1 text-xl font-semibold text-white">Licenses</h2>
                    </div>

                    @if ($order->licenses->isNotEmpty())
                        <div class="grid gap-3 text-sm">
                            @foreach ($order->licenses as $license)
                                <div class="qris-detail-row">
                                    <span>Key {{ $loop->iteration }}</span>
                                    <span class="break-all text-right font-mono text-xs text-gray-200">{{ $license->license_key }}</span>
                                </div>
                            @endforeach
                            <div class="qris-detail-row qris-total-row">
                                <span>Delivery progress</span>
                                <span class="text-right font-semibold text-aksa-accent-soft">{{ $deliveredCount }} / {{ $quantity }}</span>
                            </div>
                        </div>
                    @else
                        <div class="empty-state">No licenses have been attached to this order yet.</div>
                    @endif
                </section>

                <section class="product-section fade-up">
                    <div class="mb-4">
                        <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">{{ ($isDirectCrypto || $isBinancePay) ? 'Payment Scanner' : 'Provider' }}</p>
                        <h2 class="mt-1 text-xl font-semibold text-white">Payment Data</h2>
                    </div>

                    <div class="grid gap-3 text-sm">
                        @if ($isDirectCrypto)
                            <div class="qris-detail-row">
                                <span>Scanner status</span>
                                <span class="text-right font-semibold {{ ($payload['scanner_status'] ?? null) === 'amount_mismatch' ? 'text-red-300' : 'text-gray-200' }}">
                                    {{ str_replace('_', ' ', ucfirst($payload['scanner_status'] ?? 'waiting')) }}
                                </span>
                            </div>
                            <div class="qris-detail-row">
                                <span>Network</span>
                                <span class="text-right text-gray-200">{{ $payload['network_label'] ?? $payload['network'] ?? '-' }}</span>
                            </div>
                            <div class="qris-detail-row qris-total-row">
                                <span>Expected amount</span>
                                <span class="text-right font-mono text-xs font-semibold text-aksa-accent-soft">{{ $payload['amount'] ?? '-' }} {{ $cryptoToken }}</span>
                            </div>
                            <div class="qris-detail-row">
                                <span>Receive address</span>
                                <span class="max-w-[260px] truncate text-right font-mono text-xs text-gray-200">{{ $payload['address'] ?? '-' }}</span>
                            </div>
                            <div class="qris-detail-row">
                                <span>Contract</span>
                                <span class="max-w-[260px] truncate text-right font-mono text-xs text-gray-500">{{ $payload['contract'] ?? '-' }}</span>
                            </div>
                            <div class="qris-detail-row">
                                <span>Matched tx</span>
                                <span class="max-w-[260px] truncate text-right font-mono text-xs text-gray-200">{{ $payload['tx_hash'] ?? '-' }}</span>
                            </div>
                            <div class="qris-detail-row">
                                <span>Last checked</span>
                                <span class="text-right text-gray-200">{{ ! empty($payload['last_checked_at']) ? \Carbon\Carbon::parse($payload['last_checked_at'])->timezone(config('app.timezone'))->format('d M Y, H:i:s') . ' WIB' : '-' }}</span>
                            </div>
                            @if ($binanceDiagnostics)
                                <div class="crypto-payment-warning">
                                    <p class="text-[11px] font-semibold uppercase tracking-normal text-white">Binance API Diagnosis</p>
                                    <p class="mt-1 text-xs leading-5 text-gray-300">
                                        {{ str_replace('_', ' ', ucfirst($binanceDiagnostics['status'] ?? 'unknown')) }}.
                                        Records returned: {{ $binanceDiagnostics['returned_records'] ?? 0 }}.
                                        @if (! empty($binanceDiagnostics['http_status']) || ! empty($binanceDiagnostics['code']))
                                            HTTP {{ $binanceDiagnostics['http_status'] ?? '-' }}, code {{ $binanceDiagnostics['code'] ?? '-' }}.
                                        @endif
                                    </p>
                                    @if (! empty($binanceDiagnostics['message']))
                                        <p class="mt-1 text-xs leading-5 text-gray-400">{{ $binanceDiagnostics['message'] }}</p>
                                    @endif
                                    @if (! empty($binanceDiagnostics['rejections']))
                                        <p class="mt-1 break-all font-mono text-[11px] text-aksa-accent-soft">
                                            Rejected: {{ collect($binanceDiagnostics['rejections'])->map(fn ($count, $reason) => str_replace('_', ' ', $reason) . '=' . $count)->implode(', ') }}
                                        </p>
                                    @endif
                                    @if ($binanceClosestRecord)
                                        <p class="mt-1 break-all font-mono text-[11px] text-gray-400">
                                            Closest: {{ $binanceClosestRecord['amount'] ?? '-' }} {{ $binanceClosestRecord['coin'] ?? '-' }},
                                            {{ $binanceClosestRecord['network'] ?? '-' }},
                                            address ...{{ $binanceClosestRecord['address_suffix'] ?? '-' }},
                                            wallet {{ $binanceClosestRecord['wallet_type'] ?? '-' }},
                                            {{ $binanceClosestRecord['reference'] ?? '-' }}
                                        </p>
                                    @endif
                                </div>
                            @endif
                            @if ($amountMismatch)
                                <div class="crypto-payment-warning">
                                    <p class="text-[11px] font-semibold uppercase tracking-normal text-white">Amount Mismatch</p>
                                    <p class="mt-1 text-xs leading-5 text-gray-300">
                                        Expected {{ $amountMismatch['expected_amount'] ?? '-' }} {{ $cryptoToken }}, received {{ $amountMismatch['received_amount'] ?? '-' }} {{ $cryptoToken }}.
                                    </p>
                                    <p class="mt-1 break-all font-mono text-[11px] text-aksa-accent-soft">
                                        {{ $amountMismatch['tx_hash'] ?? '-' }}
                                    </p>
                                </div>
                            @endif
                        @elseif ($isBinancePay)
                            <div class="qris-detail-row">
                                <span>Scanner status</span>
                                <span class="text-right font-semibold text-gray-200">
                                    {{ str_replace('_', ' ', ucfirst($payload['scanner_status'] ?? 'waiting')) }}
                                </span>
                            </div>
                            <div class="qris-detail-row qris-total-row">
                                <span>Expected amount</span>
                                <span class="text-right font-mono text-xs font-semibold text-aksa-accent-soft">{{ $payload['amount'] ?? '-' }} {{ $cryptoToken }}</span>
                            </div>
                            <div class="qris-detail-row">
                                <span>Pay ID</span>
                                <span class="text-right font-mono text-xs text-gray-200">{{ $payload['pay_id'] ?? '-' }}</span>
                            </div>
                            <div class="qris-detail-row">
                                <span>Matched transaction</span>
                                <span class="max-w-[260px] truncate text-right font-mono text-xs text-gray-200">{{ $payload['transaction_id'] ?? '-' }}</span>
                            </div>
                            <div class="qris-detail-row">
                                <span>Payer</span>
                                <span class="text-right text-gray-200">{{ $payload['payer_name'] ?? '-' }}</span>
                            </div>
                            <div class="qris-detail-row">
                                <span>Last checked</span>
                                <span class="text-right text-gray-200">{{ ! empty($payload['last_checked_at']) ? \Carbon\Carbon::parse($payload['last_checked_at'])->timezone(config('app.timezone'))->format('d M Y, H:i:s') . ' WIB' : '-' }}</span>
                            </div>
                        @else
                            <div class="qris-detail-row">
                                <span>Payment URL</span>
                                <span class="max-w-[260px] truncate text-right text-gray-200">{{ $order->payment_url ?: '-' }}</span>
                            </div>
                            <div class="qris-detail-row">
                                <span>Provider method</span>
                                <span class="text-right text-gray-200">{{ $payload['payment_method'] ?? $order->payment_method ?? '-' }}</span>
                            </div>
                            <div class="qris-detail-row">
                                <span>Provider total</span>
                                <span class="text-right text-gray-200">
                                    {{ isset($payload['total_payment']) ? 'Rp ' . number_format((int) $payload['total_payment']) : '-' }}
                                </span>
                            </div>
                        @endif
                    </div>
                </section>
            </div>
        </div>
    </div>
@endsection
