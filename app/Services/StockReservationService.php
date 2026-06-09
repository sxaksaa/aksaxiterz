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

            $existing = LicenseStock::where('reserved_order_id', $order->id)
                ->where('is_sold', false)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->update([
                    'reserved_until' => $this->reservationUntil($order),
                ]);

                return $existing;
            }

            $stock = LicenseStock::query()
                ->where('product_id', $order->product_id)
                ->where('package_id', $order->package_id)
                ->available()
                ->oldest('created_at')
                ->oldest('id')
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                throw new \Exception('Automatic delivery is unavailable for this package. Please join Discord to order manually.');
            }

            $stock->update([
                'reserved_order_id' => $order->id,
                'reserved_until' => $this->reservationUntil($order),
            ]);

            return $stock;
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

    public function releaseExpiredReservations(): int
    {
        return LicenseStock::where('is_sold', false)
            ->whereNotNull('reserved_order_id')
            ->where(function ($query): void {
                $query->whereNull('reserved_until')
                    ->orWhere('reserved_until', '<=', now());
            })
            ->update([
                'reserved_order_id' => null,
                'reserved_until' => null,
            ]);
    }

    private function reservationUntil(Order $order)
    {
        $expiresAt = $order->expired_at ?: now()->addMinutes(10);
        $graceMinutes = max(0, (int) config('services.payments.reservation_grace_minutes', 20));

        return $expiresAt->copy()->addMinutes($graceMinutes);
    }
}
