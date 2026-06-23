@extends('layouts.app')

@section('content')
    @php
        $formatIdr = fn ($value) => 'Rp ' . number_format((int) $value, 0, ',', '.');
        $formatCrypto = fn ($value, $token = null) => rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.') . ($token ? ' ' . $token : '');
        $formatUsd = fn ($value) => '$ ' . rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
        $formatRowMoney = fn ($row, $key) => $row['currency_group'] === 'idr'
            ? $formatIdr($row[$key])
            : $formatCrypto($row[$key], $row['currency']);
        $statusClass = fn ($status) => match ($status) {
            'paid' => 'status-pill-paid',
            'pending' => 'status-pill-pending',
            default => 'status-pill-cancelled',
        };
    @endphp

    <div class="page-shell py-6 md:py-10">
        <section class="orders-hero fade-up mb-6">
            <div>
                <p class="mb-2 text-sm font-semibold text-[#C084FC]">Voucher Usage</p>
                <h1 class="break-all text-3xl font-bold tracking-normal md:text-4xl">{{ $voucher->code }}</h1>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                    Customer usage, checkout totals, final payment amounts, and voucher discounts for this promo code.
                </p>
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $usageStats['paid_orders'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Paid uses</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $usageStats['active_orders'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Active invoices</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $formatIdr($usageStats['checkout_idr']) }}</div>
                    <div class="mt-1 text-xs text-gray-400">Paid checkout IDR</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $formatIdr($usageStats['discount_idr']) }}</div>
                    <div class="mt-1 text-xs text-gray-400">IDR discount</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $formatUsd($usageStats['checkout_crypto']) }}</div>
                    <div class="mt-1 text-xs text-gray-400">Paid checkout $</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $formatUsd($usageStats['discount_crypto']) }}</div>
                    <div class="mt-1 text-xs text-gray-400">$ discount</div>
                </div>
            </div>
        </section>

        <div class="mb-4">
            <a href="{{ route('admin.vouchers.index') }}" class="order-action">
                <x-ui.icon name="arrow-left" class="h-4 w-4" />
                <span>Back to Vouchers</span>
            </a>
        </div>

        <div class="orders-table-wrap hidden md:block">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1180px] text-sm">
                    <thead class="bg-[#111115] text-xs uppercase tracking-normal text-gray-500">
                        <tr>
                            <th class="p-4 text-left">Customer</th>
                            <th class="p-4 text-left">Order</th>
                            <th class="p-4 text-left">Items</th>
                            <th class="p-4 text-left">Method</th>
                            <th class="p-4 text-right">Checkout</th>
                            <th class="p-4 text-right">Voucher Cut</th>
                            <th class="p-4 text-right">After Voucher</th>
                            <th class="p-4 text-left">Date</th>
                            <th class="p-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($usageRows as $row)
                            @php($order = $row['order'])
                            <tr class="orders-table-row">
                                <td class="p-4">
                                    <div class="font-semibold text-white">{{ $order->user->name ?? '-' }}</div>
                                    <div class="mt-1 break-all text-xs text-gray-500">{{ $order->user->email ?? '-' }}</div>
                                </td>
                                <td class="p-4">
                                    <div class="break-all font-mono text-xs text-gray-300">{{ $order->order_id }}</div>
                                    <div class="mt-2">
                                        <span class="status-pill {{ $statusClass($order->status) }}">{{ ucfirst($order->status) }}</span>
                                    </div>
                                </td>
                                <td class="p-4 text-xs text-gray-400">{{ $row['item_summary'] ?: '-' }}</td>
                                <td class="p-4 text-gray-300">
                                    <div>{{ $row['method_label'] }}</div>
                                    <div class="mt-1 text-xs text-gray-500">{{ $order->total_quantity }} {{ $order->total_quantity === 1 ? 'key' : 'keys' }}</div>
                                </td>
                                <td class="p-4 text-right font-semibold text-gray-200">{{ $formatRowMoney($row, 'subtotal_value') }}</td>
                                <td class="p-4 text-right font-semibold text-emerald-300">-{{ $formatRowMoney($row, 'discount_value') }}</td>
                                <td class="p-4 text-right">
                                    <div class="font-semibold text-[#D8B4FE]">{{ $formatRowMoney($row, 'final_value') }}</div>
                                    <div class="mt-1 text-xs text-gray-500">Requested {{ $formatRowMoney($row, 'paid_value') }}</div>
                                </td>
                                <td class="p-4 text-xs text-gray-400">
                                    <div>{{ $order->created_at?->timezone(config('app.timezone'))->format('d M Y') ?? '-' }}</div>
                                    <div class="mt-1">{{ $order->created_at?->timezone(config('app.timezone'))->format('H:i') ?? '-' }} WIB</div>
                                </td>
                                <td class="p-4 text-right">
                                    <a href="{{ route('admin.orders.show', $order) }}" class="order-action">
                                        <x-ui.icon name="receipt" class="h-4 w-4" />
                                        <span>Order</span>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="p-8"><div class="empty-state">This voucher has not been used yet.</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-4 md:hidden">
            @forelse ($usageRows as $row)
                @php($order = $row['order'])
                <article class="order-mobile-card motion-card">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="font-semibold text-white">{{ $order->user->name ?? '-' }}</div>
                            <div class="mt-1 break-all text-xs text-gray-500">{{ $order->user->email ?? '-' }}</div>
                        </div>
                        <span class="status-pill {{ $statusClass($order->status) }}">{{ ucfirst($order->status) }}</span>
                    </div>
                    <div class="mt-4 break-all font-mono text-xs text-gray-400">{{ $order->order_id }}</div>
                    <div class="mt-4 grid gap-2 text-sm">
                        <div class="qris-detail-row">
                            <span>Checkout</span>
                            <span class="font-semibold text-gray-200">{{ $formatRowMoney($row, 'subtotal_value') }}</span>
                        </div>
                        <div class="qris-detail-row">
                            <span>Voucher cut</span>
                            <span class="font-semibold text-emerald-300">-{{ $formatRowMoney($row, 'discount_value') }}</span>
                        </div>
                        <div class="qris-detail-row qris-total-row">
                            <span>After voucher</span>
                            <span class="font-semibold text-[#D8B4FE]">{{ $formatRowMoney($row, 'final_value') }}</span>
                        </div>
                    </div>
                    <div class="mt-4 text-xs text-gray-500">{{ $row['method_label'] }} · Requested {{ $formatRowMoney($row, 'paid_value') }}</div>
                    <div class="mt-4 text-xs text-gray-400">{{ $row['item_summary'] ?: '-' }}</div>
                    <div class="mt-4">
                        <a href="{{ route('admin.orders.show', $order) }}" class="order-action">
                            <x-ui.icon name="receipt" class="h-4 w-4" />
                            <span>Order</span>
                        </a>
                    </div>
                </article>
            @empty
                <div class="empty-state">This voucher has not been used yet.</div>
            @endforelse
        </div>

        @include('partials.pagination', [
            'paginator' => $usageRows,
            'label' => 'Voucher usage pagination',
            'itemLabel' => 'uses',
        ])
    </div>
@endsection
