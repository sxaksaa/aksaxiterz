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
            $isPakasir = ! $isCrypto;
            $canSyncCrypto = $isCrypto &&
                $order->payment_url &&
                $order->status === 'pending' &&
                $order->created_at &&
                $order->created_at->gt(now()->subDay());
            $isExpired = $order->status === 'pending' && ! $canSyncCrypto && $order->expired_at && $now->gt($order->expired_at);
            $isPending = $order->status === 'pending' && ! $isExpired;
            $statusLabel = $isPaid ? 'Paid' : ($canSyncCrypto ? 'Verifying' : ($isExpired ? 'Expired' : ($isPending ? 'Pending' : 'Cancelled')));
            $statusClass = $isPaid ? 'status-pill-paid' : ($canSyncCrypto ? 'status-pill-pending' : ($isExpired ? 'status-pill-expired' : ($isPending ? 'status-pill-pending' : 'status-pill-cancelled')));
            $methodLabel = $isCrypto ? 'Crypto (NOWPayments)' : 'QRIS';
            $methodClass = $isCrypto ? '' : 'method-pill-pakasir';
            $priceLabel = $isCrypto ? '$' . $order->price : 'Rp ' . number_format($order->price);
            $canContinueCrypto = $isPending && $isCrypto && $order->payment_url && $order->expired_at && $now->lt($order->expired_at);
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
                ],
            ];
            $canOpenPakasirQris = $canContinuePakasir && filled($pakasirCheckout['pakasir_payment']['payment_number']);
            $canCancel = $order->status === 'pending';
            $hasPaymentAction = $canSyncCrypto || $canContinueCrypto || $canSyncPakasir || $canContinuePakasir || $canCancel;
        @endphp

        <article class="order-mobile-card motion-card">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-[10px] uppercase tracking-normal text-gray-500">Order ID</div>
                    <div class="mt-1 truncate font-mono text-xs text-gray-300">{{ $order->order_id }}</div>
                </div>
                <div class="text-right">
                    <span class="status-pill {{ $statusClass }}">{{ $statusLabel }}</span>
                    @if ($isPending && ! $canSyncCrypto && $order->expired_at)
                        <div class="mt-1 text-xs text-gray-400">
                            <span class="countdown animate-pulse text-yellow-400" data-expire="{{ $order->expired_at }}"></span>
                        </div>
                    @endif
                </div>
            </div>

            <div class="mt-4">
                <h2 class="text-base font-semibold text-white">{{ $order->product->name ?? '-' }}</h2>
                <p class="mt-1 text-sm text-gray-400">{{ $order->package->name ?? '-' }}</p>
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

            @if ($hasPaymentAction)
                <div class="mt-4 flex flex-col gap-2">
                    @if ($canSyncCrypto)
                        <form action="/sync-crypto-order/{{ $order->order_id }}" method="POST" class="sync-crypto-form">
                            @csrf
                            <button type="submit" class="order-action sync-crypto-button w-full" data-order-id="{{ $order->order_id }}">
                                Verify Payment
                            </button>
                        </form>
                    @elseif ($canContinueCrypto)
                        <a href="{{ $order->payment_url }}" target="_blank" rel="noopener" class="order-action w-full">
                            Continue Payment
                        </a>
                    @elseif ($canSyncPakasir)
                        @if ($canOpenPakasirQris)
                            <button type="button" class="order-action open-pakasir-qris-button w-full" data-pakasir-checkout='@json($pakasirCheckout)'>
                                View QRIS
                            </button>
                        @elseif ($canContinuePakasir)
                            <a href="{{ $order->payment_url }}" target="_blank" rel="noopener" class="order-action w-full">
                                Open QRIS Page
                            </a>
                        @endif
                        <form action="/sync-pakasir-order/{{ $order->order_id }}" method="POST" class="sync-pakasir-form">
                            @csrf
                            <button type="submit" class="order-action sync-pakasir-button w-full" data-order-id="{{ $order->order_id }}">
                                Check Payment
                            </button>
                        </form>
                    @endif

                    @if ($canCancel)
                        <form action="/cancel-order/{{ $order->id }}" method="POST" class="cancel-order-form">
                            @csrf
                            <button type="submit" class="order-action order-action-danger cancel-order-button w-full">
                                Cancel Order
                            </button>
                        </form>
                    @endif
                </div>
            @endif
        </article>
    @empty
        <div class="empty-state">No orders yet</div>
    @endforelse
</div>

