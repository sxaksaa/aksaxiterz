<?php

namespace App\Services;

use App\Models\LicenseStock;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class StockReservationService
{
    public function reserve(Order $order): LicenseStock
    {
        return DB::transaction(function () use ($order): LicenseStock {
            $this->releaseExpiredReservations();
            $reservedUntil = $this->reservationUntil($order);
            $requirements = $order->lineItems()
                ->groupBy(fn ($item) => $item->product_id.':'.$item->package_id)
                ->map(fn ($items) => [
                    'product_id' => (int) $items->first()->product_id,
                    'package_id' => (int) $items->first()->package_id,
                    'quantity' => (int) $items->sum('quantity'),
                ])
                ->values();

            $existing = LicenseStock::where('reserved_order_id', $order->id)
                ->where('is_sold', false)
                ->oldest('created_at')
                ->oldest('id')
                ->lockForUpdate()
                ->get();
            $keptIds = collect();

            foreach ($requirements as $requirement) {
                $matching = $existing
                    ->where('product_id', $requirement['product_id'])
                    ->where('package_id', $requirement['package_id'])
                    ->take($requirement['quantity']);
                $keptIds = $keptIds->concat($matching->modelKeys());
            }

            $releaseIds = $existing->pluck('id')->diff($keptIds);

            if ($releaseIds->isNotEmpty()) {
                LicenseStock::whereKey($releaseIds)->update([
                    'reserved_order_id' => null,
                    'reserved_until' => null,
                ]);
            }

            if ($keptIds->isNotEmpty()) {
                LicenseStock::whereKey($keptIds)->update(['reserved_until' => $reservedUntil]);
            }

            foreach ($requirements as $requirement) {
                $keptForItem = $existing
                    ->whereIn('id', $keptIds)
                    ->where('product_id', $requirement['product_id'])
                    ->where('package_id', $requirement['package_id'])
                    ->count();
                $needed = $requirement['quantity'] - $keptForItem;

                if ($needed <= 0) {
                    continue;
                }

                $stocks = LicenseStock::query()
                    ->where('product_id', $requirement['product_id'])
                    ->where('package_id', $requirement['package_id'])
                    ->where('is_sold', false)
                    ->where(fn ($query) => $query->available())
                    ->oldest('created_at')
                    ->oldest('id')
                    ->lockForUpdate()
                    ->limit($needed)
                    ->get();

                if ($stocks->count() < $needed) {
                    throw new \Exception('Automatic delivery does not have enough license stock for every cart item.');
                }

                LicenseStock::whereKey($stocks->modelKeys())->update([
                    'reserved_order_id' => $order->id,
                    'reserved_until' => $reservedUntil,
                ]);
                $keptIds = $keptIds->concat($stocks->modelKeys());
            }

            return LicenseStock::whereKey($keptIds->first())->firstOrFail();
        });
    }

    public function release(Order $order): void
    {
        LicenseStock::where('reserved_order_id', $order->id)
            ->where('is_sold', false)
            ->update([
                'reserved_order_id' => null,
                'reserved_until' => null,
            ]);
    }

    public function holdFor(Order $order, int $minutes): void
    {
        LicenseStock::where('reserved_order_id', $order->id)
            ->where('is_sold', false)
            ->update([
                'reserved_until' => now()->addMinutes(max(1, $minutes)),
            ]);
    }

    public function releaseExpiredReservations(): int
    {
        return LicenseStock::where('is_sold', false)
            ->whereNotNull('reserved_order_id')
            ->where(function ($query): void {
                $query->whereDoesntHave('reservedOrder')
                    ->orWhereHas('reservedOrder', fn ($order) => $order->where('status', 'cancelled'))
                    ->orWhere(function ($expiredCrypto): void {
                        $expiredCrypto->where('reserved_until', '<=', now())
                            ->whereHas('reservedOrder', function ($order): void {
                                $order->where('status', 'pending')
                                    ->where('payment_method', 'crypto');
                            });
                    });
            })
            ->update([
                'reserved_order_id' => null,
                'reserved_until' => null,
            ]);
    }

    private function reservationUntil(Order $order)
    {
        $expiresAt = $order->expired_at ?: now()->addMinutes(5);
        $graceMinutes = max(0, (int) config('services.payments.reservation_grace_minutes', 0));

        return $expiresAt->copy()->addMinutes($graceMinutes);
    }
}
