<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

class PendingOrderExpirationService
{
    public function __construct(
        private readonly StockReservationService $stockReservationService
    ) {}

    public function expire(?int $userId = null, int $limit = 500): array
    {
        $now = now();
        $cryptoCutoff = $now->copy()->subMinutes($this->cryptoGraceMinutes());
        $staleCutoff = $now->copy()->subHours($this->stalePendingHours());

        $orders = Order::query()
            ->where('status', 'pending')
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->where(function ($query) use ($now, $cryptoCutoff, $staleCutoff): void {
                $query->where(function ($withExpiry) use ($now, $cryptoCutoff): void {
                    $withExpiry->whereNotNull('expired_at')
                        ->where(function ($deadline) use ($now, $cryptoCutoff): void {
                            $deadline->where(function ($nonCrypto) use ($now): void {
                                $nonCrypto->where(function ($method): void {
                                    $method->whereNull('payment_method')
                                        ->orWhere('payment_method', '!=', 'crypto');
                                })->where('expired_at', '<=', $now);
                            })->orWhere(function ($crypto) use ($cryptoCutoff): void {
                                $crypto->where('payment_method', 'crypto')
                                    ->where('expired_at', '<=', $cryptoCutoff);
                            });
                        });
                })->orWhere(function ($missingExpiry) use ($staleCutoff): void {
                    $missingExpiry->whereNull('expired_at')
                        ->where('created_at', '<=', $staleCutoff);
                });
            })
            ->oldest('expired_at')
            ->limit(max(1, $limit))
            ->get();

        $summary = [
            'cancelled' => 0,
            'other' => 0,
            'gopay_qris' => 0,
            'crypto' => 0,
            'binance_pay' => 0,
        ];

        foreach ($orders as $order) {
            DB::transaction(function () use ($order, $now, &$summary): void {
                $lockedOrder = Order::whereKey($order->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedOrder || ! $this->shouldExpire($lockedOrder, $now)) {
                    return;
                }

                $lockedOrder->update(['status' => 'cancelled']);
                $this->stockReservationService->release($lockedOrder);

                $method = match ($lockedOrder->payment_method) {
                    'crypto' => 'crypto',
                    'binance_pay' => 'binance_pay',
                    'gopay_qris' => 'gopay_qris',
                    default => 'other',
                };
                $summary['cancelled']++;
                $summary[$method]++;
            });
        }

        return $summary;
    }

    private function shouldExpire(Order $order, $now): bool
    {
        if ($order->status !== 'pending') {
            return false;
        }

        if (! $order->expired_at) {
            return $order->created_at &&
                $order->created_at->copy()->addHours($this->stalePendingHours())->lte($now);
        }

        $deadline = $order->expired_at->copy();

        if ($order->payment_method === 'crypto') {
            $deadline->addMinutes($this->cryptoGraceMinutes());
        }

        return $deadline->lte($now);
    }

    private function cryptoGraceMinutes(): int
    {
        return max(0, (int) config('services.crypto_direct.grace_minutes', 2));
    }

    private function stalePendingHours(): int
    {
        return max(1, (int) config('services.payments.stale_pending_hours', 24));
    }
}
