<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Services\PendingOrderExpirationService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    public function __construct(
        private readonly PendingOrderExpirationService $pendingOrderExpirationService
    ) {}

    public function index(Request $request)
    {
        $this->pendingOrderExpirationService->expire(limit: 500);

        $now = now();
        $rangeOptions = $this->rangeOptions();
        $activeRange = $this->normalizeRange((string) $request->query('range', 'daily'));
        $paymentMethodOptions = $this->paymentMethodOptions();
        $activePaymentMethod = $this->normalizePaymentMethod((string) $request->query('method', 'all'));
        $paymentMethodMeta = $paymentMethodOptions[$activePaymentMethod];

        $paidOrders = Order::query()
            ->with(['items', 'product', 'package'])
            ->where('status', 'paid')
            ->get(['id', 'order_id', 'product_id', 'package_id', 'status', 'payment_method', 'price', 'quantity', 'payment_payload', 'paid_at', 'created_at']);

        $methodPaidOrders = $this->filterOrdersByPaymentMethod($paidOrders, $activePaymentMethod);

        $firstPaidDate = $methodPaidOrders
            ->map(fn (Order $order) => $this->paidDate($order))
            ->filter()
            ->sortBy(fn ($date) => $date->timestamp)
            ->first();

        $rangeMeta = $this->rangeMeta($activeRange, $now, $firstPaidDate);
        $filteredPaidOrders = $this->filterOrdersByRange($methodPaidOrders, $rangeMeta['from']);
        $selectedStats = $this->periodStats($filteredPaidOrders);
        $salesTrend = $this->salesTrend($filteredPaidOrders, $activeRange, $rangeMeta['from'], $now, $firstPaidDate, $activePaymentMethod);

        $metricCards = [
            [
                'label' => 'IDR Revenue',
                'value' => $selectedStats['idr_revenue'],
                'format' => 'idr',
                'caption' => $rangeMeta['label'].' · '.$paymentMethodMeta['label'],
                'icon' => 'circle-dollar-sign',
            ],
            [
                'label' => 'Crypto Revenue',
                'value' => $selectedStats['crypto_revenue'],
                'format' => 'crypto',
                'caption' => $rangeMeta['label'].' · '.$paymentMethodMeta['label'],
                'icon' => 'wallet',
            ],
            [
                'label' => 'Paid Orders',
                'value' => $selectedStats['orders'],
                'format' => 'count',
                'caption' => $rangeMeta['label'].' · '.$paymentMethodMeta['label'],
                'icon' => 'receipt',
            ],
            [
                'label' => 'Licenses Sold',
                'value' => $selectedStats['licenses'],
                'format' => 'count',
                'caption' => $rangeMeta['label'].' · '.$paymentMethodMeta['label'],
                'icon' => 'key-round',
            ],
        ];

        $lowStockThreshold = (int) config('admin.low_stock_threshold', 3);
        $lowStockPackages = Package::query()
            ->whereHas('product', fn ($query) => $query->visible())
            ->with('product')
            ->withCount(['licenseStocks', 'availableLicenseStocks'])
            ->orderBy('product_id')
            ->orderBy('price')
            ->get()
            ->filter(fn (Package $package) => (int) $package->available_license_stocks_count <= $lowStockThreshold)
            ->sortBy([
                ['available_license_stocks_count', 'asc'],
                ['product.name', 'asc'],
                ['price', 'asc'],
            ])
            ->values();

        $stockStats = [
            'available' => LicenseStock::available()->count(),
            'reserved' => LicenseStock::reserved()->count(),
            'sold' => LicenseStock::where('is_sold', true)->count(),
            'low_stock' => $lowStockPackages->count(),
        ];

        $orderStats = [
            'pending' => $this->ordersInRange($rangeMeta['from'], $activePaymentMethod)->where('status', 'pending')->count(),
            'paid' => $this->ordersInRange($rangeMeta['from'], $activePaymentMethod)->where('status', 'paid')->count(),
            'cancelled' => $this->ordersInRange($rangeMeta['from'], $activePaymentMethod)->where('status', 'cancelled')->count(),
            'delivery_issues' => $this->deliveryIssueCount($rangeMeta['from'], $activePaymentMethod),
        ];

        $topProducts = $this->topProducts($filteredPaidOrders);
        $paymentSplit = $this->paymentSplit($filteredPaidOrders);

        $lowStockPackages = $lowStockPackages->take(8)->values();

        return view('admin.dashboard.index', compact(
            'activeRange',
            'activePaymentMethod',
            'rangeOptions',
            'paymentMethodOptions',
            'paymentMethodMeta',
            'rangeMeta',
            'metricCards',
            'selectedStats',
            'salesTrend',
            'stockStats',
            'orderStats',
            'topProducts',
            'paymentSplit',
            'lowStockPackages',
            'lowStockThreshold'
        ));
    }

    private function rangeOptions(): array
    {
        return [
            'hourly' => ['label' => 'Hourly', 'short' => 'Hour'],
            'daily' => ['label' => 'Daily', 'short' => 'Day'],
            'weekly' => ['label' => 'Weekly', 'short' => 'Week'],
            'monthly' => ['label' => 'Monthly', 'short' => 'Month'],
            'lifetime' => ['label' => 'Lifetime', 'short' => 'All'],
        ];
    }

    private function paymentMethodOptions(): array
    {
        return [
            'all' => ['label' => 'All payments', 'short' => 'All'],
            'pakasir' => ['label' => 'QRIS', 'short' => 'QRIS'],
            'binance_pay' => ['label' => 'Binance Pay', 'short' => 'Binance'],
            'crypto' => ['label' => 'Crypto', 'short' => 'Crypto'],
        ];
    }

    private function normalizeRange(string $range): string
    {
        $aliases = [
            '1' => 'hourly',
            'today' => 'hourly',
            '7' => 'daily',
            '30' => 'weekly',
            'all' => 'lifetime',
        ];
        $range = $aliases[$range] ?? $range;

        return array_key_exists($range, $this->rangeOptions()) ? $range : 'daily';
    }

    private function normalizePaymentMethod(string $method): string
    {
        return array_key_exists($method, $this->paymentMethodOptions()) ? $method : 'all';
    }

    private function rangeMeta(string $range, $now, $firstPaidDate): array
    {
        $timezoneLabel = $this->timezoneLabel();

        if ($range === 'hourly') {
            $from = $now->copy()->startOfDay();

            return [
                'range' => $range,
                'label' => 'Hourly',
                'from' => $from,
                'description' => $from->format('d M Y').' by paid hour ('.$timezoneLabel.')',
            ];
        }

        if ($range === 'weekly') {
            $from = $now->copy()->subWeeks(7)->startOfWeek();

            return [
                'range' => $range,
                'label' => 'Weekly',
                'from' => $from,
                'description' => $from->format('d M Y').' - '.$now->format('d M Y').' by paid week ('.$timezoneLabel.')',
            ];
        }

        if ($range === 'monthly') {
            $from = $now->copy()->subMonths(11)->startOfMonth();

            return [
                'range' => $range,
                'label' => 'Monthly',
                'from' => $from,
                'description' => $from->format('M Y').' - '.$now->format('M Y').' by paid month ('.$timezoneLabel.')',
            ];
        }

        if ($range === 'lifetime') {
            return [
                'range' => $range,
                'label' => 'Lifetime',
                'from' => null,
                'description' => $firstPaidDate
                    ? 'Since '.$firstPaidDate->format('d M Y').' by paid date ('.$timezoneLabel.')'
                    : 'No paid orders yet',
            ];
        }

        $from = $now->copy()->subDays(6)->startOfDay();

        return [
            'range' => 'daily',
            'label' => 'Daily',
            'from' => $from,
            'description' => $from->format('d M Y').' - '.$now->format('d M Y').' by paid day ('.$timezoneLabel.')',
        ];
    }

    private function filterOrdersByRange(Collection $orders, $from): Collection
    {
        return $orders
            ->filter(function (Order $order) use ($from): bool {
                if (! $from) {
                    return true;
                }

                $date = $this->paidDate($order);

                return $date && $date->gte($from);
            })
            ->values();
    }

    private function filterOrdersByPaymentMethod(Collection $orders, string $method): Collection
    {
        if ($method === 'all') {
            return $orders->values();
        }

        return $orders
            ->filter(fn (Order $order): bool => $order->payment_method === $method)
            ->values();
    }

    private function periodStats(Collection $orders): array
    {
        $orderIds = $orders
            ->pluck('order_id')
            ->filter()
            ->values();

        return [
            'orders' => $orders->count(),
            'licenses' => $orderIds->isEmpty() ? 0 : License::whereIn('order_id', $orderIds)->count(),
            'idr_revenue' => (int) round($orders
                ->reject(fn (Order $order) => $this->isCryptoOrder($order))
                ->sum(fn (Order $order) => (float) ($order->price ?? 0))),
            'crypto_revenue' => round((float) $orders
                ->filter(fn (Order $order) => $this->isCryptoOrder($order))
                ->sum(fn (Order $order) => $this->cryptoAmount($order)), 2),
        ];
    }

    private function salesTrend(Collection $orders, string $range, $from, $now, $firstPaidDate, string $paymentMethod): array
    {
        $bucketMode = match ($range) {
            'hourly' => 'hour',
            'weekly' => 'week',
            'monthly' => 'month',
            'lifetime' => $firstPaidDate && $firstPaidDate->diffInDays($now) <= 45 ? 'day' : 'month',
            default => 'day',
        };
        $bucketFormat = match (true) {
            $bucketMode === 'hour' => 'Y-m-d-H',
            $bucketMode === 'week' => 'o-W',
            $bucketMode === 'month' => 'Y-m',
            default => 'Y-m-d',
        };

        if ($bucketMode === 'hour') {
            $start = ($from ?: $now->copy()->startOfDay())->copy()->startOfDay();
        } elseif ($bucketMode === 'week') {
            $start = ($from ?: $now->copy()->subWeeks(7)->startOfWeek())->copy()->startOfWeek();
        } elseif ($bucketMode === 'month') {
            $start = $range === 'lifetime' && $firstPaidDate
                ? $firstPaidDate->copy()->startOfMonth()
                : ($from ?: $now->copy()->subMonths(11)->startOfMonth())->copy()->startOfMonth();
        } elseif ($range === 'lifetime' && $firstPaidDate) {
            $start = $firstPaidDate->copy()->startOfDay();
        } else {
            $start = ($from ?: $now->copy()->subDays(6)->startOfDay())->copy();
        }

        $buckets = [];
        $cursor = $start->copy();
        $end = match (true) {
            $bucketMode === 'hour' => $now->copy()->endOfDay()->startOfHour(),
            $bucketMode === 'week' => $now->copy()->startOfWeek(),
            $bucketMode === 'month' => $now->copy()->startOfMonth(),
            default => $now->copy()->startOfDay(),
        };

        while ($cursor->lte($end)) {
            $key = $cursor->format($bucketFormat);
            [$label, $shortLabel] = $this->bucketLabels($cursor, $bucketMode);

            $buckets[$key] = [
                'key' => $key,
                'label' => $label,
                'short_label' => $shortLabel,
                'orders' => 0,
                'line_revenue' => 0.0,
                'idr_revenue' => 0,
                'crypto_revenue' => 0.0,
            ];

            match (true) {
                $bucketMode === 'hour' => $cursor->addHour(),
                $bucketMode === 'week' => $cursor->addWeek(),
                $bucketMode === 'month' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
        }

        foreach ($orders as $order) {
            $date = $this->paidDate($order);

            if (! $date) {
                continue;
            }

            $key = match ($bucketMode) {
                'hour' => $date->copy()->startOfHour()->format($bucketFormat),
                'week' => $date->copy()->startOfWeek()->format($bucketFormat),
                'month' => $date->copy()->startOfMonth()->format($bucketFormat),
                default => $date->format($bucketFormat),
            };

            if (! isset($buckets[$key])) {
                continue;
            }

            $buckets[$key]['orders']++;
            $buckets[$key]['line_revenue'] += $this->chartLineValue($order, $paymentMethod);

            if ($this->isCryptoOrder($order)) {
                $buckets[$key]['crypto_revenue'] += $this->cryptoAmount($order);
            } else {
                $buckets[$key]['idr_revenue'] += (int) round((float) ($order->price ?? 0));
            }
        }

        $points = collect($buckets)->values();

        return [
            'bucket' => $bucketMode,
            'points' => $points,
            'label_step' => max(1, (int) ceil(max(1, $points->count()) / 6)),
            'line_label' => $this->chartLineLabel($paymentMethod),
            'line_format' => $this->chartLineFormat($paymentMethod),
            'max_orders' => max(1, (int) $points->max('orders')),
            'max_line_revenue' => max(1, (float) $points->max('line_revenue')),
            'max_idr_revenue' => max(1, (int) $points->max('idr_revenue')),
            'max_crypto_revenue' => max(1, (float) $points->max('crypto_revenue')),
        ];
    }

    private function bucketLabels($date, string $bucketMode): array
    {
        if ($bucketMode === 'hour') {
            return [$date->format('d M H:00'), $date->format('H:00')];
        }

        if ($bucketMode === 'week') {
            $start = $date->copy()->startOfWeek();
            $end = $date->copy()->endOfWeek();

            return [
                $start->format('d M').' - '.$end->format('d M'),
                'W'.$start->isoWeek(),
            ];
        }

        if ($bucketMode === 'month') {
            return [$date->format('M Y'), $date->format('M')];
        }

        return [$date->format('d M'), $date->format('d M')];
    }

    private function topProducts(Collection $orders): Collection
    {
        return $orders
            ->flatMap(function (Order $order) {
                return $this->orderItems($order)->map(fn ($item) => [
                    'product' => (string) ($item->product_name ?: $item->product?->name ?: $order->product?->name ?: 'Product'),
                    'quantity' => max(1, (int) $item->quantity),
                    'idr_total' => (int) ($item->line_total_idr ?? 0),
                ]);
            })
            ->groupBy('product')
            ->map(fn (Collection $rows, string $product) => [
                'product' => $product,
                'quantity' => (int) $rows->sum('quantity'),
                'idr_total' => (int) $rows->sum('idr_total'),
            ])
            ->sortByDesc('quantity')
            ->take(5)
            ->values();
    }

    private function paymentSplit(Collection $orders): Collection
    {
        return $orders
            ->groupBy(fn (Order $order) => $this->paymentMethodLabel($order->payment_method))
            ->map(fn (Collection $orders, string $method) => [
                'method' => $method,
                'orders' => $orders->count(),
            ])
            ->sortByDesc('orders')
            ->values();
    }

    private function orderItems(Order $order): Collection
    {
        if ($order->relationLoaded('items') && $order->items->isNotEmpty()) {
            return $order->items;
        }

        if (! $order->product_id || ! $order->package_id) {
            return collect();
        }

        return collect([(object) [
            'product_name' => $order->product?->name ?? 'Product',
            'product' => null,
            'quantity' => max(1, (int) $order->quantity),
            'line_total_idr' => (int) ($order->package?->price ?? 0) * max(1, (int) $order->quantity),
        ]]);
    }

    private function ordersInRange($from, string $paymentMethod = 'all')
    {
        return Order::query()
            ->when($paymentMethod !== 'all', fn ($query) => $query->where('payment_method', $paymentMethod))
            ->when($from, fn ($query) => $query->where(function ($query) use ($from) {
                $query
                    ->where('created_at', '>=', $from)
                    ->orWhere('paid_at', '>=', $from);
            }));
    }

    private function deliveryIssueCount($from = null, string $paymentMethod = 'all'): int
    {
        return $this->ordersInRange($from, $paymentMethod)
            ->where('status', 'paid')
            ->whereRaw(
                '(SELECT COUNT(*) FROM licenses WHERE licenses.order_id = orders.order_id) < COALESCE(orders.quantity, 1)'
            )
            ->count();
    }

    private function paidDate(Order $order)
    {
        $date = $order->paid_at ?: $order->created_at;

        return $date?->copy()->timezone(config('app.timezone'));
    }

    private function timezoneLabel(): string
    {
        return now()->timezone(config('app.timezone'))->format('T') ?: (string) config('app.timezone');
    }

    private function chartLineLabel(string $paymentMethod): string
    {
        return match ($paymentMethod) {
            'pakasir' => 'QRIS IDR revenue',
            'binance_pay' => 'Binance Pay crypto revenue',
            'crypto' => 'Crypto revenue',
            default => 'All order value',
        };
    }

    private function chartLineFormat(string $paymentMethod): string
    {
        return in_array($paymentMethod, ['crypto', 'binance_pay'], true) ? 'crypto' : 'idr';
    }

    private function chartLineValue(Order $order, string $paymentMethod): float
    {
        if ($paymentMethod === 'all') {
            return (float) $this->orderIdrValue($order);
        }

        if ($this->isCryptoOrder($order)) {
            return $this->cryptoAmount($order);
        }

        return (float) ($order->price ?? 0);
    }

    private function orderIdrValue(Order $order): int
    {
        $items = $this->orderItems($order);

        if ($items->isNotEmpty()) {
            return (int) $items->sum(fn ($item) => (int) ($item->line_total_idr ?? 0));
        }

        return $this->isCryptoOrder($order) ? 0 : (int) round((float) ($order->price ?? 0));
    }

    private function isCryptoOrder(Order $order): bool
    {
        return in_array($order->payment_method, ['crypto', 'binance_pay'], true);
    }

    private function cryptoAmount(Order $order): float
    {
        $payload = is_array($order->payment_payload) ? $order->payment_payload : [];

        foreach (['base_amount', 'final_amount', 'amount'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return (float) $payload[$key];
            }
        }

        return (float) ($order->price ?? 0);
    }

    private function paymentMethodLabel(?string $method): string
    {
        return match ($method) {
            'pakasir' => 'QRIS',
            'crypto' => 'Crypto',
            'binance_pay' => 'Binance Pay',
            default => ucfirst($method ?: 'Legacy'),
        };
    }
}
