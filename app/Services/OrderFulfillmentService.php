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
            $quantity = max(1, (int) $lockedOrder->quantity);
            $existingLicenses = License::where('order_id', $lockedOrder->order_id)
                ->oldest('id')
                ->lockForUpdate()
                ->get();

            if ($existingLicenses->count() >= $quantity) {
                $this->markPaidOrder($lockedOrder);

                return $existingLicenses->first();
            }

            $package = Package::findOrFail($lockedOrder->package_id);
            $needed = $quantity - $existingLicenses->count();

            $stocks = LicenseStock::where('reserved_order_id', $lockedOrder->id)
                ->where('is_sold', false)
                ->oldest('created_at')
                ->oldest('id')
                ->lockForUpdate()
                ->limit($needed)
                ->get();

            if ($stocks->count() < $needed) {
                $moreStocks = LicenseStock::where('product_id', $lockedOrder->product_id)
                    ->where('package_id', $package->id)
                    ->when($stocks->isNotEmpty(), fn ($query) => $query->whereKeyNot($stocks->modelKeys()))
                    ->available()
                    ->oldest('created_at')
                    ->oldest('id')
                    ->lockForUpdate()
                    ->limit($needed - $stocks->count())
                    ->get();
                $stocks = $stocks->concat($moreStocks);
            }

            if ($stocks->count() < $needed) {
                $this->releaseCompetingReservations($lockedOrder);

                $moreStocks = LicenseStock::where('product_id', $lockedOrder->product_id)
                    ->where('package_id', $package->id)
                    ->when($stocks->isNotEmpty(), fn ($query) => $query->whereKeyNot($stocks->modelKeys()))
                    ->available()
                    ->oldest('created_at')
                    ->oldest('id')
                    ->lockForUpdate()
                    ->limit($needed - $stocks->count())
                    ->get();
                $stocks = $stocks->concat($moreStocks);
            }

            if (
                $stocks->count() < $needed ||
                $stocks->contains(fn (LicenseStock $stock) => $stock->is_sold ||
                    (int) $stock->product_id !== (int) $lockedOrder->product_id ||
                    (int) $stock->package_id !== (int) $package->id)
            ) {
                throw new \Exception('No license stock available for this package');
            }

            $delivered = $existingLicenses;

            foreach ($stocks->take($needed) as $stock) {
                $stock->update([
                    'is_sold' => true,
                    'reserved_order_id' => null,
                    'reserved_until' => null,
                    'sold_at' => now(),
                ]);

                $delivered->push(License::create([
                    'user_id' => $lockedOrder->user_id,
                    'product_id' => $lockedOrder->product_id,
                    'license_key' => $stock->license_key,
                    'duration' => $package->name,
                    'order_id' => $lockedOrder->order_id,
                ]));
            }

            $this->markPaidOrder($lockedOrder);

            return $delivered->first();
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
