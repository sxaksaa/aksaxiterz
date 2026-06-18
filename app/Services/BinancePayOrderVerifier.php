<?php

namespace App\Services;

use App\Models\License;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BinancePayOrderVerifier
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly OrderFulfillmentService $orderFulfillmentService,
        private readonly StockReservationService $stockReservationService
    ) {}

    public function verify(Order $order): array
    {
        if ($order->exists) {
            $order->refresh();
        }

        if (! $this->isBinancePayOrder($order)) {
            return [
                'order_id' => $order->order_id,
                'status' => $order->status,
                'message' => 'This order is not a Binance Pay checkout.',
            ];
        }

        if ($order->status === 'paid') {
            return $this->withLicensePayload([
                'order_id' => $order->order_id,
                'status' => 'paid',
            ], $order);
        }

        if (! in_array($order->status, ['pending', 'cancelled'], true)) {
            return [
                'order_id' => $order->order_id,
                'status' => $order->status,
                'message' => 'This Binance Pay order is no longer active.',
            ];
        }

        if ($this->isPastRecoveryPeriod($order)) {
            $order = $this->cancelPendingOrder($order);

            return [
                'order_id' => $order->order_id,
                'status' => $order->status,
                'message' => 'The automatic Binance Pay verification window has ended. Please contact support with the transaction receipt.',
            ];
        }

        $this->scanPending(100, $order->id);
        $order->refresh();

        if ($order->status === 'paid') {
            return $this->withLicensePayload([
                'order_id' => $order->order_id,
                'status' => 'paid',
            ], $order);
        }

        if ($order->status === 'pending' && $this->isPastGracePeriod($order)) {
            $order = $this->cancelPendingOrder($order);
        }

        return [
            'order_id' => $order->order_id,
            'status' => $order->status,
            'message' => $order->status === 'cancelled'
                ? 'No matching Binance Pay transfer was found before the invoice deadline. If you already paid, retry verification during the recovery window.'
                : 'Binance Pay is still being verified. Send the exact amount in the same token to the Pay ID shown.',
        ];
    }

    public function scanPending(int $limit = 100, ?int $includeOrderId = null): array
    {
        try {
            return Cache::lock('payment-scan:binance-pay', 55)
                ->block(0, fn () => $this->scanPendingUnlocked($limit, $includeOrderId));
        } catch (LockTimeoutException) {
            return [
                'checked' => 0,
                'paid' => 0,
                'pending' => 0,
                'skipped' => true,
            ];
        }
    }

    private function scanPendingUnlocked(int $limit, ?int $includeOrderId): array
    {
        $summary = [
            'checked' => 0,
            'paid' => 0,
            'pending' => 0,
            'skipped' => false,
        ];

        $cooldownSeconds = max(5, (int) config('services.binance.pay.scan_cooldown_seconds', 20));

        if (Cache::has('payment-scan:binance-pay:cooldown')) {
            $summary['skipped'] = true;

            return $summary;
        }

        $orders = Order::query()
            ->where('payment_method', 'binance_pay')
            ->whereIn('status', ['pending', 'cancelled'])
            ->where(function ($query): void {
                $query->whereNull('expired_at')
                    ->orWhere('expired_at', '>', now()->subHours($this->recoveryHours()));
            })
            ->oldest()
            ->limit(max(1, $limit))
            ->get()
            ->filter(fn (Order $order) => $this->isBinancePayOrder($order))
            ->values();

        if ($includeOrderId && ! $orders->contains('id', $includeOrderId)) {
            $included = Order::whereKey($includeOrderId)->first();

            if ($included && $this->isBinancePayOrder($included)) {
                $orders->push($included);
            }
        }

        if ($orders->isEmpty()) {
            return $summary;
        }

        $startAt = $orders
            ->map(fn (Order $order) => $order->created_at)
            ->filter()
            ->min()
            ?->copy()
            ->subMinutes(5) ?: now()->subDay();

        try {
            $history = $this->paymentService->getBinancePayTransactions(
                $startAt,
                now()->addMinutes(5)
            );
            Cache::put('payment-scan:binance-pay:cooldown', true, $cooldownSeconds);
        } catch (\Throwable $error) {
            Cache::put('payment-scan:binance-pay:cooldown', true, min(10, $cooldownSeconds));

            Log::warning('BINANCE PAY VERIFY ERROR: '.$error->getMessage());

            foreach ($orders as $order) {
                $this->rememberInspection($order, [
                    'status' => 'request_failed',
                    'returned_records' => 0,
                ]);
            }

            return $summary + ['error' => true];
        }

        $transactions = collect($history['transactions'] ?? []);
        $diagnostics = is_array($history['diagnostics'] ?? null)
            ? $history['diagnostics']
            : ['status' => 'request_succeeded', 'returned_records' => $transactions->count()];

        foreach ($orders as $order) {
            $summary['checked']++;
            $transaction = $this->matchingTransaction($order, $transactions);

            if ($transaction && $this->completeOrder($order, $transaction)) {
                $summary['paid']++;
            } else {
                $this->rememberInspection($order, $diagnostics);
                $summary['pending']++;
            }
        }

        return $summary;
    }

    private function matchingTransaction(Order $order, Collection $transactions): ?array
    {
        $payload = $order->payment_payload;
        $expectedAmount = $this->normalizeAmount($payload['amount'] ?? null);
        $expectedToken = strtoupper(trim((string) ($payload['token'] ?? 'USDT')));
        $earliestMs = ($order->created_at?->copy()->subMinutes(5)->getTimestampMs()) ?: 0;
        $latestMs = $order->expired_at
            ? $order->expired_at->copy()->addMinutes($this->graceMinutes())->getTimestampMs()
            : now()->addMinutes(5)->getTimestampMs();

        if ($expectedAmount === null || $expectedToken === '') {
            return null;
        }

        foreach ($transactions as $transaction) {
            if (! is_array($transaction)) {
                continue;
            }

            $reference = strtolower(trim((string) ($transaction['transactionId'] ?? '')));
            $amount = $this->normalizeAmount($transaction['amount'] ?? null);
            $token = strtoupper(trim((string) ($transaction['currency'] ?? '')));
            $orderType = strtoupper(trim((string) ($transaction['orderType'] ?? '')));
            $timestampMs = (int) ($transaction['transactionTime'] ?? 0);

            if (
                $reference === '' ||
                $amount === null ||
                $amount[0] === '-' ||
                $amount !== $expectedAmount ||
                $token !== $expectedToken ||
                $orderType !== 'C2C' ||
                $timestampMs < $earliestMs ||
                $timestampMs > $latestMs ||
                Order::where('payment_reference', $reference)->whereKeyNot($order->id)->exists()
            ) {
                continue;
            }

            return [
                'transaction_id' => $reference,
                'transaction_time' => $timestampMs,
                'amount' => $amount,
                'token' => $token,
                'order_type' => $orderType,
                'payer_name' => trim((string) data_get($transaction, 'payerInfo.name')),
            ];
        }

        return null;
    }

    private function completeOrder(Order $order, array $transaction): bool
    {
        try {
            return DB::transaction(function () use ($order, $transaction): bool {
                $lockedOrder = Order::whereKey($order->id)
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $lockedOrder ||
                    ! $this->isBinancePayOrder($lockedOrder) ||
                    ! in_array($lockedOrder->status, ['pending', 'cancelled'], true) ||
                    $this->isPastRecoveryPeriod($lockedOrder)
                ) {
                    return false;
                }

                $reference = strtolower(trim((string) ($transaction['transaction_id'] ?? '')));

                if (
                    $reference === '' ||
                    blank($lockedOrder->payment_match_key) ||
                    Order::where('payment_reference', $reference)
                        ->whereKeyNot($lockedOrder->id)
                        ->exists()
                ) {
                    return false;
                }

                $payload = is_array($lockedOrder->payment_payload)
                    ? $lockedOrder->payment_payload
                    : [];
                $payload['scanner_status'] = 'matched';
                $payload['last_checked_at'] = now()->toIso8601String();
                $payload['transaction_id'] = $reference;
                $payload['confirmed_at'] = Carbon::createFromTimestampMs(
                    (int) $transaction['transaction_time']
                )->toIso8601String();
                $payload['payer_name'] = $transaction['payer_name'] ?: null;
                $payload['order_type'] = $transaction['order_type'];

                $lockedOrder->update([
                    'payment_payload' => $payload,
                    'payment_reference' => $reference,
                    'payment_match_key' => null,
                ]);

                try {
                    $this->orderFulfillmentService->fulfill($lockedOrder);
                } catch (\Exception $fulfillmentError) {
                    if ($fulfillmentError->getMessage() !== 'No license stock available for this package') {
                        throw $fulfillmentError;
                    }

                    $this->orderFulfillmentService->markPaid($lockedOrder);
                }

                return true;
            });
        } catch (\Throwable $error) {
            Log::warning('BINANCE PAY MATCH ERROR: '.$error->getMessage(), [
                'order_id' => $order->order_id,
            ]);

            return false;
        }
    }

    private function rememberInspection(Order $order, array $diagnostics): void
    {
        DB::transaction(function () use ($order, $diagnostics): void {
            $lockedOrder = Order::whereKey($order->id)
                ->lockForUpdate()
                ->first();

            if (
                ! $lockedOrder ||
                ! in_array($lockedOrder->status, ['pending', 'cancelled'], true) ||
                filled($lockedOrder->payment_reference)
            ) {
                return;
            }

            $payload = is_array($lockedOrder->payment_payload)
                ? $lockedOrder->payment_payload
                : [];
            $payload['scanner_status'] = 'pending';
            $payload['last_checked_at'] = now()->toIso8601String();
            $payload['binance_pay_diagnostics'] = $diagnostics;

            $lockedOrder->update(['payment_payload' => $payload]);
        });
    }

    private function normalizeAmount(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            return null;
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        $fraction = rtrim($fraction, '0');
        $normalized = ($whole === '' ? '0' : $whole).($fraction === '' ? '' : '.'.$fraction);

        return $negative && $normalized !== '0' ? '-'.$normalized : $normalized;
    }

    private function withLicensePayload(array $payload, Order $order): array
    {
        $licenses = License::where('order_id', $order->order_id)->oldest('id')->get();
        $payload['quantity'] = max(1, (int) $order->quantity);
        $payload['delivered_count'] = $licenses->count();

        if ($licenses->isNotEmpty()) {
            $payload['license_key'] = $licenses->first()->license_key;
            $payload['license_keys'] = $licenses->pluck('license_key')->all();
        }

        if ($licenses->count() < $payload['quantity']) {
            $payload['delivery_pending'] = true;
            $payload['message'] = 'Payment is verified, but automatic delivery could not provide every license key. Please join Discord for manual delivery.';
        }

        return $payload;
    }

    private function isBinancePayOrder(Order $order): bool
    {
        $payload = $order->payment_payload;

        return $order->payment_method === 'binance_pay' &&
            is_array($payload) &&
            ($payload['type'] ?? null) === 'binance_pay_personal';
    }

    private function isPastGracePeriod(Order $order): bool
    {
        return $order->expired_at &&
            $order->expired_at->copy()->addMinutes($this->graceMinutes())->lte(now());
    }

    private function isPastRecoveryPeriod(Order $order): bool
    {
        return $order->expired_at &&
            $order->expired_at->copy()->addHours($this->recoveryHours())->lte(now());
    }

    private function graceMinutes(): int
    {
        return max(0, (int) config('services.binance.pay.grace_minutes', 2));
    }

    private function recoveryHours(): int
    {
        return max(1, (int) config('services.binance.pay.recovery_hours', 24));
    }

    private function cancelPendingOrder(Order $order): Order
    {
        $cancelled = Order::whereKey($order->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);
        $freshOrder = $order->fresh();

        if ($cancelled > 0) {
            $this->stockReservationService->release($freshOrder);
        }

        return $freshOrder;
    }
}
