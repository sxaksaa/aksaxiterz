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
            $quantity = max(1, (int) $order->quantity);
            $reservedUntil = $this->reservationUntil($order);

            $existing = LicenseStock::where('reserved_order_id', $order->id)
                ->where('is_sold', false)
                ->oldest('created_at')
                ->oldest('id')
                ->lockForUpdate()
                ->get();

            if ($existing->count() > $quantity) {
                $existing->slice($quantity)->each->update([
                    'reserved_order_id' => null,
                    'reserved_until' => null,
                ]);
                $existing = $existing->take($quantity);
            }

            if ($existing->isNotEmpty()) {
                LicenseStock::whereKey($existing->modelKeys())->update([
                    'reserved_until' => $reservedUntil,
                ]);
            }

            $needed = $quantity - $existing->count();
            $newReservations = collect();

            if ($needed > 0) {
                $newReservations = LicenseStock::query()
                    ->where('product_id', $order->product_id)
                    ->where('package_id', $order->package_id)
                    ->when($existing->isNotEmpty(), fn ($query) => $query->whereKeyNot($existing->modelKeys()))
                    ->available()
                    ->oldest('created_at')
                    ->oldest('id')
                    ->lockForUpdate()
                    ->limit($needed)
                    ->get();

                if ($newReservations->count() < $needed) {
                    throw new \Exception('Automatic delivery does not have enough license stock for this quantity.');
                }

                LicenseStock::whereKey($newReservations->modelKeys())->update([
                    'reserved_order_id' => $order->id,
                    'reserved_until' => $reservedUntil,
                ]);
            }

            return $existing->first() ?: $newReservations->firstOrFail()->fresh();
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
