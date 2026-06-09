<?php

namespace App\Services;

use App\Models\License;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use Illuminate\Support\Facades\DB;

class OrderFulfillmentService
{
    public function fulfill(Order $order): License
    {
        return DB::transaction(function () use ($order): License {
            $lockedOrder = Order::whereKey($order->id)
                ->lockForUpdate()
                ->first() ?: $order;

            if ($license = License::where('order_id', $lockedOrder->order_id)->first()) {
                $this->markPaid($lockedOrder);

                return $license;
            }

            $package = Package::findOrFail($lockedOrder->package_id);

            $stock = LicenseStock::where('reserved_order_id', $lockedOrder->id)
                ->where('is_sold', false)
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                $stock = LicenseStock::where('product_id', $lockedOrder->product_id)
                    ->where('package_id', $package->id)
                    ->available()
                    ->oldest('created_at')
                    ->oldest('id')
                    ->lockForUpdate()
                    ->first();
            }

            if (
                ! $stock ||
                $stock->is_sold ||
                (int) $stock->product_id !== (int) $lockedOrder->product_id ||
                (int) $stock->package_id !== (int) $package->id
            ) {
                throw new \Exception('No license stock available for this package');
            }

            $stock->update([
                'is_sold' => true,
                'reserved_order_id' => null,
                'reserved_until' => null,
                'sold_at' => now(),
            ]);

            $this->markPaid($lockedOrder);

            return License::create([
                'user_id' => $lockedOrder->user_id,
                'product_id' => $lockedOrder->product_id,
                'license_key' => $stock->license_key,
                'duration' => $package->name,
                'order_id' => $lockedOrder->order_id,
            ]);
        });
    }

    public function markPaid(Order $order): void
    {
        $order->update([
            'status' => 'paid',
            'paid_at' => $order->paid_at ?: now(),
        ]);
    }
}
