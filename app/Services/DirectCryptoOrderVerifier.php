<?php

namespace App\Services;

use App\Models\License;
use App\Models\Order;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DirectCryptoOrderVerifier
{
    private StockReservationService $stockReservationService;

    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly OrderFulfillmentService $orderFulfillmentService,
        ?StockReservationService $stockReservationService = null
    ) {
        $this->stockReservationService = $stockReservationService ?: app(StockReservationService::class);
    }

    public function verify(Order $order): array
    {
        try {
            return Cache::lock("payment-verify:crypto:{$order->id}", 120)
                ->block(0, fn () => $this->verifyUnlocked($order));
        } catch (LockTimeoutException) {
            if ($order->exists) {
                $order->refresh();
            }

            $payload = [
                'order_id' => $order->order_id,
                'status' => $order->status,
                'message' => 'Crypto payment is already being checked. Please wait for the current check to finish.',
            ];

            return $order->status === 'paid'
                ? $this->withLicensePayload($payload, $order)
                : $payload;
        }
    }

    private function verifyUnlocked(Order $order): array
    {
        if ($order->exists) {
            $order->refresh();
        }

        if (! $this->isDirectCryptoOrder($order)) {
            return [
                'order_id' => $order->order_id,
                'status' => $order->status,
                'message' => 'This crypto order uses the old checkout flow. Please cancel it and start a new stablecoin address checkout.',
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
                'message' => 'This order is no longer active. Please contact support if a payment was already sent.',
            ];
        }

        if ($this->isPastRecoveryPeriod($order)) {
            if ($order->status === 'pending') {
                $order = $this->cancelPendingOrder($order);
            }

            if ($order->status === 'paid') {
                return $this->withLicensePayload([
                    'order_id' => $order->order_id,
                    'status' => 'paid',
                ], $order);
            }

            return [
                'order_id' => $order->order_id,
                'status' => 'cancelled',
                'message' => 'The automatic verification window for this crypto invoice has ended. Please contact support with the transaction receipt.',
            ];
        }

        if ($order->status === 'pending' && $this->isPastGracePeriod($order)) {
            $order = $this->cancelPendingOrder($order);

            if ($order->status === 'paid') {
                return $this->withLicensePayload([
                    'order_id' => $order->order_id,
                    'status' => 'paid',
                ], $order);
            }
        }

        try {
            $inspection = $this->paymentService->inspectDirectCryptoPayment($order);
            $transfer = $inspection['transfer'] ?? null;

            if ($transfer && ! $this->transferWithinGracePeriod($order, $transfer)) {
                $transfer = null;
            }

            if (! $transfer) {
                $this->rememberInspection($order, $inspection);
                $payload = [
                    'order_id' => $order->order_id,
                    'status' => $order->fresh()->status,
                    'message' => $this->isPastGracePeriod($order)
                        ? 'No qualifying payment confirmed before the invoice deadline was found. You can retry Verify Sent Payment during the recovery window.'
                        : 'Crypto payment is still being verified. Make sure it was sent to the exact address, exact amount, and selected network. If Binance shows Off-chain Transfer, keep the receipt for support.',
                ];

                return $payload;
            }

            DB::beginTransaction();

            $lockedOrder = Order::whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->isDirectCryptoOrder($lockedOrder)) {
                DB::rollBack();

                return [
                    'order_id' => $order->order_id,
                    'status' => $order->status,
                    'error' => 'Crypto order data does not match this checkout.',
                ];
            }

            if (! in_array($lockedOrder->status, ['pending', 'cancelled'], true)) {
                DB::rollBack();

                return [
                    'order_id' => $lockedOrder->order_id,
                    'status' => $lockedOrder->status,
                    'message' => 'This order is no longer active. Please contact support if a payment was already sent.',
                ];
            }

            if ($this->isPastRecoveryPeriod($lockedOrder)) {
                if ($lockedOrder->status === 'pending') {
                    $lockedOrder->update(['status' => 'cancelled']);
                    $this->stockReservationService->release($lockedOrder);
                }
                DB::commit();

                return [
                    'order_id' => $lockedOrder->order_id,
                    'status' => 'cancelled',
                    'message' => 'The automatic verification window for this crypto invoice has ended. Please contact support with the transaction receipt.',
                ];
            }

            $reference = $this->paymentReference($transfer);

            if ($reference === '') {
                throw new \Exception('Crypto transfer reference is missing');
            }

            if (blank($lockedOrder->payment_match_key)) {
                throw new \Exception('Crypto invoice match key is missing');
            }

            if (
                Order::where('payment_reference', $reference)
                    ->whereKeyNot($lockedOrder->getKey())
                    ->exists()
            ) {
                throw new \Exception('Crypto transfer was already used');
            }

            $deliveryPending = false;

            $this->rememberMatchedTransfer($lockedOrder, $transfer, $reference);

            try {
                $this->orderFulfillmentService->fulfill($lockedOrder);
            } catch (\Exception $fulfillmentError) {
                if ($fulfillmentError->getMessage() !== 'No license stock available for this package') {
                    throw $fulfillmentError;
                }

                $deliveryPending = true;
                $this->orderFulfillmentService->markPaid($lockedOrder);
            }

            DB::commit();

            $payload = $this->withLicensePayload([
                'order_id' => $lockedOrder->order_id,
                'status' => $lockedOrder->fresh()->status,
                'tx_hash' => $transfer['tx_hash'] ?? null,
            ], $lockedOrder);

            if ($deliveryPending && ($payload['delivered_count'] ?? 0) < ($payload['quantity'] ?? 1)) {
                $payload['delivery_pending'] = true;
                $payload['message'] = 'Payment is verified, but automatic delivery could not provide every license key. Please join Discord for manual delivery.';
            }

            return $payload;
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::warning('DIRECT CRYPTO VERIFY ERROR: '.$e->getMessage(), [
                'order_id' => $order->order_id,
            ]);

            return [
                'order_id' => $order->order_id,
                'status' => $order->fresh()->status,
                'message' => $this->publicCryptoSyncError($e),
            ];
        }
    }

    public function scanPending(int $limit = 50): array
    {
        $orders = Order::query()
            ->where('payment_method', 'crypto')
            ->whereIn('status', ['pending', 'cancelled'])
            ->where('created_at', '>', now()->subHours($this->recoveryHours() + 2))
            ->where(function ($query): void {
                $query->whereNull('expired_at')
                    ->orWhere('expired_at', '>', now()->subHours($this->recoveryHours()));
            })
            ->oldest()
            ->limit(max(1, $limit))
            ->get()
            ->filter(fn (Order $order) => $this->isDirectCryptoOrder($order));

        $summary = [
            'checked' => 0,
            'paid' => 0,
            'mismatch' => 0,
            'pending' => 0,
        ];

        foreach ($orders as $order) {
            $summary['checked']++;
            $result = $this->verify($order);

            if (($result['status'] ?? null) === 'paid') {
                $summary['paid']++;
            } else {
                $summary['pending']++;
            }
        }

        return $summary;
    }

    public function cancelExpiredForUser(int $userId): int
    {
        $orders = Order::query()
            ->where('user_id', $userId)
            ->where('payment_method', 'crypto')
            ->where('status', 'pending')
            ->whereNotNull('expired_at')
            ->where('expired_at', '<=', now()->subMinutes($this->graceMinutes()))
            ->get();

        $cancelled = 0;

        foreach ($orders as $order) {
            DB::transaction(function () use ($order, &$cancelled): void {
                $lockedOrder = Order::whereKey($order->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedOrder || $lockedOrder->status !== 'pending' || ! $this->isPastGracePeriod($lockedOrder)) {
                    return;
                }

                $lockedOrder->update(['status' => 'cancelled']);
                $this->stockReservationService->release($lockedOrder);
                $cancelled++;
            });
        }

        return $cancelled;
    }

    private function rememberInspection(Order $order, array $inspection): void
    {
        $payload = $order->payment_payload;

        if (! is_array($payload)) {
            return;
        }

        $payload['scanner_status'] = 'pending';
        $payload['last_checked_at'] = now()->toIso8601String();
        unset($payload['amount_mismatch'], $payload['amount_mismatches']);

        if (isset($inspection['last_scanned_block']) && is_numeric($inspection['last_scanned_block'])) {
            $payload['last_scanned_block'] = max(0, (int) $inspection['last_scanned_block']);
        }

        DB::transaction(function () use ($order, $payload): void {
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

            $lockedOrder->update([
                'payment_payload' => $payload,
            ]);
        });

        $order->refresh();
    }

    private function rememberMatchedTransfer(Order $order, array $transfer, string $reference): void
    {
        $payload = $order->payment_payload;

        if (! is_array($payload)) {
            $payload = [];
        }

        $payload['scanner_status'] = 'matched';
        $payload['last_checked_at'] = now()->toIso8601String();
        $payload['tx_hash'] = $transfer['tx_hash'] ?? null;
        $payload['paid_at'] = now()->toIso8601String();
        unset($payload['amount_mismatch'], $payload['amount_mismatches']);

        if (! empty($transfer['confirmed_at']) && $transfer['confirmed_at'] instanceof \DateTimeInterface) {
            $payload['confirmed_at'] = $transfer['confirmed_at']->format(DATE_ATOM);
        }

        $order->update([
            'payment_payload' => $payload,
            'payment_reference' => $reference,
            'payment_match_key' => null,
        ]);
    }

    private function paymentReference(array $transfer): string
    {
        return strtolower(trim((string) ($transfer['tx_hash'] ?? '')));
    }

    private function withLicensePayload(array $payload, Order $order): array
    {
        if (($payload['status'] ?? null) !== 'paid') {
            return $payload;
        }

        $licenses = License::where('order_id', $order->order_id)->oldest('id')->get();
        $payload['quantity'] = max(1, (int) $order->quantity);
        $payload['delivered_count'] = $licenses->count();

        if ($licenses->isNotEmpty()) {
            $payload['license_key'] = $licenses->first()->license_key;
            $payload['license_keys'] = $licenses->pluck('license_key')->all();
        }

        return $payload;
    }

    private function isDirectCryptoOrder(Order $order): bool
    {
        $payload = $order->payment_payload;

        return $order->payment_method === 'crypto' &&
            is_array($payload) &&
            ($payload['type'] ?? null) === 'direct_crypto';
    }

    private function graceMinutes(): int
    {
        return max(0, (int) config('services.crypto_direct.grace_minutes', 2));
    }

    private function recoveryHours(): int
    {
        return max(1, (int) config('services.crypto_direct.recovery_hours', 24));
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

    private function transferWithinGracePeriod(Order $order, array $transfer): bool
    {
        if (! $order->expired_at) {
            return true;
        }

        $confirmedAt = $transfer['confirmed_at'] ?? null;

        if (! $confirmedAt instanceof \DateTimeInterface) {
            return false;
        }

        return $confirmedAt <= $order->expired_at->copy()->addMinutes($this->graceMinutes());
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

    private function publicCryptoSyncError(\Exception $error): string
    {
        if ($error->getMessage() === 'No license stock available for this package') {
            return 'Payment is verified, but automatic license delivery is not ready for this package. Please join Discord for manual delivery.';
        }

        if ($error->getMessage() === 'Unable to verify crypto payment') {
            return 'Crypto network API could not be reached. Please try Verify again.';
        }

        if (in_array($error->getMessage(), [
            'Crypto transfer reference is missing',
            'Crypto transfer was already used',
            'Crypto invoice match key is missing',
        ], true)) {
            return 'This transfer cannot be matched automatically. Please contact support with the transaction receipt.';
        }

        return 'Crypto payment is still being verified. Make sure it was sent to the exact address, exact amount, and selected network. If Binance shows Off-chain Transfer, keep the receipt for support.';
    }
}
