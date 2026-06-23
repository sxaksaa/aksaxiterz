@extends('layouts.app')

@section('content')
    <div class="page-shell py-6 md:py-10">
        <section class="orders-hero fade-up mb-6">
            <div>
                <div>
                    <p class="mb-2 text-sm font-semibold text-[#C084FC]">Admin</p>
                    <h1 class="text-3xl font-bold tracking-normal md:text-4xl">Orders</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                        Check payment status, customer details, delivery state, and license visibility in one place.
                    </p>
                </div>
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['total'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Total orders</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['pending'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Pending</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['paid'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Paid</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['cancelled'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Cancelled</div>
                </div>
                <a href="{{ route('admin.orders.index', ['delivery' => 'incomplete']) }}" class="order-stat transition hover:border-red-500/40">
                    <div class="text-xl font-semibold {{ $stats['delivery_issues'] > 0 ? 'text-red-300' : 'text-white' }}">
                        {{ $stats['delivery_issues'] }}
                    </div>
                    <div class="mt-1 text-xs text-gray-400">Delivery issues</div>
                </a>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['licenses'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Licenses</div>
                </div>
            </div>
        </section>

        @if (session('info'))
            <div class="mb-4 rounded-xl border border-[#9333EA]/30 bg-[#9333EA]/10 px-4 py-3 text-sm text-[#D8B4FE]">
                {{ session('info') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                {{ $errors->first() }}
            </div>
        @endif

        <section class="product-section mb-6 fade-up">
            <form id="adminOrderFilterForm" method="GET" action="{{ route('admin.orders.index') }}"
                class="grid gap-3 md:grid-cols-2 md:items-end xl:grid-cols-[1fr_0.65fr_0.65fr_0.65fr_auto]">
                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Search</span>
                    <input name="search" value="{{ request('search') }}" class="search-bar w-full"
                        placeholder="Order ID, customer, or product">
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Status</span>
                    <select name="status" class="search-bar w-full">
                        <option value="">All status</option>
                        @foreach (['pending' => 'Pending', 'paid' => 'Paid', 'cancelled' => 'Cancelled'] as $value => $label)
                            <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Method</span>
                    <select name="method" class="search-bar w-full">
                        <option value="">All methods</option>
                        <option value="pakasir" @selected(request('method') === 'pakasir')>QRIS</option>
                        <option value="crypto" @selected(request('method') === 'crypto')>Crypto</option>
                        <option value="binance_pay" @selected(request('method') === 'binance_pay')>Binance Pay</option>
                    </select>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Delivery</span>
                    <select name="delivery" class="search-bar w-full">
                        <option value="">All delivery</option>
                        <option value="incomplete" @selected(request('delivery') === 'incomplete')>Needs attention</option>
                    </select>
                </label>

                <div class="flex gap-2">
                    <button type="submit" class="btn-footer h-12">
                        <x-ui.icon name="filter" class="h-4 w-4" />
                        <span>Filter</span>
                    </button>
                    <a href="{{ route('admin.orders.index') }}" class="btn-footer-secondary h-12">
                        <x-ui.icon name="rotate-ccw" class="h-4 w-4" />
                        <span>Reset</span>
                    </a>
                </div>
            </form>
        </section>

        <div class="orders-table-wrap hidden md:block">
            <div class="flex items-center justify-between gap-3 border-b border-[#27272A] px-4 py-4">
                <div>
                    <h2 class="text-sm font-semibold text-white">Order Operations</h2>
                    <p class="mt-1 text-xs text-gray-500">Use detail view for manual paid checks and license resync.</p>
                </div>
                <span class="rounded-lg border border-[#9333EA]/30 bg-[#9333EA]/10 px-3 py-1 text-xs font-semibold text-[#C084FC]">
                    {{ $orders->total() }} records
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[1060px] text-sm">
                    <thead class="bg-[#111115] text-xs uppercase tracking-normal text-gray-500">
                        <tr>
                            <th class="p-4 text-left">Order</th>
                            <th class="p-4 text-left">Customer</th>
                            <th class="p-4 text-left">Product</th>
                            <th class="p-4 text-left">Method</th>
                            <th class="p-4 text-left">Status</th>
                            <th class="p-4 text-left">Paid At</th>
                            <th class="p-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($orders as $order)
                            @php
                                $isPaid = $order->status === 'paid';
                                $methodLabel = match ($order->payment_method) {
                                    'crypto' => 'Crypto',
                                    'pakasir' => 'QRIS',
                                    'binance_pay' => 'Binance Pay',
                                    default => ucfirst($order->payment_method ?: 'Legacy'),
                                };
                                $statusClass = $isPaid ? 'status-pill-paid' : ($order->status === 'pending' ? 'status-pill-pending' : 'status-pill-cancelled');
                                $paidAt = ($order->paid_at ?: ($isPaid ? $order->updated_at : null))?->timezone(config('app.timezone'));
                            @endphp

                            <tr class="orders-table-row">
                                <td class="p-4">
                                    <div class="font-mono text-xs font-semibold text-gray-300">{{ $order->order_id }}</div>
                                    <div class="mt-1 text-xs text-gray-500">{{ $order->created_at?->timezone(config('app.timezone'))->format('d M Y, H:i') ?? '-' }} WIB</div>
                                </td>
                                <td class="p-4">
                                    <div class="font-semibold text-white">{{ $order->user->name ?? '-' }}</div>
                                    <div class="mt-1 max-w-[220px] truncate text-xs text-gray-500">{{ $order->user->email ?? '-' }}</div>
                                </td>
                                <td class="p-4">
                                    @include('partials.order-items-summary', ['order' => $order, 'compact' => true])
                                </td>
                                <td class="p-4 text-gray-300">{{ $methodLabel }}</td>
                                <td class="p-4">
                                    <span class="status-pill {{ $statusClass }}">{{ ucfirst($order->status) }}</span>
                                    <div class="mt-1 text-xs {{ $isPaid && $order->licenses_count < max(1, (int) $order->quantity) ? 'text-red-300' : 'text-gray-500' }}">
                                        {{ $order->licenses_count }} / {{ $order->quantity }} delivered
                                    </div>
                                    @include('partials.order-status-timeline', ['order' => $order])
                                </td>
                                <td class="p-4 text-xs text-gray-300">
                                    {{ $paidAt ? $paidAt->format('d M Y, H:i:s') . ' WIB' : '-' }}
                                </td>
                                <td class="p-4 text-right">
                                    <a href="{{ route('admin.orders.show', $order) }}" class="order-action compact-action" title="Open order detail" aria-label="Open detail for {{ $order->order_id }}">
                                        <x-ui.icon name="eye" class="h-4 w-4" />
                                        <span class="sr-only">Detail</span>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="p-8">
                                    <div class="empty-state">
                                        <span class="empty-state-icon">
                                            <x-ui.icon name="receipt" class="h-6 w-6" />
                                        </span>
                                        <span class="empty-state-title">No orders found</span>
                                        <p class="empty-state-copy">Try a different search, status, method, or delivery filter.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-4 md:hidden">
            @forelse ($orders as $order)
                @php
                    $isPaid = $order->status === 'paid';
                    $statusClass = $isPaid ? 'status-pill-paid' : ($order->status === 'pending' ? 'status-pill-pending' : 'status-pill-cancelled');
                @endphp
                <article class="order-mobile-card motion-card">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-mono text-xs text-gray-300">{{ $order->order_id }}</div>
                            <div class="mt-2">
                                @include('partials.order-items-summary', ['order' => $order, 'compact' => true])
                            </div>
                            <div class="mt-1 truncate text-xs text-gray-500">{{ $order->user->email ?? '-' }}</div>
                        </div>
                        <span class="status-pill {{ $statusClass }}">{{ ucfirst($order->status) }}</span>
                    </div>

                    @include('partials.order-status-timeline', ['order' => $order])

                    <div class="mt-4 grid gap-2 text-sm text-gray-400">
                        <div>Method:
                            <span class="font-semibold text-white">
                                {{ match ($order->payment_method) {
                                    'crypto' => 'Crypto',
                                    'pakasir' => 'QRIS',
                                    'binance_pay' => 'Binance Pay',
                                    default => ucfirst($order->payment_method ?: 'Legacy'),
                                } }}
                            </span>
                        </div>
                        <div>Items: <span class="font-semibold text-white">{{ $order->item_count }} package(s)</span></div>
                        <div>Quantity: <span class="font-semibold text-white">{{ $order->total_quantity }} {{ $order->total_quantity === 1 ? 'key' : 'keys' }}</span></div>
                        <div>Delivery:
                            <span class="font-semibold {{ $isPaid && $order->licenses_count < max(1, (int) $order->quantity) ? 'text-red-300' : 'text-white' }}">
                                {{ $order->licenses_count }} / {{ $order->quantity }} delivered
                            </span>
                        </div>
                    </div>

                    <a href="{{ route('admin.orders.show', $order) }}" class="order-action mt-4 w-full">
                        <x-ui.icon name="eye" class="h-4 w-4" />
                        <span>Detail</span>
                    </a>
                </article>
            @empty
                <div class="empty-state">
                    <span class="empty-state-icon">
                        <x-ui.icon name="receipt" class="h-6 w-6" />
                    </span>
                    <span class="empty-state-title">No orders found</span>
                    <p class="empty-state-copy">Try a different search, status, method, or delivery filter.</p>
                </div>
            @endforelse
        </div>

        @include('partials.pagination', [
            'paginator' => $orders,
            'label' => 'Admin order pagination',
            'itemLabel' => 'orders',
        ])
    </div>

@endsection
