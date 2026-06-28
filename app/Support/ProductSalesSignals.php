<?php

namespace App\Support;

use App\Models\OrderItem;
use Illuminate\Support\Collection;

class ProductSalesSignals
{
    public function apply(Collection $products): Collection
    {
        if ($products->isEmpty()) {
            return $products;
        }

        $allTime = $this->paidQuantities();
        $recent = $this->paidQuantities(now()->subDays(30));
        $topAllTimeId = $allTime->filter(fn ($quantity) => $quantity > 0)->keys()->first();
        $topRecentId = $recent->filter(fn ($quantity) => $quantity > 0)->keys()->first();

        $products->each(function ($product) use ($allTime, $recent, $topAllTimeId, $topRecentId): void {
            $productId = (int) $product->id;
            $allTimeQuantity = (int) ($allTime[$productId] ?? 0);
            $recentQuantity = (int) ($recent[$productId] ?? 0);
            $label = null;
            $variant = null;
            $subtitle = null;

            if ($topAllTimeId && $productId === (int) $topAllTimeId) {
                $label = 'Best Seller';
                $variant = 'best';
                $subtitle = $allTimeQuantity.' sold';
            } elseif ($topRecentId && $productId === (int) $topRecentId) {
                $label = 'Popular';
                $variant = 'popular';
                $subtitle = $recentQuantity.' sold this month';
            }

            $product->setAttribute('sales_badge_label', $label);
            $product->setAttribute('sales_badge_variant', $variant);
            $product->setAttribute('sales_badge_subtitle', $subtitle);
            $product->setAttribute('all_time_paid_quantity', $allTimeQuantity);
            $product->setAttribute('recent_paid_quantity', $recentQuantity);
        });

        return $products;
    }

    private function paidQuantities($from = null): Collection
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.status', 'paid')
            ->where('products.is_visible', true)
            ->when($from, function ($query) use ($from) {
                $query->where(function ($query) use ($from) {
                    $query->where('orders.paid_at', '>=', $from)
                        ->orWhere(function ($query) use ($from) {
                            $query->whereNull('orders.paid_at')
                                ->where('orders.updated_at', '>=', $from);
                        });
                });
            })
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as paid_quantity')
            ->groupBy('order_items.product_id')
            ->orderByDesc('paid_quantity')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->product_id => (int) $row->paid_quantity]);
    }
}
