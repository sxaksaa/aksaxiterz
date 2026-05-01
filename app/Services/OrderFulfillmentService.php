<?php

namespace App\Services;

use App\Models\License;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;

class OrderFulfillmentService
{
    public function fulfill(Order $order): License
    {
        if ($license = License::where('order_id', $order->order_id)->first()) {
            $this->markPaid($order);

            return $license;
        }

        $package = Package::findOrFail($order->package_id);

        $stock = LicenseStock::where('product_id', $order->product_id)
            ->where('package_id', $package->id)
            ->where('is_sold', false)
            ->lockForUpdate()
            ->first();

        if (! $stock || $stock->is_sold) {
            throw new \Exception('No license stock available for this package');
        }

        $stock->update([
            'is_sold' => true,
            'sold_at' => now(),
        ]);

        $this->markPaid($order);

        return License::create([
            'user_id' => $order->user_id,
            'product_id' => $order->product_id,
            'license_key' => $stock->license_key,
            'duration' => $package->name,
            'order_id' => $order->order_id,
        ]);
    }

    private function markPaid(Order $order): void
    {
        $order->update([
            'status' => 'paid',
            'paid_at' => $order->paid_at ?: now(),
        ]);
    }
}
