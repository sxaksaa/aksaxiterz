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
                'message' => 'This crypto order uses the old checkout flow. Please cancel it and start a new stablecoin address checkout.',
            ];
        }

        if ($order->status === 'paid') {
            return $this->withLicensePayload([
                'order_id' => $order->order_id,
                'status' => 'paid',
            ], $order);
        }

        if ($order->status !== 'pending') {
            return [
                'order_id' => $order->order_id,
                'status' => $order->status,
                'message' => 'This order is no longer active. Please contact support if a payment was already sent.',
            ];
        }

        if ($order->expired_at && $order->expired_at->lte(now())) {
            $order->update(['status' => 'cancelled']);

            return [
                'order_id' => $order->order_id,
                'status' => 'cancelled',
                'message' => 'This crypto invoice has expired. Please contact support if a payment was already sent.',
            ];
        }

        try {
            $inspection = $this->paymentService->inspectDirectCryptoPayment($order);
            $transfer = $inspection['transfer'] ?? null;

            if (! $transfer) {
                $this->rememberInspection($order, $inspection);
                $token = $this->cryptoToken($order);

                $payload = [
                    'order_id' => $order->order_id,
                    'status' => $order->fresh()->status,
                    'message' => empty($inspection['mismatches'])
                        ? 'Crypto payment is still being verified. Make sure it was sent to the exact address, exact amount, and selected network. If Binance shows Off-chain Transfer, keep the receipt for support.'
                        : "Received {$token} amount does not match this order. Please contact support.",
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

            if ($lockedOrder->status !== 'pending') {
                DB::rollBack();

                return [
                    'order_id' => $lockedOrder->order_id,
                    'status' => $lockedOrder->status,
                    'message' => 'This order is no longer active. Please contact support if a payment was already sent.',
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

            if ($deliveryPending && empty($payload['license_key'] ?? null)) {
                $payload['delivery_pending'] = true;
                $payload['message'] = 'Payment is verified, but automatic license delivery is not ready for this package. Please join Discord for manual delivery.';
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
        } else {
            unset($payload['amount_mismatch'], $payload['amount_mismatches']);
        }

        if (isset($inspection['last_scanned_block']) && is_numeric($inspection['last_scanned_block'])) {
            $payload['last_scanned_block'] = max(0, (int) $inspection['last_scanned_block']);
        }

        $order->update([
            'payment_payload' => $payload,
        ]);
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

    private function cryptoToken(Order $order): string
    {
        $payload = $order->payment_payload;
        $token = is_array($payload) ? strtoupper(trim((string) ($payload['token'] ?? 'USDT'))) : 'USDT';

        return $token !== '' ? $token : 'USDT';
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
