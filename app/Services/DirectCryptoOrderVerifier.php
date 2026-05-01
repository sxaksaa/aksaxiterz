<?php

namespace App\Services;

use App\Models\License;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DirectCryptoOrderVerifier
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly OrderFulfillmentService $orderFulfillmentService
    ) {
    }

    public function verify(Order $order): array
    {
        if (! $this->isDirectCryptoOrder($order)) {
            return [
                'order_id' => $order->order_id,
                'status' => $order->status,
                'message' => 'This crypto order uses the old checkout flow. Please cancel it and start a new USDT address checkout.',
            ];
        }

        if ($order->status === 'paid') {
            return $this->withLicensePayload([
                'order_id' => $order->order_id,
                'status' => 'paid',
            ], $order);
        }

        try {
            $inspection = $this->paymentService->inspectDirectCryptoPayment($order);
            $transfer = $inspection['transfer'] ?? null;

            if (! $transfer) {
                $this->rememberInspection($order, $inspection);

                $payload = [
                    'order_id' => $order->order_id,
                    'status' => $order->fresh()->status,
                    'message' => empty($inspection['mismatches'])
                        ? 'Crypto payment is still being verified.'
                        : 'Received USDT amount does not match this order. Please contact support.',
                ];

                if (! empty($inspection['mismatches'][0])) {
                    $payload['amount_mismatch'] = $inspection['mismatches'][0];
                }

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

            if ($lockedOrder->status !== 'paid') {
                $this->rememberMatchedTransfer($lockedOrder, $transfer);
                $this->orderFulfillmentService->fulfill($lockedOrder);
            }

            DB::commit();

            return $this->withLicensePayload([
                'order_id' => $lockedOrder->order_id,
                'status' => $lockedOrder->fresh()->status,
                'tx_hash' => $transfer['tx_hash'] ?? null,
            ], $lockedOrder);
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
            ->where('status', 'pending')
            ->where('created_at', '>', now()->subDay())
            ->where(function ($query): void {
                $query->whereNull('expired_at')
                    ->orWhere('expired_at', '>', now());
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
            } elseif (! empty($result['amount_mismatch'] ?? null)) {
                $summary['mismatch']++;
            } else {
                $summary['pending']++;
            }
        }

        return $summary;
    }

    private function rememberInspection(Order $order, array $inspection): void
    {
        $payload = $order->payment_payload;

        if (! is_array($payload)) {
            return;
        }

        $payload['scanner_status'] = empty($inspection['mismatches']) ? 'pending' : 'amount_mismatch';
        $payload['last_checked_at'] = now()->toIso8601String();

        if (! empty($inspection['mismatches'])) {
            $payload['amount_mismatch'] = $inspection['mismatches'][0];
            $payload['amount_mismatches'] = array_slice($inspection['mismatches'], 0, 5);
        }

        $order->update([
            'payment_payload' => $payload,
        ]);
    }

    private function rememberMatchedTransfer(Order $order, array $transfer): void
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
        ]);
    }

    private function withLicensePayload(array $payload, Order $order): array
    {
        if (($payload['status'] ?? null) !== 'paid') {
            return $payload;
        }

        $license = License::where('order_id', $order->order_id)->first();

        if ($license) {
            $payload['license_key'] = $license->license_key;
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

    private function publicCryptoSyncError(\Exception $error): string
    {
        if ($error->getMessage() === 'No license stock available for this package') {
            return 'Payment is verified, but no license stock is available for this package.';
        }

        if ($error->getMessage() === 'Unable to verify crypto payment') {
            return 'Crypto network API could not be reached. Please try Verify again.';
        }

        return 'Crypto payment is still being verified.';
    }
}
