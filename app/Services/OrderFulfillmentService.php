<?php

namespace App\Services;

use App\Models\License;
use App\Models\LicenseStock;
use App\Models\Order;
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
            $existingLicenses = License::where('order_id', $lockedOrder->order_id)
                ->oldest('id')
                ->lockForUpdate()
                ->get();
            $items = $lockedOrder->lineItems();
            $quantity = max(1, (int) $items->sum('quantity'));
            $deliveryComplete = $items->every(function ($item) use ($existingLicenses): bool {
                $deliveredForItem = $existingLicenses->filter(fn (License $license) => (
                    (int) $license->order_item_id === (int) $item->id ||
                    (
                        ! $license->order_item_id &&
                        (int) $license->product_id === (int) $item->product_id &&
                        $license->duration === $item->package_name
                    )
                ))->count();

                return $deliveredForItem >= (int) $item->quantity;
            });

            if ($deliveryComplete && $existingLicenses->count() >= $quantity) {
                $this->markPaidOrder($lockedOrder);

                return $existingLicenses->first();
            }

            $delivered = $existingLicenses;

            foreach ($items as $item) {
                $itemExisting = $existingLicenses->filter(fn (License $license) => (
                    (int) $license->order_item_id === (int) $item->id ||
                    (
                        ! $license->order_item_id &&
                        (int) $license->product_id === (int) $item->product_id &&
                        $license->duration === $item->package_name
                    )
                ));
                $needed = max(0, (int) $item->quantity - $itemExisting->count());

                if ($needed <= 0) {
                    continue;
                }

                $stocks = $this->stocksForItem($lockedOrder, $item, $needed);

                if ($stocks->count() < $needed) {
                    $this->releaseCompetingReservations($lockedOrder);
                    $stocks = $this->stocksForItem($lockedOrder, $item, $needed);
                }

                if (
                    $stocks->count() < $needed ||
                    $stocks->contains(fn (LicenseStock $stock) => $stock->is_sold ||
                        (int) $stock->product_id !== (int) $item->product_id ||
                        (int) $stock->package_id !== (int) $item->package_id)
                ) {
                    throw new \Exception('No license stock available for this package');
                }

                foreach ($stocks->take($needed) as $stock) {
                    $stock->update([
                        'is_sold' => true,
                        'reserved_order_id' => null,
                        'reserved_until' => null,
                        'sold_at' => now(),
                    ]);

                    $delivered->push(License::create([
                        'user_id' => $lockedOrder->user_id,
                        'product_id' => $item->product_id,
                        'license_key' => $stock->license_key,
                        'duration' => $item->package_name,
                        'order_id' => $lockedOrder->order_id,
                        'order_item_id' => $item->id,
                    ]));
                }
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
                $replacement->cartSignature() !== $order->cartSignature() ||
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
        $pairs = $order->lineItems()
            ->map(fn ($item) => [$item->product_id, $item->package_id])
            ->unique(fn ($pair) => implode(':', $pair));
        $reservedOrderIds = LicenseStock::query()
            ->where('is_sold', false)
            ->whereNotNull('reserved_order_id')
            ->where(function ($query) use ($pairs): void {
                foreach ($pairs as [$productId, $packageId]) {
                    $query->orWhere(fn ($pair) => $pair
                        ->where('product_id', $productId)
                        ->where('package_id', $packageId));
                }
            })
            ->pluck('reserved_order_id')
            ->filter()
            ->unique();

        $competitors = Order::query()
            ->where('user_id', $order->user_id)
            ->where('status', 'pending')
            ->whereKeyNot($order->id)
            ->whereIn('id', $reservedOrderIds)
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

    private function stocksForItem(Order $order, $item, int $needed)
    {
        $stocks = LicenseStock::where('reserved_order_id', $order->id)
            ->where('product_id', $item->product_id)
            ->where('package_id', $item->package_id)
            ->where('is_sold', false)
            ->oldest('created_at')
            ->oldest('id')
            ->lockForUpdate()
            ->limit($needed)
            ->get();

        if ($stocks->count() >= $needed) {
            return $stocks;
        }

        $moreStocks = LicenseStock::where('product_id', $item->product_id)
            ->where('package_id', $item->package_id)
            ->when($stocks->isNotEmpty(), fn ($query) => $query->whereKeyNot($stocks->modelKeys()))
            ->available()
            ->oldest('created_at')
            ->oldest('id')
            ->lockForUpdate()
            ->limit($needed - $stocks->count())
            ->get();

        return $stocks->concat($moreStocks);
    }
}
