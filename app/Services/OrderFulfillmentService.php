<?php

namespace App\Services;

use App\Models\License;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use Illuminate\Support\Facades\DB;

class OrderFulfillmentService
{
    public function __construct(
        private readonly StockReservationService $stockReservationService
    ) {}

    public function fulfill(Order $order): License
    {
        return DB::transaction(function () use ($order): License {
            $lockedOrder = Order::whereKey($order->id)
                ->lockForUpdate()
                ->first() ?: $order;

            $this->cancelPendingReplacements($lockedOrder);

            if ($license = License::where('order_id', $lockedOrder->order_id)->first()) {
                $this->markPaidOrder($lockedOrder);

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

            if (! $stock) {
                $this->releaseCompetingReservations($lockedOrder);

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

            $this->markPaidOrder($lockedOrder);

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
        DB::transaction(function () use ($order): void {
            $lockedOrder = Order::whereKey($order->id)
                ->lockForUpdate()
                ->first() ?: $order;

            $this->cancelPendingReplacements($lockedOrder);
            $this->markPaidOrder($lockedOrder);
        });
    }

    private function cancelPendingReplacements(Order $order): void
    {
        $replacementId = (int) $order->replaced_by;
        $visited = [(int) $order->id => true];

        for ($depth = 0; $replacementId > 0 && $depth < 20; $depth++) {
            if (isset($visited[$replacementId])) {
                break;
            }

            $visited[$replacementId] = true;
            $replacement = Order::whereKey($replacementId)
                ->lockForUpdate()
                ->first();

            if (
                ! $replacement ||
                (int) $replacement->user_id !== (int) $order->user_id ||
                (int) $replacement->product_id !== (int) $order->product_id ||
                (int) $replacement->package_id !== (int) $order->package_id ||
                ! in_array($replacement->status, ['pending', 'cancelled'], true)
            ) {
                break;
            }

            $replacementId = (int) $replacement->replaced_by;

            if ($replacement->status === 'pending') {
                $replacement->update(['status' => 'cancelled']);
            }

            $this->stockReservationService->release($replacement);
        }
    }

    private function releaseCompetingReservations(Order $order): void
    {
        $competitors = Order::query()
            ->where('user_id', $order->user_id)
            ->where('product_id', $order->product_id)
            ->where('package_id', $order->package_id)
            ->where('status', 'pending')
            ->whereKeyNot($order->id)
            ->whereIn('id', LicenseStock::query()
                ->select('reserved_order_id')
                ->where('is_sold', false)
                ->whereNotNull('reserved_order_id'))
            ->oldest('id')
            ->lockForUpdate()
            ->get();

        foreach ($competitors as $competitor) {
            $competitor->update(['status' => 'cancelled']);
            $this->stockReservationService->release($competitor);
        }
    }

    private function markPaidOrder(Order $order): void
    {
        $order->update([
            'status' => 'paid',
            'paid_at' => $order->paid_at ?: now(),
        ]);
    }
}