<div class="orders-table-wrap hidden md:block">
    <div class="flex items-center justify-between gap-3 border-b border-[#27272A] px-4 py-4">
        <div>
            <h2 class="text-sm font-semibold text-white">Recent Orders</h2>
            <p class="mt-1 text-xs text-gray-500">Auto-refreshes after checkout and payment retry.</p>
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
                        $isPakasir = ! $isCrypto;
                        $canSyncCrypto = $isCrypto &&
                            $order->payment_url &&
                            $order->status === 'pending' &&
                            $order->created_at &&
                            $order->created_at->gt(now()->subDay());
                        $isExpired = $order->status === 'pending' && ! $canSyncCrypto && $order->expired_at && $now->gt($order->expired_at);
                        $isPending = $order->status === 'pending' && ! $isExpired;
                        $statusLabel = $isPaid ? 'Paid' : ($canSyncCrypto ? 'Verifying' : ($isExpired ? 'Expired' : ($isPending ? 'Pending' : 'Cancelled')));
                        $statusClass = $isPaid ? 'status-pill-paid' : ($canSyncCrypto ? 'status-pill-pending' : ($isExpired ? 'status-pill-expired' : ($isPending ? 'status-pill-pending' : 'status-pill-cancelled')));
                        $methodLabel = $isCrypto ? 'Crypto (NOWPayments)' : 'QRIS';
                        $methodClass = $isCrypto ? '' : 'method-pill-pakasir';
                        $priceLabel = $isCrypto ? '$' . $order->price : 'Rp ' . number_format($order->price);
                        $canContinueCrypto = $isPending && $isCrypto && $order->payment_url && $order->expired_at && $now->lt($order->expired_at);
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
                            ],
                        ];
                        $canOpenPakasirQris = $canContinuePakasir && filled($pakasirCheckout['pakasir_payment']['payment_number']);
                        $canCancel = $order->status === 'pending';
                        $hasPaymentAction = $canSyncCrypto || $canContinueCrypto || $canSyncPakasir || $canContinuePakasir || $canCancel;
                    @endphp

                    <tr class="orders-table-row">
                        <td class="p-4">
                            <div class="max-w-[190px] truncate font-mono text-xs text-gray-300">{{ $order->order_id }}</div>
                            <div class="mt-1 text-[10px] uppercase tracking-normal text-gray-500">Invoice</div>
                        </td>
                        <td class="p-4">
                            <div class="font-semibold text-white">{{ $order->product->name ?? '-' }}</div>
                            <div class="mt-1 text-xs text-gray-500">{{ $order->package->name ?? '-' }}</div>
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
                            @if ($isPending && ! $canSyncCrypto && $order->expired_at)
                                <div class="mt-1 text-xs text-gray-400">
                                    <span class="countdown animate-pulse text-yellow-400" data-expire="{{ $order->expired_at }}"></span>
                                </div>
                            @endif
                        </td>
                        <td class="p-4 text-right">
                            <div class="inline-flex flex-wrap justify-end gap-2">
                                @if (! $hasPaymentAction)
                                    <span class="text-xs text-gray-600">-</span>
                                @elseif ($canSyncCrypto)
                                    <form action="/sync-crypto-order/{{ $order->order_id }}" method="POST" class="sync-crypto-form inline">
                                        @csrf
                                        <button type="submit" class="order-action sync-crypto-button" data-order-id="{{ $order->order_id }}">
                                            Verify
                                        </button>
                                    </form>
                                @elseif ($canContinueCrypto)
                                    <a href="{{ $order->payment_url }}" target="_blank" rel="noopener" class="order-action">
                                        Continue
                                    </a>
                                @elseif ($canSyncPakasir)
                                    @if ($canOpenPakasirQris)
                                        <button type="button" class="order-action open-pakasir-qris-button" data-pakasir-checkout='@json($pakasirCheckout)'>
                                            QRIS
                                        </button>
                                    @elseif ($canContinuePakasir)
                                        <a href="{{ $order->payment_url }}" target="_blank" rel="noopener" class="order-action">
                                            QRIS
                                        </a>
                                    @endif
                                    <form action="/sync-pakasir-order/{{ $order->order_id }}" method="POST" class="sync-pakasir-form inline">
                                        @csrf
                                        <button type="submit" class="order-action sync-pakasir-button" data-order-id="{{ $order->order_id }}">
                                            Check
                                        </button>
                                    </form>
                                @endif

                                @if ($canCancel)
                                    <form action="/cancel-order/{{ $order->id }}" method="POST" class="cancel-order-form inline">
                                        @csrf
                                        <button type="submit" class="order-action order-action-danger cancel-order-button">
                                            Cancel
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="p-8">
                            <div class="empty-state">No orders yet</div>
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
