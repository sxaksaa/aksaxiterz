@php
    $now = now();
@endphp

<div class="space-y-4 md:hidden">
    @forelse ($orders as $order)
        @php
            $orderDate = $order->created_at?->timezone(config('app.timezone'));
            $isPaid = $order->status === 'paid';
            $isCrypto = $order->payment_method === 'crypto';
            $canSyncCrypto = $isCrypto &&
                $order->payment_url &&
                $order->status === 'pending' &&
                $order->created_at &&
                $order->created_at->gt(now()->subDay());
            $isExpired = $order->status === 'pending' && ! $canSyncCrypto && $order->expired_at && $now->gt($order->expired_at);
            $isPending = $order->status === 'pending' && ! $isExpired;
            $statusLabel = $isPaid ? 'Paid' : ($canSyncCrypto ? 'Verifying' : ($isExpired ? 'Expired' : ($isPending ? 'Pending' : 'Cancelled')));
            $statusClass = $isPaid ? 'status-pill-paid' : ($canSyncCrypto ? 'status-pill-pending' : ($isExpired ? 'status-pill-expired' : ($isPending ? 'status-pill-pending' : 'status-pill-cancelled')));
            $methodLabel = $isCrypto ? 'Crypto (USDT)' : 'Midtrans';
            $methodClass = $isCrypto ? '' : 'method-pill-midtrans';
            $priceLabel = $isCrypto ? '$' . $order->price : 'Rp ' . number_format($order->price);
            $canContinueCrypto = $isPending && $isCrypto && $order->payment_url && $order->expired_at && $now->lt($order->expired_at);
            $canPayMidtrans = $isPending && ! $isCrypto && $order->expired_at && $now->lt($order->expired_at);
            $canCancel = $order->status === 'pending';
        @endphp

        <article class="order-mobile-card motion-card">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-[10px] uppercase tracking-normal text-gray-500">Order ID</div>
                    <div class="mt-1 flex min-w-0 items-center gap-2">
                        <div class="truncate font-mono text-xs text-gray-300">{{ $order->order_id }}</div>
                        <button type="button" data-copy-value="{{ $order->order_id }}"
                            data-copy-title="Order ID copied"
                            data-copy-message="The order ID is ready to paste."
                            class="shrink-0 rounded-md border border-[#9333EA]/30 px-2 py-1 text-[10px] font-semibold text-[#C084FC] transition hover:border-[#C084FC] hover:text-white">
                            Copy
                        </button>
                    </div>
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
                    <span class="text-xs text-gray-500">Date</span>
                    <span class="text-right text-xs text-gray-300">
                        {{ $orderDate?->format('d M Y') ?? '-' }}
                        <span class="block text-gray-500">{{ $orderDate ? $orderDate->format('H:i:s') . ' WIB' : '-' }}</span>
                    </span>
                </div>
            </div>

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
                @elseif ($canPayMidtrans)
                    <form action="/pay-again/{{ $order->id }}" method="POST" class="pay-again-form">
                        @csrf
                        <button type="submit" class="order-action pay-btn w-full">
                            Pay Again
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
                @elseif ($isPaid)
                    <a href="/licenses" class="order-action w-full">View License</a>
                @elseif (! $canSyncCrypto && ! $canContinueCrypto && ! $canPayMidtrans)
                    <span class="inline-flex w-full items-center justify-center rounded-lg border border-[#27272A] px-3 py-2 text-xs font-semibold text-gray-500">
                        No action needed
                    </span>
                @endif
            </div>
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
                    <th class="p-4 text-left">Date</th>
                    <th class="p-4 text-left">Status</th>
                    <th class="p-4 text-right">Action</th>
                </tr>
            </thead>

            <tbody id="ordersTable">
                @forelse ($orders as $order)
                    @php
                        $orderDate = $order->created_at?->timezone(config('app.timezone'));
                        $isPaid = $order->status === 'paid';
                        $isCrypto = $order->payment_method === 'crypto';
                        $canSyncCrypto = $isCrypto &&
                            $order->payment_url &&
                            $order->status === 'pending' &&
                            $order->created_at &&
                            $order->created_at->gt(now()->subDay());
                        $isExpired = $order->status === 'pending' && ! $canSyncCrypto && $order->expired_at && $now->gt($order->expired_at);
                        $isPending = $order->status === 'pending' && ! $isExpired;
                        $statusLabel = $isPaid ? 'Paid' : ($canSyncCrypto ? 'Verifying' : ($isExpired ? 'Expired' : ($isPending ? 'Pending' : 'Cancelled')));
                        $statusClass = $isPaid ? 'status-pill-paid' : ($canSyncCrypto ? 'status-pill-pending' : ($isExpired ? 'status-pill-expired' : ($isPending ? 'status-pill-pending' : 'status-pill-cancelled')));
                        $methodLabel = $isCrypto ? 'Crypto (USDT)' : 'Midtrans';
                        $methodClass = $isCrypto ? '' : 'method-pill-midtrans';
                        $priceLabel = $isCrypto ? '$' . $order->price : 'Rp ' . number_format($order->price);
                        $canContinueCrypto = $isPending && $isCrypto && $order->payment_url && $order->expired_at && $now->lt($order->expired_at);
                        $canPayMidtrans = $isPending && ! $isCrypto && $order->expired_at && $now->lt($order->expired_at);
                        $canCancel = $order->status === 'pending';
                    @endphp

                    <tr class="orders-table-row">
                        <td class="p-4">
                            <div class="flex max-w-[190px] items-center gap-2">
                                <div class="min-w-0 truncate font-mono text-xs text-gray-300">{{ $order->order_id }}</div>
                                <button type="button" data-copy-value="{{ $order->order_id }}"
                                    data-copy-title="Order ID copied"
                                    data-copy-message="The order ID is ready to paste."
                                    class="shrink-0 rounded-md border border-[#9333EA]/30 px-2 py-1 text-[10px] font-semibold text-[#C084FC] transition hover:border-[#C084FC] hover:text-white">
                                    Copy
                                </button>
                            </div>
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
                                @if ($canSyncCrypto)
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
                                @elseif ($canPayMidtrans)
                                    <form action="/pay-again/{{ $order->id }}" method="POST" class="pay-again-form inline">
                                        @csrf
                                        <button type="submit" class="order-action pay-btn">
                                            Pay Again
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
                                @elseif ($isPaid)
                                    <a href="/licenses" class="order-action">License</a>
                                @elseif (! $canSyncCrypto && ! $canContinueCrypto && ! $canPayMidtrans)
                                    <span class="text-xs text-gray-500">No action</span>
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
