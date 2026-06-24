<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class RecentPurchaseFeed
{
    public function storefront(?Product $product = null, int $limit = 6): Collection
    {
        $orders = Order::query()
            ->with(['items.product', 'items.package', 'package', 'product', 'user'])
            ->where('status', 'paid')
            ->when($product, function ($query) use ($product) {
                $query->where(function ($query) use ($product) {
                    $query->where('product_id', $product->id)
                        ->orWhereHas('items', fn ($query) => $query->where('product_id', $product->id));
                });
            })
            ->orderByRaw('COALESCE(paid_at, updated_at) DESC')
            ->latest('id')
            ->limit($limit * 3)
            ->get();

        return $orders
            ->flatMap(fn (Order $order) => $this->presentOrder($order, $product))
            ->take($limit)
            ->values();
    }

    private function presentOrder(Order $order, ?Product $filterProduct = null): Collection
    {
        return $order->lineItems()
            ->filter(function (OrderItem $item) use ($filterProduct) {
                return ! $filterProduct || (int) $item->product_id === (int) $filterProduct->id;
            })
            ->map(function (OrderItem $item) use ($order) {
                $paidAt = $order->paid_at ?: $order->updated_at ?: $order->created_at;
                $productName = $item->product_name ?: $item->product?->name ?: $order->product?->name ?: 'Product';
                $packageName = $item->package_name ?: $item->package?->name ?: $order->package?->name ?: 'License';

                return [
                    'buyer' => $this->maskedBuyer($order->user?->name ?: $order->user?->email),
                    'product' => $productName,
                    'package' => $this->normalizedPackageName($packageName),
                    'quantity' => max(1, (int) $item->quantity),
                    'ago' => $this->shortRelativeTime($paidAt),
                    'paid_at' => $paidAt?->toIso8601String(),
                ];
            });
    }

    private function maskedBuyer(?string $value): string
    {
        $name = trim((string) $value);

        if (str_contains($name, '@')) {
            $name = Str::before($name, '@');
        }

        $name = preg_replace('/\s+/', ' ', $name) ?: '';
        $name = trim($name);

        if ($name === '') {
            return 'Customer';
        }

        $firstName = (string) Str::of($name)->explode(' ')->filter()->first();
        $length = Str::length($firstName);

        if ($length <= 1) {
            return Str::upper($firstName).'***';
        }

        $first = Str::upper(Str::substr($firstName, 0, 1));
        $last = Str::substr($firstName, -1);
        $mask = str_repeat('*', min(6, max(3, $length - 2)));

        return $first.$mask.$last;
    }

    private function normalizedPackageName(string $name): string
    {
        $name = preg_replace_callback('/(\d+)\s*hari/i', function (array $matches) {
            $days = (int) $matches[1];

            return $days.' '.Str::plural('Day', $days);
        }, $name) ?: $name;

        return trim($name) ?: 'License';
    }

    private function shortRelativeTime($date): string
    {
        if (! $date) {
            return 'recently';
        }

        $seconds = max(0, now()->getTimestamp() - $date->getTimestamp());

        if ($seconds < 60) {
            return 'just now';
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return $minutes.'m ago';
        }

        $hours = intdiv($minutes, 60);

        if ($hours < 24) {
            return $hours.'h ago';
        }

        $days = intdiv($hours, 24);

        if ($days < 14) {
            return $days.'d ago';
        }

        return $date->timezone(config('app.timezone'))->format('d M');
    }
}
