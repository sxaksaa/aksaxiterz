@extends('layouts.app')

@section('content')
    @php
        $isPaid = $order->status === 'paid';
        $statusClass = $isPaid ? 'status-pill-paid' : ($order->status === 'pending' ? 'status-pill-pending' : 'status-pill-cancelled');
        $methodLabel = $order->payment_method === 'crypto' ? 'Crypto (NOWPayments)' : 'QRIS';
        $paidAt = ($order->paid_at ?: ($isPaid ? $order->updated_at : null))?->timezone(config('app.timezone'));
        $createdAt = $order->created_at?->timezone(config('app.timezone'));
        $expiresAt = $order->expired_at?->timezone(config('app.timezone'));
        $payload = is_array($order->payment_payload) ? $order->payment_payload : [];
    @endphp

    <div class="page-shell py-6 md:py-10">
        <section class="orders-hero fade-up mb-6">
            <div class="grid gap-5 lg:grid-cols-[1fr_auto] lg:items-end">
                <div>
                    <p class="mb-2 text-sm font-semibold text-[#C084FC]">Admin Order Detail</p>
                    <h1 class="break-all text-2xl font-bold tracking-normal md:text-4xl">{{ $order->order_id }}</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                        Verify customer payment state, license delivery, and provider references before helping support.
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('admin.orders.index') }}" class="btn-footer-secondary">All Orders</a>
                    <a href="{{ route('admin.license-stocks.index') }}" class="btn-footer-secondary">Stock</a>
                    <a href="/orders" class="btn-footer">My Orders</a>
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
                    <div class="text-xl font-semibold text-white">{{ $order->license ? 'Delivered' : 'Missing' }}</div>
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
            <div class="mb-4 rounded-xl border border-[#9333EA]/30 bg-[#9333EA]/10 px-4 py-3 text-sm text-[#D8B4FE]">
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
                        <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Order</p>
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
                        <span>Product</span>
                        <span class="text-right text-gray-200">{{ $order->product->name ?? '-' }}</span>
                    </div>
                    <div class="qris-detail-row">
                        <span>Package</span>
                        <span class="text-right text-gray-200">{{ $order->package->name ?? '-' }}</span>
                    </div>
                    <div class="qris-detail-row">
                        <span>Amount</span>
                        <span class="font-semibold text-[#D8B4FE]">
                            {{ $order->payment_method === 'crypto' ? '$' . $order->price : 'Rp ' . number_format($order->price) }}
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
                        <span class="text-right font-semibold text-[#D8B4FE]">{{ $paidAt ? $paidAt->format('d M Y, H:i:s') . ' WIB' : '-' }}</span>
                    </div>
                </div>

                <div class="mt-5 flex flex-wrap gap-2">
                    @if (! $isPaid)
                        <form action="{{ route('admin.orders.mark-paid', $order) }}" method="POST">
                            @csrf
                            <button class="order-action" onclick="return confirm('Mark this order as paid and deliver a license?')">
                                Mark Paid
                            </button>
                        </form>
                    @endif

                    <form action="{{ route('admin.orders.resync-license', $order) }}" method="POST">
                        @csrf
                        <button class="order-action">
                            Resync License
                        </button>
                    </form>

                    @if ($order->payment_url)
                        <a href="{{ $order->payment_url }}" target="_blank" rel="noopener noreferrer" class="order-action">
                            Open Payment
                        </a>
                    @endif
                </div>
            </section>

            <div class="grid gap-6">
                <section class="product-section fade-up">
                    <div class="mb-4">
                        <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Delivery</p>
                        <h2 class="mt-1 text-xl font-semibold text-white">License</h2>
                    </div>

                    @if ($order->license)
                        <div class="grid gap-3 text-sm">
                            <div class="qris-detail-row">
                                <span>License key</span>
                                <span class="break-all text-right font-mono text-xs text-gray-200">{{ $order->license->license_key }}</span>
                            </div>
                            <div class="qris-detail-row">
                                <span>Delivered</span>
                                <span class="text-right text-gray-200">{{ $order->license->created_at?->timezone(config('app.timezone'))->format('d M Y, H:i:s') ?? '-' }} WIB</span>
                            </div>
                        </div>
                    @else
                        <div class="empty-state">No license has been attached to this order yet.</div>
                    @endif
                </section>

                <section class="product-section fade-up">
                    <div class="mb-4">
                        <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Provider</p>
                        <h2 class="mt-1 text-xl font-semibold text-white">Payment Data</h2>
                    </div>

                    <div class="grid gap-3 text-sm">
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
                    </div>
                </section>
            </div>
        </div>
    </div>
@endsection
