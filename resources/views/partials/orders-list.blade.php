<!-- ================= MOBILE (CARD) ================= -->
<div class="space-y-4 md:hidden">

    @forelse ($orders as $order)
        <div class="bg-[#15151B] border border-[#27272A] rounded-xl p-4 space-y-2">

            <div class="flex justify-between text-xs text-gray-400">
                <span>{{ $order->order_id }}</span>
                <span>
                    @if ($order->status == 'paid')
                        <span class="text-green-400">Paid</span>
                    @elseif ($order->status == 'pending' && $order->expired_at && now()->gt($order->expired_at))
                        <span class="text-red-400">Expired</span>
                    @elseif($order->status == 'pending')
                        <span class="text-yellow-400">Pending</span>

                        @if ($order->expired_at && now()->lt($order->expired_at))
                            <div class="text-xs text-gray-400 mt-1">
                                <span class="countdown text-yellow-400 animate-pulse"
                                    data-expire="{{ $order->expired_at }}">
                                </span>
                            </div>
                        @endif
                    @else
                        <span class="text-red-400">Cancelled (Replaced)</span>
                    @endif
                </span>
            </div>

            <div class="text-sm font-semibold">
                {{ $order->product->name ?? '-' }}
            </div>

            <div class="text-xs text-gray-400">
                {{ $order->package->name ?? '-' }}
            </div>

            <div class="text-xs">
                @if ($order->payment_method == 'crypto')
                    <span class="text-green-400">Crypto (USDT)</span>
                @else
                    <span class="text-blue-400">Midtrans</span>
                @endif
            </div>

            <div class="text-[#C084FC] text-sm">
                @if ($order->payment_method == 'crypto')
                    ${{ $order->price }}
                @else
                    Rp {{ number_format($order->price) }}
                @endif
            </div>

            <div class="pt-2">
                @if (
                    $order->status === 'pending' &&
                        $order->payment_method === 'crypto' &&
                        $order->payment_url &&
                        $order->expired_at &&
                        now()->lt($order->expired_at))
                    <a href="{{ $order->payment_url }}" target="_blank" rel="noopener" class="text-purple-400 text-xs underline">
                        Continue
                    </a>
                @elseif (
                    $order->status === 'pending' &&
                        $order->payment_method === 'midtrans' &&
                        $order->expired_at &&
                        now()->lt($order->expired_at))
                    <form action="/pay-again/{{ $order->id }}" method="POST" class="pay-again-form">
                        @csrf
                        <button type="submit" class="pay-btn text-blue-400 text-xs underline">
                            Pay Again
                        </button>
                    </form>
                @elseif ($order->status === 'pending' && $order->expired_at && now()->gt($order->expired_at))
                    <span class="text-red-400 text-xs">Expired</span>
                @else
                    <span class="text-gray-500 text-xs">-</span>
                @endif
            </div>

        </div>
    @empty
        <p class="text-gray-400 text-sm">Belum ada order</p>
    @endforelse

</div>

<!-- ================= DESKTOP (TABLE) ================= -->
<div class="hidden md:block bg-[#15151B] border border-[#27272A] rounded-xl overflow-hidden">

    <table class="w-full text-sm">

        <thead class="bg-[#1f1f25] text-gray-400">
            <tr>
                <th class="p-3 text-left">Order ID</th>
                <th class="p-3 text-left">Produk</th>
                <th class="p-3 text-left">Paket</th>
                <th class="p-3 text-left">Metode</th>
                <th class="p-3 text-left">Harga</th>
                <th class="p-3 text-left">Status</th>
                <th class="p-3 text-left">Action</th>
            </tr>
        </thead>

        <tbody id="ordersTable">
            @forelse ($orders as $order)
                <tr class="border-t border-[#27272A]">

                    <td class="p-3 text-xs text-gray-400">{{ $order->order_id }}</td>
                    <td class="p-3">{{ $order->product->name ?? '-' }}</td>
                    <td class="p-3">{{ $order->package->name ?? '-' }}</td>

                    <td class="p-3">
                        @if ($order->payment_method == 'crypto')
                            <span class="text-purple-400">Crypto (USDT)</span>
                        @else
                            <span class="text-blue-400">Midtrans</span>
                        @endif
                    </td>

                    <td class="p-3 text-[#C084FC]">
                        @if ($order->payment_method == 'crypto')
                            ${{ $order->price }}
                        @else
                            Rp {{ number_format($order->price) }}
                        @endif
                    </td>

                    <td class="p-3">
                        @if ($order->status == 'paid')
                            <span class="text-green-400">Paid</span>
                        @elseif ($order->status == 'pending' && $order->expired_at && now()->gt($order->expired_at))
                            <span class="text-red-400">Expired</span>
                        @elseif($order->status == 'pending')
                            <span class="text-yellow-400">Pending</span>

                            @if ($order->expired_at && now()->lt($order->expired_at))
                                <div class="text-xs text-gray-400">
                                    <span class="countdown text-yellow-400 animate-pulse"
                                        data-expire="{{ $order->expired_at }}">
                                    </span>
                                </div>
                            @endif
                        @else
                            <span class="text-red-400">Cancelled (Replaced)</span>
                        @endif
                    </td>

                    <td class="p-3">
                        @if (
                            $order->status === 'pending' &&
                                $order->payment_method === 'crypto' &&
                                $order->payment_url &&
                                $order->expired_at &&
                                now()->lt($order->expired_at))
                            <a href="{{ $order->payment_url }}" target="_blank" rel="noopener"
                                class="text-purple-400 text-xs underline">
                                Continue
                            </a>
                        @elseif (
                            $order->status === 'pending' &&
                                $order->payment_method === 'midtrans' &&
                                $order->expired_at &&
                                now()->lt($order->expired_at))
                            <form action="/pay-again/{{ $order->id }}" method="POST" class="pay-again-form inline">
                                @csrf
                                <button type="submit" class="pay-btn text-blue-400 text-xs underline">
                                    Pay Again
                                </button>
                            </form>
                        @elseif ($order->status === 'pending' && $order->expired_at && now()->gt($order->expired_at))
                            <span class="text-red-400 text-xs">Expired</span>
                        @else
                            -
                        @endif
                    </td>

                </tr>
            @empty
                <tr>
                    <td colspan="7" class="p-6 text-center text-gray-400">
                        Belum ada order
                    </td>
                </tr>
            @endforelse
        </tbody>

    </table>

</div>
