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
        $activeRange = $this->normalizeRange((string) $request->query('range', '7'));

        $paidOrders = Order::query()
            ->with(['items', 'product', 'package'])
            ->where('status', 'paid')
            ->get(['id', 'order_id', 'product_id', 'package_id', 'status', 'payment_method', 'price', 'quantity', 'payment_payload', 'paid_at', 'created_at']);

        $firstPaidDate = $paidOrders
            ->map(fn (Order $order) => $this->paidDate($order))
            ->filter()
            ->sortBy(fn ($date) => $date->timestamp)
            ->first();

        $rangeMeta = $this->rangeMeta($activeRange, $now, $firstPaidDate);
        $filteredPaidOrders = $this->filterOrdersByRange($paidOrders, $rangeMeta['from']);
        $selectedStats = $this->periodStats($filteredPaidOrders);
        $salesTrend = $this->salesTrend($filteredPaidOrders, $activeRange, $rangeMeta['from'], $now, $firstPaidDate);

        $metricCards = [
            [
                'label' => 'IDR Revenue',
                'value' => $selectedStats['idr_revenue'],
                'format' => 'idr',
                'caption' => $rangeMeta['label'],
                'icon' => 'circle-dollar-sign',
            ],
            [
                'label' => 'Crypto Revenue',
                'value' => $selectedStats['crypto_revenue'],
                'format' => 'crypto',
                'caption' => $rangeMeta['label'],
                'icon' => 'wallet',
            ],
            [
                'label' => 'Paid Orders',
                'value' => $selectedStats['orders'],
                'format' => 'count',
                'caption' => $rangeMeta['label'],
                'icon' => 'receipt',
            ],
            [
                'label' => 'Licenses Sold',
                'value' => $selectedStats['licenses'],
                'format' => 'count',
                'caption' => $rangeMeta['label'],
                'icon' => 'key-round',
            ],
        ];

        $lowStockThreshold = (int) config('admin.low_stock_threshold', 3);
        $lowStockPackages = Package::query()
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
            'pending' => $this->ordersInRange($rangeMeta['from'])->where('status', 'pending')->count(),
            'paid' => $this->ordersInRange($rangeMeta['from'])->where('status', 'paid')->count(),
            'cancelled' => $this->ordersInRange($rangeMeta['from'])->where('status', 'cancelled')->count(),
            'delivery_issues' => $this->deliveryIssueCount($rangeMeta['from']),
        ];

        $topProducts = $this->topProducts($filteredPaidOrders);
        $paymentSplit = $this->paymentSplit($filteredPaidOrders);

        $recentOrders = $this->ordersInRange($rangeMeta['from'])
            ->with(['user', 'product', 'package', 'items'])
            ->withCount('licenses')
            ->latest()
            ->take(8)
            ->get();

        $lowStockPackages = $lowStockPackages->take(8)->values();

        return view('admin.dashboard.index', compact(
            'activeRange',
            'rangeOptions',
            'rangeMeta',
            'metricCards',
            'selectedStats',
            'salesTrend',
            'stockStats',
            'orderStats',
            'topProducts',
            'paymentSplit',
            'recentOrders',
            'lowStockPackages',
            'lowStockThreshold'
        ));
    }

    private function rangeOptions(): array
    {
        return [
            '1' => ['label' => 'Today', 'short' => '1D'],
            '7' => ['label' => '7 Days', 'short' => '7D'],
            '30' => ['label' => '30 Days', 'short' => '30D'],
            'all' => ['label' => 'Lifetime', 'short' => 'All'],
        ];
    }

    private function normalizeRange(string $range): string
    {
        return array_key_exists($range, $this->rangeOptions()) ? $range : '7';
    }

    private function rangeMeta(string $range, $now, $firstPaidDate): array
    {
        if ($range === '1') {
            $from = $now->copy()->startOfDay();

            return [
                'range' => $range,
                'label' => 'Today',
                'from' => $from,
                'description' => $from->format('d M Y'),
            ];
        }

        if ($range === '30') {
            $from = $now->copy()->subDays(29)->startOfDay();

            return [
                'range' => $range,
                'label' => 'Last 30 days',
                'from' => $from,
                'description' => $from->format('d M Y').' - '.$now->format('d M Y'),
            ];
        }

        if ($range === 'all') {
            return [
                'range' => $range,
                'label' => 'Lifetime',
                'from' => null,
                'description' => $firstPaidDate
                    ? 'Since '.$firstPaidDate->format('d M Y')
                    : 'No paid orders yet',
            ];
        }

        $from = $now->copy()->subDays(6)->startOfDay();

        return [
            'range' => '7',
            'label' => 'Last 7 days',
            'from' => $from,
            'description' => $from->format('d M Y').' - '.$now->format('d M Y'),
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

    private function salesTrend(Collection $orders, string $range, $from, $now, $firstPaidDate): array
    {
        $useMonthlyBuckets = $range === 'all' && $firstPaidDate && $firstPaidDate->diffInDays($now) > 45;
        $bucketFormat = $useMonthlyBuckets ? 'Y-m' : 'Y-m-d';
        $labelFormat = $useMonthlyBuckets ? 'M y' : 'd M';
        $shortLabelFormat = $useMonthlyBuckets ? 'M' : 'd M';

        if ($useMonthlyBuckets) {
            $start = $firstPaidDate->copy()->startOfMonth();
        } elseif ($range === 'all' && $firstPaidDate) {
            $start = $firstPaidDate->copy()->startOfDay();
        } else {
            $start = ($from ?: $now->copy()->subDays(6)->startOfDay())->copy();
        }

        $buckets = [];
        $cursor = $start->copy();
        $end = $useMonthlyBuckets ? $now->copy()->startOfMonth() : $now->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $key = $cursor->format($bucketFormat);
            $buckets[$key] = [
                'key' => $key,
                'label' => $cursor->format($labelFormat),
                'short_label' => $cursor->format($shortLabelFormat),
                'orders' => 0,
                'idr_revenue' => 0,
                'crypto_revenue' => 0.0,
            ];

            $useMonthlyBuckets ? $cursor->addMonth() : $cursor->addDay();
        }

        foreach ($orders as $order) {
            $date = $this->paidDate($order);

            if (! $date) {
                continue;
            }

            $key = $date->format($bucketFormat);

            if (! isset($buckets[$key])) {
                continue;
            }

            $buckets[$key]['orders']++;

            if ($this->isCryptoOrder($order)) {
                $buckets[$key]['crypto_revenue'] += $this->cryptoAmount($order);
            } else {
                $buckets[$key]['idr_revenue'] += (int) round((float) ($order->price ?? 0));
            }
        }

        $points = collect($buckets)->values();

        return [
            'bucket' => $useMonthlyBuckets ? 'month' : 'day',
            'points' => $points,
            'label_step' => max(1, (int) ceil(max(1, $points->count()) / 6)),
            'max_orders' => max(1, (int) $points->max('orders')),
            'max_idr_revenue' => max(1, (int) $points->max('idr_revenue')),
            'max_crypto_revenue' => max(1, (float) $points->max('crypto_revenue')),
        ];
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

    private function ordersInRange($from)
    {
        return Order::query()
            ->when($from, fn ($query) => $query->where(function ($query) use ($from) {
                $query
                    ->where('created_at', '>=', $from)
                    ->orWhere('paid_at', '>=', $from);
            }));
    }

    private function deliveryIssueCount($from = null): int
    {
        return $this->ordersInRange($from)
            ->where('status', 'paid')
            ->whereRaw(
                '(SELECT COUNT(*) FROM licenses WHERE licenses.order_id = orders.order_id) < COALESCE(orders.quantity, 1)'
            )
            ->count();
    }

    private function paidDate(Order $order)
    {
        return $order->paid_at ?: $order->created_at;
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
