@extends('layouts.app')

@section('content')
    @php
        $formatIdr = fn ($value) => 'Rp ' . number_format((int) $value, 0, ',', '.');
        $formatCrypto = fn ($value) => '$ ' . rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
        $statusClass = fn ($status) => match ($status) {
            'paid' => 'status-pill-paid',
            'pending' => 'status-pill-pending',
            default => 'status-pill-cancelled',
        };
    @endphp

    <div class="page-shell py-6 md:py-10">
        <section class="orders-hero fade-up mb-6">
            <div>
                <p class="mb-2 text-sm font-semibold text-[#C084FC]">Admin User Detail</p>
                <div class="flex flex-wrap items-center gap-3">
                    @if ($user->avatar)
                        <img src="{{ $user->avatar }}" alt="" class="h-12 w-12 rounded-full object-cover">
                    @else
                        <div class="flex h-12 w-12 items-center justify-center rounded-full border border-[#9333EA]/35 bg-[#9333EA]/10 text-sm font-semibold text-[#D8B4FE]">
                            {{ strtoupper(substr($user->name ?: $user->email, 0, 1)) }}
                        </div>
                    @endif
                    <div class="min-w-0">
                        <h1 class="break-words text-3xl font-bold tracking-normal md:text-4xl">{{ $user->name ?: 'Unnamed user' }}</h1>
                        <p class="mt-1 break-all text-sm text-gray-400">{{ $user->email }}</p>
                    </div>
                </div>
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $user->orders_count }}</div>
                    <div class="mt-1 text-xs text-gray-400">Total orders</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $user->paid_orders_count }}</div>
                    <div class="mt-1 text-xs text-gray-400">Paid orders</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $user->pending_orders_count }}</div>
                    <div class="mt-1 text-xs text-gray-400">Pending</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $user->licenses_count }}</div>
                    <div class="mt-1 text-xs text-gray-400">Licenses</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $formatIdr($spendStats['idr']) }}</div>
                    <div class="mt-1 text-xs text-gray-400">Paid IDR</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $formatCrypto($spendStats['crypto']) }}</div>
                    <div class="mt-1 text-xs text-gray-400">Paid crypto</div>
                </div>
            </div>
        </section>

        <div class="mb-4">
            <a href="{{ route('admin.users.index') }}" class="order-action">
                <x-ui.icon name="arrow-left" class="h-4 w-4" />
                <span>Back to Users</span>
            </a>
        </div>

        <section class="product-section mb-6 fade-up">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Orders</p>
                    <h2 class="mt-1 text-xl font-semibold text-white">Order History</h2>
                </div>
                <span class="rounded-lg border border-[#9333EA]/30 bg-[#9333EA]/10 px-3 py-1 text-xs font-semibold text-[#C084FC]">
                    {{ $orders->total() }} orders
                </span>
            </div>

            <div class="hidden overflow-x-auto md:block">
                <table class="w-full min-w-[980px] text-sm">
                    <thead class="bg-[#111115] text-xs uppercase tracking-normal text-gray-500">
                        <tr>
                            <th class="p-4 text-left">Order</th>
                            <th class="p-4 text-left">Items</th>
                            <th class="p-4 text-left">Method</th>
                            <th class="p-4 text-left">Status</th>
                            <th class="p-4 text-left">Voucher</th>
                            <th class="p-4 text-left">Paid At</th>
                            <th class="p-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($orders as $order)
                            @php
                                $methodLabel = match ($order->payment_method) {
                                    'crypto' => 'Crypto',
                                    'pakasir' => 'QRIS',
                                    'binance_pay' => 'Binance Pay',
                                    default => ucfirst($order->payment_method ?: 'Legacy'),
                                };
                            @endphp
                            <tr class="orders-table-row">
                                <td class="p-4">
                                    <div class="font-mono text-xs font-semibold text-gray-300">{{ $order->order_id }}</div>
                                    <div class="mt-1 text-xs text-gray-500">{{ $order->created_at?->timezone(config('app.timezone'))->format('d M Y, H:i') ?? '-' }} WIB</div>
                                </td>
                                <td class="p-4">
                                    @include('partials.order-items-summary', ['order' => $order, 'compact' => true])
                                </td>
                                <td class="p-4 text-gray-300">{{ $methodLabel }}</td>
                                <td class="p-4">
                                    <span class="status-pill {{ $statusClass($order->status) }}">{{ ucfirst($order->status) }}</span>
                                    <div class="mt-1 text-xs text-gray-500">{{ $order->licenses_count }} / {{ $order->quantity }} delivered</div>
                                </td>
                                <td class="p-4 text-gray-300">{{ $order->voucher?->code ?? '-' }}</td>
                                <td class="p-4 text-xs text-gray-400">
                                    {{ $order->paid_at?->timezone(config('app.timezone'))->format('d M Y, H:i') ?? '-' }}
                                </td>
                                <td class="p-4 text-right">
                                    <a href="{{ route('admin.orders.show', $order) }}" class="order-action">
                                        <x-ui.icon name="receipt" class="h-4 w-4" />
                                        <span>Order</span>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="p-8">
                                    <div class="empty-state">This user has no orders yet.</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="space-y-4 md:hidden">
                @forelse ($orders as $order)
                    <article class="order-mobile-card motion-card">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="font-mono text-xs text-gray-300">{{ $order->order_id }}</div>
                                <div class="mt-2">
                                    @include('partials.order-items-summary', ['order' => $order, 'compact' => true])
                                </div>
                            </div>
                            <span class="status-pill {{ $statusClass($order->status) }}">{{ ucfirst($order->status) }}</span>
                        </div>
                        <div class="mt-4 grid gap-2 text-sm text-gray-400">
                            <div>Voucher: <span class="font-semibold text-white">{{ $order->voucher?->code ?? '-' }}</span></div>
                            <div>Delivery: <span class="font-semibold text-white">{{ $order->licenses_count }} / {{ $order->quantity }}</span></div>
                        </div>
                        <a href="{{ route('admin.orders.show', $order) }}" class="order-action mt-4 w-full">
                            <x-ui.icon name="receipt" class="h-4 w-4" />
                            <span>Order</span>
                        </a>
                    </article>
                @empty
                    <div class="empty-state">This user has no orders yet.</div>
                @endforelse
            </div>

            @include('partials.pagination', [
                'paginator' => $orders,
                'label' => 'User order pagination',
                'itemLabel' => 'orders',
            ])
        </section>

        <section class="product-section fade-up">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Licenses</p>
                    <h2 class="mt-1 text-xl font-semibold text-white">Delivered Licenses</h2>
                </div>
                <span class="rounded-lg border border-[#9333EA]/30 bg-[#9333EA]/10 px-3 py-1 text-xs font-semibold text-[#C084FC]">
                    {{ $licenses->total() }} licenses
                </span>
            </div>

            <div class="hidden overflow-x-auto md:block">
                <table class="w-full min-w-[860px] text-sm">
                    <thead class="bg-[#111115] text-xs uppercase tracking-normal text-gray-500">
                        <tr>
                            <th class="p-4 text-left">License</th>
                            <th class="p-4 text-left">Product</th>
                            <th class="p-4 text-left">Package</th>
                            <th class="p-4 text-left">Order</th>
                            <th class="p-4 text-left">Delivered</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($licenses as $license)
                            <tr class="orders-table-row">
                                <td class="p-4">
                                    <div class="max-w-[260px] truncate font-mono text-xs text-gray-300">{{ $license->license_key }}</div>
                                </td>
                                <td class="p-4 font-semibold text-white">{{ $license->product->name ?? '-' }}</td>
                                <td class="p-4 text-gray-300">{{ $license->orderItem?->package_name ?? $license->duration ?? '-' }}</td>
                                <td class="p-4">
                                    @if ($license->order)
                                        <a href="{{ route('admin.orders.show', $license->order) }}" class="font-mono text-xs text-[#C084FC]">{{ $license->order_id }}</a>
                                    @elseif ($license->order_id)
                                        <span class="font-mono text-xs text-gray-400">{{ $license->order_id }}</span>
                                    @else
                                        <span class="text-xs text-gray-500">-</span>
                                    @endif
                                </td>
                                <td class="p-4 text-xs text-gray-400">{{ $license->created_at?->timezone(config('app.timezone'))->format('d M Y, H:i') ?? '-' }} WIB</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="p-8">
                                    <div class="empty-state">This user has no delivered licenses yet.</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="space-y-4 md:hidden">
                @forelse ($licenses as $license)
                    <article class="order-mobile-card motion-card">
                        <div class="font-semibold text-white">{{ $license->product->name ?? '-' }}</div>
                        <div class="mt-1 text-sm text-gray-400">{{ $license->orderItem?->package_name ?? $license->duration ?? '-' }}</div>
                        <div class="mt-4 break-all font-mono text-xs text-gray-300">{{ $license->license_key }}</div>
                        <div class="mt-4 text-xs text-gray-500">Order: {{ $license->order_id ?: '-' }}</div>
                    </article>
                @empty
                    <div class="empty-state">This user has no delivered licenses yet.</div>
                @endforelse
            </div>

            @include('partials.pagination', [
                'paginator' => $licenses,
                'label' => 'User license pagination',
                'itemLabel' => 'licenses',
            ])
        </section>
    </div>
@endsection
