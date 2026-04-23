@extends('layouts.app')

@section('content')
    <div class="max-w-5xl mx-auto px-6 py-10">

        <h1 class="text-2xl font-semibold mb-6">Riwayat Order</h1>

        <div class="bg-[#15151B] border border-[#27272A] rounded-xl overflow-hidden">

            <table class="w-full text-sm">
                <thead class="bg-[#1f1f25] text-gray-400">
                    <tr>
                        <th class="p-3 text-left">Order ID</th>
                        <th class="p-3 text-left">Produk</th>
                        <th class="p-3 text-left">Paket</th>
                        <th class="p-3 text-left">Metode</th>
                        <th class="p-3 text-left">Harga</th>
                        <th class="p-3 text-left">Status</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($orders as $order)
                        <tr class="border-t border-[#27272A]">

                            <td class="p-3 text-xs text-gray-400">
                                {{ $order->order_id }}
                            </td>

                            <td class="p-3">
                                {{ $order->product->name }}
                            </td>

                            <td class="p-3">
                                {{ $order->package->name }}
                            </td>

                            <td class="p-3">
                                @if ($order->payment_method == 'crypto')
                                    <span class="text-green-400">Crypto (USDT)</span>
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
                                    <span class="text-green-400">✔ Paid</span>
                                @elseif($order->status == 'pending')
                                    <span class="text-yellow-400">⏳ Pending</span>
                                @else
                                    <span class="text-red-400">✖ Cancelled</span>
                                @endif
                            </td>

                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-6 text-center text-gray-400">
                                Belum ada order 😅
                            </td>
                        </tr>
                    @endforelse
                </tbody>

            </table>

        </div>

    </div>
@endsection
