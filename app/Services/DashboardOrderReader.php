<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardOrderReader
{
    /** Read report values without hydrating models, casts and nested relations per order. */
    public function read(Builder $query): Collection
    {
        $orders = (clone $query)
            ->select(['id', 'order_id', 'product_id', 'package_id', 'payment_method', 'price', 'quantity', 'paid_at', 'created_at'])
            ->selectRaw('CASE WHEN payment_method IN (?, ?) THEN payment_payload ELSE NULL END AS payment_payload', ['crypto', 'binance_pay'])
            ->toBase()->get();

        if ($orders->isEmpty()) {
            return $orders;
        }

        $items = DB::table('order_items as items')
            ->leftJoin('products', 'products.id', '=', 'items.product_id')
            ->whereIntegerInRaw('items.order_id', $orders->pluck('id')->all())
            ->select('items.order_id', 'items.product_name', 'items.quantity', 'items.line_total_idr', 'products.name as current_product_name')
            ->get()->each(function ($item): void {
                $item->product = $item->current_product_name !== null ? (object) ['name' => $item->current_product_name] : null;
            })->groupBy('order_id');
        $products = DB::table('products')->whereIntegerInRaw('id', $orders->pluck('product_id')->filter()->unique()->all())->pluck('name', 'id');
        $legacyPackageIds = $orders->filter(fn ($order) => ! $items->has($order->id))->pluck('package_id')->filter()->unique()->all();
        $prices = $legacyPackageIds === [] ? collect() : DB::table('packages')->whereIntegerInRaw('id', $legacyPackageIds)->pluck('price', 'id');

        foreach ($orders as $order) {
            $order->items = $items->get($order->id, collect());
            $order->product = $products->has($order->product_id) ? (object) ['name' => $products->get($order->product_id)] : null;
            $order->package = $prices->has($order->package_id) ? (object) ['price' => $prices->get($order->package_id)] : null;
            $order->payment_payload = is_string($order->payment_payload) ? json_decode($order->payment_payload, true) : [];
        }

        return $orders;
    }
}
