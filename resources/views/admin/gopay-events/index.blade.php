@extends('layouts.app')

@section('content')
    @php
        $statusLabels = [
            'received' => 'Received',
            'matched' => 'Matched',
            'matched_delivery_pending' => 'Delivery pending',
            'unmatched' => 'Unmatched',
            'ambiguous' => 'Ambiguous',
            'stale' => 'Stale',
        ];
        $statusClasses = [
            'matched' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-200',
            'matched_delivery_pending' => 'border-amber-400/30 bg-amber-400/10 text-amber-200',
            'unmatched' => 'border-red-400/30 bg-red-400/10 text-red-200',
            'ambiguous' => 'border-red-400/30 bg-red-400/10 text-red-200',
            'stale' => 'border-gray-500/30 bg-gray-500/10 text-gray-300',
            'received' => 'border-aksa-accent-30 bg-aksa-accent-10 text-aksa-accent-soft',
        ];
    @endphp

    <div class="page-shell py-6 md:py-10">
        <section class="orders-hero fade-up mb-6">
            <div>
                <p class="mb-2 text-sm font-semibold text-aksa-accent">Admin</p>
                <h1 class="text-3xl font-bold tracking-normal md:text-4xl">GoPay QRIS Events</h1>
                <p class="mt-3 max-w-3xl text-sm leading-6 text-gray-400 md:text-base">
                    Monitor signed notifications from the merchant phone. Unmatched and stale events are recorded here for safe manual review.
                </p>
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['total'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">All events</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-emerald-200">{{ $stats['matched'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Matched payments</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold {{ $stats['attention'] > 0 ? 'text-red-200' : 'text-white' }}">{{ $stats['attention'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Needs attention</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold {{ $stats['delivery_pending'] > 0 ? 'text-amber-200' : 'text-white' }}">{{ $stats['delivery_pending'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Delivery pending</div>
                </div>
            </div>
        </section>

        <section class="product-section mb-6 fade-up">
            <form method="GET" action="{{ route('admin.gopay-events.index') }}"
                class="grid gap-3 md:grid-cols-[1fr_0.65fr_auto] md:items-end">
                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Search</span>
                    <input name="search" value="{{ request('search') }}" class="search-bar w-full"
                        placeholder="Event ID, exact amount, device, or order ID">
                </label>
                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Status</span>
                    <select name="status" class="search-bar w-full">
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="flex gap-2">
                    <button type="submit" class="btn-footer h-12">
                        <x-ui.icon name="filter" class="h-4 w-4" />
                        <span>Filter</span>
                    </button>
                    <a href="{{ route('admin.gopay-events.index') }}" class="btn-footer-secondary h-12">
                        <x-ui.icon name="rotate-ccw" class="h-4 w-4" />
                        <span>Reset</span>
                    </a>
                </div>
            </form>
        </section>

        <div class="orders-table-wrap hidden lg:block">
            <div class="flex items-center justify-between gap-3 border-b border-[#27272A] px-4 py-4">
                <div>
                    <h2 class="text-sm font-semibold text-white">Notification ledger</h2>
                    <p class="mt-1 text-xs text-gray-500">A 202 response means the event was safely recorded, not that an order was paid.</p>
                </div>
                <span class="rounded-lg border border-aksa-accent-30 bg-aksa-accent-10 px-3 py-1 text-xs font-semibold text-aksa-accent">
                    {{ $events->total() }} records
                </span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1180px] text-sm">
                    <thead class="bg-[#111115] text-xs uppercase tracking-normal text-gray-500">
                        <tr>
                            <th class="p-4 text-left">Payment time</th>
                            <th class="p-4 text-left">Amount</th>
                            <th class="p-4 text-left">Status</th>
                            <th class="p-4 text-left">Notification</th>
                            <th class="p-4 text-left">Matched order</th>
                            <th class="p-4 text-left">Device / event</th>
                            <th class="p-4 text-left">Received</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($events as $event)
                            <tr class="orders-table-row align-top">
                                <td class="p-4 text-xs text-gray-300">
                                    <div>{{ $event->notificationPostedAt()->format('d M Y') }}</div>
                                    <div class="mt-1 text-gray-500">{{ $event->notificationPostedAt()->format('H:i:s') }} WIB</div>
                                </td>
                                <td class="p-4 font-semibold text-white">Rp {{ number_format($event->amount_idr, 0, ',', '.') }}</td>
                                <td class="p-4">
                                    <span class="inline-flex rounded-lg border px-2.5 py-1 text-xs font-semibold {{ $statusClasses[$event->status] ?? $statusClasses['received'] }}">
                                        {{ $statusLabels[$event->status] ?? ucfirst($event->status) }}
                                    </span>
                                </td>
                                <td class="p-4">
                                    <div class="max-w-[260px] font-semibold text-white">{{ $event->title }}</div>
                                    <div class="mt-1 max-w-[280px] text-xs leading-5 text-gray-400">{{ $event->notification_text }}</div>
                                </td>
                                <td class="p-4">
                                    @if ($event->matchedOrder)
                                        <a href="{{ route('admin.orders.show', $event->matchedOrder) }}" class="font-mono text-xs font-semibold text-aksa-accent hover:text-white">
                                            {{ $event->matchedOrder->order_id }}
                                        </a>
                                        <div class="mt-1 max-w-[200px] truncate text-xs text-gray-500">{{ $event->matchedOrder->user?->email ?: '-' }}</div>
                                    @else
                                        <span class="text-xs text-gray-500">No order matched</span>
                                    @endif
                                </td>
                                <td class="p-4 text-xs text-gray-400">
                                    <div>{{ $event->device_id }}</div>
                                    <div class="mt-1 max-w-[190px] truncate font-mono text-gray-600" title="{{ $event->event_id }}">{{ $event->event_id }}</div>
                                </td>
                                <td class="p-4 text-xs text-gray-400">
                                    <div>{{ $event->received_at?->timezone(config('app.timezone'))->format('d M Y, H:i:s') ?? '-' }}</div>
                                    @if ($event->last_received_at && !$event->last_received_at->equalTo($event->received_at))
                                        <div class="mt-1 text-gray-600">Retried {{ $event->last_received_at->timezone(config('app.timezone'))->format('d M, H:i:s') }}</div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="p-8"><div class="empty-state">No QRIS events found</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-4 lg:hidden">
            @forelse ($events as $event)
                <article class="order-mobile-card motion-card">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="font-semibold text-white">Rp {{ number_format($event->amount_idr, 0, ',', '.') }}</div>
                            <div class="mt-1 text-xs text-gray-500">{{ $event->notificationPostedAt()->format('d M Y, H:i:s') }} WIB</div>
                        </div>
                        <span class="inline-flex shrink-0 rounded-lg border px-2.5 py-1 text-xs font-semibold {{ $statusClasses[$event->status] ?? $statusClasses['received'] }}">
                            {{ $statusLabels[$event->status] ?? ucfirst($event->status) }}
                        </span>
                    </div>
                    <div class="mt-4 text-sm font-semibold text-white">{{ $event->title }}</div>
                    <div class="mt-1 text-sm text-gray-400">{{ $event->notification_text }}</div>
                    <div class="mt-4 grid gap-2 text-xs text-gray-500">
                        <div>Device: <span class="text-gray-300">{{ $event->device_id }}</span></div>
                        @if ($event->matchedOrder)
                            <div>Order: <a href="{{ route('admin.orders.show', $event->matchedOrder) }}" class="font-mono text-aksa-accent">{{ $event->matchedOrder->order_id }}</a></div>
                        @else
                            <div>Order: <span class="text-gray-300">No match</span></div>
                        @endif
                        <div class="truncate font-mono" title="{{ $event->event_id }}">Event: {{ $event->event_id }}</div>
                    </div>
                </article>
            @empty
                <div class="empty-state">No QRIS events found</div>
            @endforelse
        </div>

        @include('partials.pagination', [
            'paginator' => $events,
            'label' => 'GoPay QRIS event pagination',
            'itemLabel' => 'events',
        ])
    </div>
@endsection
