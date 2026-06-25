@extends('layouts.app')

@section('content')
    @php
        $formatIdr = fn ($amount) => 'Rp ' . number_format((int) round($amount), 0, ',', '.');
        $formatCrypto = fn ($amount) => '$' . number_format((float) $amount, 2);
        $formatLineRevenue = fn ($amount) => $salesTrend['line_format'] === 'crypto'
            ? $formatCrypto($amount)
            : $formatIdr($amount);
        $formatMetric = function (array $card) use ($formatIdr, $formatCrypto) {
            return match ($card['format']) {
                'idr' => $formatIdr($card['value']),
                'crypto' => $formatCrypto($card['value']),
                default => number_format((int) $card['value']),
            };
        };
        $statusClass = fn ($status) => match ($status) {
            'paid' => 'status-pill-paid',
            'pending' => 'status-pill-pending',
            default => 'status-pill-cancelled',
        };
        $methodLabel = fn ($method) => match ($method) {
            'pakasir' => 'QRIS',
            'crypto' => 'Crypto',
            'binance_pay' => 'Binance Pay',
            default => ucfirst($method ?: 'Legacy'),
        };

        $chartPoints = collect($salesTrend['points']);
        $chartWidth = 760;
        $chartHeight = 260;
        $chartPadX = 34;
        $chartTop = 28;
        $plotHeight = 154;
        $chartBottom = $chartTop + $plotHeight;
        $chartUsableWidth = $chartWidth - ($chartPadX * 2);
        $pointCount = max(1, $chartPoints->count());
        $xForIndex = fn ($index) => $pointCount === 1
            ? $chartWidth / 2
            : $chartPadX + (($chartUsableWidth / max(1, $pointCount - 1)) * $index);
        $barSlot = $pointCount > 1 ? $chartUsableWidth / $pointCount : 72;
        $barWidth = min(26, max(7, $barSlot * 0.52));
        $curvePoints = [];
        $labelStep = $salesTrend['label_step'];
        $hasSales = ($selectedStats['orders'] ?? 0) > 0;
        $dashboardUrl = function (array $overrides = []) use ($activeRange, $activePaymentMethod) {
            $query = array_merge([
                'range' => $activeRange,
                'method' => $activePaymentMethod,
            ], $overrides);

            if (($query['method'] ?? 'all') === 'all') {
                unset($query['method']);
            }

            return route('admin.dashboard', $query);
        };
    @endphp

    <div class="page-shell py-6 md:py-10" aria-live="polite">
        <section class="orders-hero fade-up mb-6">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="mb-2 text-sm font-semibold text-[#C084FC]">Admin</p>
                    <h1 class="text-3xl font-bold tracking-normal md:text-4xl">Dashboard</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                        {{ $rangeMeta['label'] }} performance, order activity, and stock pressure.
                    </p>
                </div>

                <div class="flex w-fit max-w-full flex-wrap gap-1 rounded-xl border border-[#27272A] bg-black/20 p-1">
                    @foreach ($rangeOptions as $rangeKey => $range)
                        <a href="{{ $dashboardUrl(['range' => $rangeKey]) }}"
                            data-dashboard-range-link
                            aria-current="{{ (string) $activeRange === (string) $rangeKey ? 'page' : 'false' }}"
                            class="inline-flex min-h-10 items-center justify-center rounded-lg px-4 text-sm font-semibold transition {{ (string) $activeRange === (string) $rangeKey ? 'bg-[#9333EA] text-white' : 'text-gray-400 hover:bg-white/[0.04] hover:text-white' }}">
                            {{ $range['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($metricCards as $card)
                    <div class="order-stat">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">
                                    {{ $card['label'] }}
                                </div>
                                <div class="mt-2 text-xl font-semibold text-white">
                                    {{ $formatMetric($card) }}
                                </div>
                            </div>
                            <x-ui.icon name="{{ $card['icon'] }}" class="h-5 w-5 text-[#C084FC]" />
                        </div>
                        <div class="mt-3 text-xs text-gray-400">{{ $card['caption'] }}</div>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="mb-6 grid gap-4 xl:grid-cols-[1.35fr_0.65fr]">
            <section class="product-section fade-up">
                <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Sales Trend</p>
                        <h2 class="mt-1 text-xl font-semibold text-white">{{ $rangeMeta['label'] }}</h2>
                        <p class="mt-1 text-sm text-gray-400">{{ $rangeMeta['description'] }}</p>
                    </div>

                    <div class="flex flex-col gap-2 sm:items-end">
                        <div class="flex flex-wrap gap-2 text-xs font-semibold sm:justify-end">
                            <span class="rounded-lg border border-[#9333EA]/30 bg-[#9333EA]/10 px-3 py-2 text-[#D8B4FE]">
                                {{ $salesTrend['line_label'] }} line
                            </span>
                            <span class="rounded-lg border border-[#38BDF8]/30 bg-[#38BDF8]/10 px-3 py-2 text-[#BAE6FD]">
                                Order bars
                            </span>
                        </div>

                        <div class="flex w-fit max-w-full flex-wrap gap-1 rounded-xl border border-[#27272A] bg-black/20 p-1 text-xs font-semibold">
                            @foreach ($paymentMethodOptions as $methodKey => $method)
                                <a href="{{ $dashboardUrl(['method' => $methodKey]) }}"
                                    data-dashboard-range-link
                                    aria-current="{{ (string) $activePaymentMethod === (string) $methodKey ? 'page' : 'false' }}"
                                    class="inline-flex min-h-9 items-center justify-center rounded-lg px-3 transition {{ (string) $activePaymentMethod === (string) $methodKey ? 'bg-[#9333EA] text-white' : 'text-gray-400 hover:bg-white/[0.04] hover:text-white' }}">
                                    {{ $method['short'] }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="dashboard-chart-frame" data-dashboard-chart>
                    <div id="dashboardSalesTooltip" class="dashboard-chart-tooltip" role="status" aria-live="polite" aria-hidden="true"
                        data-dashboard-chart-tooltip>
                        <div class="dashboard-chart-tooltip-title" data-chart-tooltip-title></div>
                        <div class="dashboard-chart-tooltip-row">
                            <span>Orders</span>
                            <strong data-chart-tooltip-orders></strong>
                        </div>
                        <div class="dashboard-chart-tooltip-row">
                            <span>{{ $salesTrend['line_label'] }}</span>
                            <strong data-chart-tooltip-line></strong>
                        </div>
                        <div class="dashboard-chart-tooltip-row">
                            <span>IDR Revenue</span>
                            <strong data-chart-tooltip-idr></strong>
                        </div>
                        <div class="dashboard-chart-tooltip-row">
                            <span>Crypto Revenue</span>
                            <strong data-chart-tooltip-crypto></strong>
                        </div>
                    </div>

                    <div class="overflow-x-auto pb-1">
                        <svg viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" class="min-w-[720px] w-full" role="img" aria-label="Sales trend chart"
                            data-dashboard-chart-svg>
                        <defs>
                            <linearGradient id="aksaRevenueFill" x1="0" x2="0" y1="0" y2="1">
                                <stop offset="0%" stop-color="#C084FC" stop-opacity="0.24" />
                                <stop offset="100%" stop-color="#C084FC" stop-opacity="0" />
                            </linearGradient>
                        </defs>

                        @for ($line = 0; $line <= 3; $line++)
                            @php
                                $gridY = $chartTop + (($plotHeight / 3) * $line);
                            @endphp
                            <line x1="{{ $chartPadX }}" y1="{{ $gridY }}" x2="{{ $chartWidth - $chartPadX }}" y2="{{ $gridY }}"
                                stroke="#27272A" stroke-width="1" />
                        @endfor

                        @foreach ($chartPoints as $pointIndex => $point)
                            @php
                                $x = $xForIndex($pointIndex);
                                $orderHeight = $point['orders'] > 0
                                    ? max(5, ($point['orders'] / $salesTrend['max_orders']) * $plotHeight)
                                    : 2;
                                $barY = $chartBottom - $orderHeight;
                                $revenueY = $chartBottom - (($point['line_revenue'] / $salesTrend['max_line_revenue']) * $plotHeight);
                                $formattedLine = $formatLineRevenue($point['line_revenue']);
                                $formattedIdr = $formatIdr($point['idr_revenue']);
                                $formattedCrypto = $formatCrypto($point['crypto_revenue']);
                                $hasPointActivity = $point['orders'] > 0 || $point['line_revenue'] > 0;
                                $curvePoints[] = [
                                    'x' => round($x, 2),
                                    'y' => round($revenueY, 2),
                                ];
                                $showLabel = $hasPointActivity || $pointIndex === 0 || $pointIndex === $pointCount - 1 || $pointIndex % $labelStep === 0;
                            @endphp

                            <rect x="{{ $x - ($barWidth / 2) }}" y="{{ $barY }}" width="{{ $barWidth }}" height="{{ $orderHeight }}"
                                rx="4" fill="#38BDF8" opacity="{{ $point['orders'] > 0 ? '0.34' : '0.12' }}">
                                <title>{{ $point['label'] }}: {{ $point['orders'] }} orders, {{ $salesTrend['line_label'] }} {{ $formattedLine }}, {{ $formattedIdr }}, {{ $formattedCrypto }}</title>
                            </rect>

                            @if ($showLabel)
                                <text x="{{ $x }}" y="{{ $chartBottom + 28 }}" text-anchor="middle" fill="#71717A" font-size="11">
                                    {{ $point['short_label'] }}
                                </text>
                            @endif
                        @endforeach

                        @php
                            $revenuePath = '';
                            $revenueAreaPath = '';
                            $curveCount = count($curvePoints);

                            if ($curveCount === 1) {
                                $point = $curvePoints[0];
                                $startX = max($chartPadX, $point['x'] - 28);
                                $endX = min($chartWidth - $chartPadX, $point['x'] + 28);
                                $leftControl = round(($startX + $point['x']) / 2, 2);
                                $rightControl = round(($point['x'] + $endX) / 2, 2);
                                $revenuePath = "M {$startX} {$point['y']} C {$leftControl} {$point['y']}, {$rightControl} {$point['y']}, {$endX} {$point['y']}";
                                $revenueAreaPath = "{$revenuePath} L {$endX} {$chartBottom} L {$startX} {$chartBottom} Z";
                            } elseif ($curveCount > 1) {
                                $revenuePath = "M {$curvePoints[0]['x']} {$curvePoints[0]['y']}";

                                for ($i = 1; $i < $curveCount; $i++) {
                                    $previous = $curvePoints[$i - 1];
                                    $current = $curvePoints[$i];
                                    $midX = round(($previous['x'] + $current['x']) / 2, 2);
                                    $revenuePath .= " C {$midX} {$previous['y']}, {$midX} {$current['y']}, {$current['x']} {$current['y']}";
                                }

                                $firstPoint = $curvePoints[0];
                                $lastPoint = $curvePoints[$curveCount - 1];
                                $revenueAreaPath = "{$revenuePath} L {$lastPoint['x']} {$chartBottom} L {$firstPoint['x']} {$chartBottom} Z";
                            }
                        @endphp

                        @if ($chartPoints->isNotEmpty() && $revenuePath)
                            <path d="{{ $revenueAreaPath }}" fill="url(#aksaRevenueFill)" />
                            <path d="{{ $revenuePath }}" fill="none" stroke="#C084FC" stroke-width="3.5"
                                stroke-linecap="round" stroke-linejoin="round" />

                            @foreach ($chartPoints as $pointIndex => $point)
                                @if ($point['line_revenue'] > 0)
                                    @php
                                        $x = $xForIndex($pointIndex);
                                        $revenueY = $chartBottom - (($point['line_revenue'] / $salesTrend['max_line_revenue']) * $plotHeight);
                                    @endphp
                                    <circle cx="{{ $x }}" cy="{{ $revenueY }}" r="4"
                                        fill="#D8B4FE" stroke="#111115" stroke-width="2">
                                        <title>{{ $point['label'] }}: {{ $formatLineRevenue($point['line_revenue']) }} {{ $salesTrend['line_label'] }}</title>
                                    </circle>
                                @endif
                            @endforeach
                        @endif

                        @foreach ($chartPoints as $pointIndex => $point)
                            @php
                                $x = $xForIndex($pointIndex);
                                $revenueY = $chartBottom - (($point['line_revenue'] / $salesTrend['max_line_revenue']) * $plotHeight);
                                $previousX = $pointIndex > 0 ? $xForIndex($pointIndex - 1) : $chartPadX;
                                $nextX = $pointIndex < $pointCount - 1 ? $xForIndex($pointIndex + 1) : $chartWidth - $chartPadX;
                                $hitLeft = $pointCount === 1 ? max($chartPadX, $x - 64) : (($previousX + $x) / 2);
                                $hitRight = $pointCount === 1 ? min($chartWidth - $chartPadX, $x + 64) : (($x + $nextX) / 2);
                                $hitWidth = max(34, $hitRight - $hitLeft);
                                $formattedLine = $formatLineRevenue($point['line_revenue']);
                                $formattedIdr = $formatIdr($point['idr_revenue']);
                                $formattedCrypto = $formatCrypto($point['crypto_revenue']);
                            @endphp
                            <rect x="{{ round($hitLeft, 2) }}" y="{{ $chartTop - 14 }}" width="{{ round($hitWidth, 2) }}"
                                height="{{ $plotHeight + 48 }}" rx="8" class="dashboard-chart-hit" tabindex="0"
                                data-chart-point data-label="{{ $point['label'] }}" data-short-label="{{ $point['short_label'] }}"
                                data-orders="{{ number_format((int) $point['orders']) }}" data-line="{{ $formattedLine }}" data-idr="{{ $formattedIdr }}"
                                data-crypto="{{ $formattedCrypto }}" data-x="{{ round($x, 2) }}" data-y="{{ round($revenueY, 2) }}"
                                aria-label="{{ $point['label'] }}: {{ $point['orders'] }} orders, {{ $salesTrend['line_label'] }} {{ $formattedLine }}, {{ $formattedIdr }} IDR revenue, {{ $formattedCrypto }} crypto revenue"></rect>
                        @endforeach
                        </svg>
                    </div>
                </div>

                @unless ($hasSales)
                    <div class="mt-3 rounded-xl border border-[#27272A] bg-black/20 px-4 py-3 text-sm text-gray-400">
                        No paid orders in this period.
                    </div>
                @endunless
            </section>

            <section class="product-section fade-up">
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Period Summary</p>
                        <h2 class="mt-1 text-xl font-semibold text-white">{{ $rangeMeta['label'] }}</h2>
                    </div>
                    <x-ui.icon name="sliders-horizontal" class="h-5 w-5 text-[#C084FC]" />
                </div>

                <div class="grid gap-3">
                    <div class="rounded-xl border border-[#27272A] bg-black/20 p-3">
                        <div class="text-xs text-gray-500">Window</div>
                        <div class="mt-1 text-sm font-semibold text-white">{{ $rangeMeta['description'] }}</div>
                    </div>
                    <div class="rounded-xl border border-[#27272A] bg-black/20 p-3">
                        <div class="text-xs text-gray-500">Average IDR order</div>
                        <div class="mt-1 text-sm font-semibold text-white">
                            {{ $selectedStats['orders'] > 0 ? $formatIdr($selectedStats['idr_revenue'] / max(1, $selectedStats['orders'])) : '-' }}
                        </div>
                    </div>
                </div>

                <div class="mt-5">
                    <div class="mb-2 text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Payment Split</div>
                    <div class="grid gap-2">
                        @forelse ($paymentSplit as $row)
                            <div class="flex items-center justify-between gap-3 rounded-xl border border-[#27272A] bg-black/20 px-3 py-2">
                                <div class="text-sm font-semibold text-white">{{ $row['method'] }}</div>
                                <div class="text-xs font-semibold text-gray-400">{{ $row['orders'] }} orders</div>
                            </div>
                        @empty
                            <div class="rounded-xl border border-[#27272A] bg-black/20 px-3 py-3 text-sm text-gray-500">
                                No paid payment activity.
                            </div>
                        @endforelse
                    </div>
                </div>
            </section>
        </div>

        <div class="mb-6 grid gap-4 lg:grid-cols-[1.15fr_0.85fr]">
            <section class="product-section fade-up">
                <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Operations</p>
                        <h2 class="mt-1 text-xl font-semibold text-white">Needs attention</h2>
                        <p class="mt-1 text-xs text-gray-500">{{ $rangeMeta['label'] }} order activity.</p>
                    </div>
                    <a href="{{ route('admin.orders.index', ['delivery' => 'incomplete']) }}" class="btn-footer-secondary w-fit">
                        <x-ui.icon name="receipt" class="h-4 w-4" />
                        <span>Delivery Issues</span>
                    </a>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <a href="{{ route('admin.orders.index', ['status' => 'pending']) }}" class="order-stat transition hover:border-[#9333EA]/50">
                        <div class="text-xl font-semibold text-white">{{ $orderStats['pending'] }}</div>
                        <div class="mt-1 text-xs text-gray-400">Pending orders</div>
                    </a>
                    <a href="{{ route('admin.orders.index', ['delivery' => 'incomplete']) }}" class="order-stat transition hover:border-red-500/40">
                        <div class="text-xl font-semibold {{ $orderStats['delivery_issues'] > 0 ? 'text-red-300' : 'text-white' }}">
                            {{ $orderStats['delivery_issues'] }}
                        </div>
                        <div class="mt-1 text-xs text-gray-400">Delivery issues</div>
                    </a>
                    <a href="{{ route('admin.license-stocks.index', ['status' => 'available']) }}" class="order-stat transition hover:border-[#9333EA]/50">
                        <div class="text-xl font-semibold text-white">{{ $stockStats['available'] }}</div>
                        <div class="mt-1 text-xs text-gray-400">Available keys</div>
                    </a>
                    <a href="{{ route('admin.license-stocks.index') }}" class="order-stat transition hover:border-amber-400/40">
                        <div class="text-xl font-semibold {{ $stockStats['low_stock'] > 0 ? 'text-amber-200' : 'text-white' }}">
                            {{ $stockStats['low_stock'] }}
                        </div>
                        <div class="mt-1 text-xs text-gray-400">Low stock packages</div>
                    </a>
                </div>
            </section>

            <section class="product-section fade-up">
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Stock</p>
                        <h2 class="mt-1 text-xl font-semibold text-white">License inventory</h2>
                    </div>
                    <a href="{{ route('admin.license-stocks.index') }}" class="order-action">
                        <x-ui.icon name="key-round" class="h-4 w-4" />
                        <span>Open</span>
                    </a>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl border border-[#27272A] bg-black/20 p-3">
                        <div class="text-lg font-semibold text-white">{{ $stockStats['available'] }}</div>
                        <div class="mt-1 text-xs text-gray-500">Available</div>
                    </div>
                    <div class="rounded-xl border border-[#27272A] bg-black/20 p-3">
                        <div class="text-lg font-semibold text-white">{{ $stockStats['reserved'] }}</div>
                        <div class="mt-1 text-xs text-gray-500">Reserved</div>
                    </div>
                    <div class="rounded-xl border border-[#27272A] bg-black/20 p-3">
                        <div class="text-lg font-semibold text-white">{{ $stockStats['sold'] }}</div>
                        <div class="mt-1 text-xs text-gray-500">Sold</div>
                    </div>
                </div>
            </section>
        </div>

        @if ($lowStockPackages->isNotEmpty())
            <section class="mb-6 rounded-xl border border-amber-400/30 bg-amber-400/10 px-4 py-4 text-sm text-amber-100 fade-up">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="font-semibold text-amber-50">Low stock notice</h2>
                        <p class="mt-1 text-xs text-amber-100/80">
                            Packages with {{ $lowStockThreshold }} or fewer available keys need restocking.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        @foreach ($lowStockPackages as $package)
                            <span class="rounded-lg border border-amber-300/30 bg-black/20 px-3 py-2 text-xs font-semibold">
                                {{ $package->product->name ?? 'Product' }} - {{ $package->name }}:
                                {{ $package->available_license_stocks_count }} left
                            </span>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        <section class="product-section mb-6 fade-up">
            <div class="mb-4 flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">{{ $rangeMeta['label'] }}</p>
                    <h2 class="mt-1 text-xl font-semibold text-white">Top products</h2>
                </div>
                <a href="{{ route('admin.orders.index', ['status' => 'paid']) }}" class="order-action">
                    <x-ui.icon name="receipt" class="h-4 w-4" />
                    <span>Orders</span>
                </a>
            </div>

            <div class="grid gap-3 lg:grid-cols-5">
                @forelse ($topProducts as $product)
                    <div class="rounded-xl border border-[#27272A] bg-black/20 p-3">
                        <div class="truncate text-sm font-semibold text-white">{{ $product['product'] }}</div>
                        <div class="mt-3 text-xl font-semibold text-[#C084FC]">{{ $product['quantity'] }}</div>
                        <div class="mt-1 text-xs text-gray-500">{{ $formatIdr($product['idr_total']) }} IDR line total</div>
                    </div>
                @empty
                    <div class="empty-state lg:col-span-5">No paid product sales in this period.</div>
                @endforelse
            </div>
        </section>

        <div class="orders-table-wrap hidden lg:block fade-up">
            <div class="flex items-center justify-between gap-3 border-b border-[#27272A] px-4 py-4">
                <div>
                    <h2 class="text-sm font-semibold text-white">Recent Orders</h2>
                    <p class="mt-1 text-xs text-gray-500">{{ $rangeMeta['label'] }} customer checkout activity.</p>
                </div>
                <a href="{{ route('admin.orders.index') }}" class="order-action">
                    <x-ui.icon name="eye" class="h-4 w-4" />
                    <span>View All</span>
                </a>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[980px] text-sm">
                    <thead class="bg-[#111115] text-xs uppercase tracking-normal text-gray-500">
                        <tr>
                            <th class="p-4 text-left">Order</th>
                            <th class="p-4 text-left">Customer</th>
                            <th class="p-4 text-left">Items</th>
                            <th class="p-4 text-left">Method</th>
                            <th class="p-4 text-left">Status</th>
                            <th class="p-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recentOrders as $order)
                            <tr class="orders-table-row">
                                <td class="p-4">
                                    <div class="font-mono text-xs font-semibold text-gray-300">{{ $order->order_id }}</div>
                                    <div class="mt-1 text-xs text-gray-500">
                                        Created: {{ $order->created_at?->timezone(config('app.timezone'))->format('d M Y, H:i') ?? '-' }} WIB
                                    </div>
                                    @if ($order->paid_at)
                                        <div class="mt-1 text-xs text-gray-500">
                                            Paid: {{ $order->paid_at?->timezone(config('app.timezone'))->format('d M Y, H:i') ?? '-' }} WIB
                                        </div>
                                    @endif
                                </td>
                                <td class="p-4">
                                    <div class="font-semibold text-white">{{ $order->user->name ?? '-' }}</div>
                                    <div class="mt-1 max-w-[220px] truncate text-xs text-gray-500">{{ $order->user->email ?? '-' }}</div>
                                </td>
                                <td class="p-4">
                                    @include('partials.order-items-summary', ['order' => $order, 'compact' => true])
                                </td>
                                <td class="p-4 text-gray-300">{{ $methodLabel($order->payment_method) }}</td>
                                <td class="p-4">
                                    <span class="status-pill {{ $statusClass($order->status) }}">{{ ucfirst($order->status) }}</span>
                                    <div class="mt-1 text-xs text-gray-500">{{ $order->licenses_count }} licenses</div>
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
                                <td colspan="6" class="p-8">
                                    <div class="empty-state">No recent orders yet.</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-4 lg:hidden">
            @forelse ($recentOrders as $order)
                <article class="order-mobile-card motion-card">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-mono text-xs text-gray-300">{{ $order->order_id }}</div>
                            <div class="mt-2">
                                @include('partials.order-items-summary', ['order' => $order, 'compact' => true])
                            </div>
                            <div class="mt-1 truncate text-xs text-gray-500">{{ $order->user->email ?? '-' }}</div>
                        </div>
                        <span class="status-pill {{ $statusClass($order->status) }}">{{ ucfirst($order->status) }}</span>
                    </div>

                    <div class="mt-4 grid gap-2 text-sm text-gray-400">
                        <div>Method: <span class="font-semibold text-white">{{ $methodLabel($order->payment_method) }}</span></div>
                        <div>Delivery: <span class="font-semibold text-white">{{ $order->licenses_count }} licenses</span></div>
                    </div>

                    <a href="{{ route('admin.orders.show', $order) }}" class="order-action mt-4 w-full">
                        <x-ui.icon name="eye" class="h-4 w-4" />
                        <span>Detail</span>
                    </a>
                </article>
            @empty
                <div class="empty-state">No recent orders yet.</div>
            @endforelse
        </div>
    </div>
@endsection
