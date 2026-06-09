<?php

namespace App\Services;

use App\Models\License;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PakasirOrderVerifier
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly OrderFulfillmentService $orderFulfillmentService,
        private readonly StockReservationService $stockReservationService
    ) {
    }

    public function verify(Order $order): array
    {
        if ($order->payment_method !== 'pakasir') {
            return [
                'order_id' => $order->order_id,
                'status' => $order->status,
                'message' => 'This order is not a Pakasir payment.',
            ];
        }

        if ($order->status === 'paid') {
            return $this->withLicensePayload([
                'order_id' => $order->order_id,
                'status' => 'paid',
            ], $order);
        }

        try {
            $payload = $this->paymentService->getPakasirStatus($order);

            if (! $this->validPayload($order, $payload)) {
                throw new \Exception('Invalid Pakasir status');
            }

            DB::beginTransaction();

            $lockedOrder = Order::whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->validPayload($lockedOrder, $payload)) {
                throw new \Exception('Invalid Pakasir status');
            }

            $transaction = $this->payloadTransaction($payload);
            $providerStatus = strtolower((string) ($transaction['status'] ?? ''));
            $deliveryPending = false;

            $this->rememberProviderStatus($lockedOrder, $transaction, $providerStatus);

            if ($providerStatus === 'completed') {
                try {
                    $this->orderFulfillmentService->fulfill($lockedOrder);
                } catch (\Exception $fulfillmentError) {
                    if ($fulfillmentError->getMessage() !== 'No license stock available for this package') {
                        throw $fulfillmentError;
                    }

                    $deliveryPending = true;
                    $this->orderFulfillmentService->markPaid($lockedOrder);
                }
            } elseif (in_array($providerStatus, ['cancelled', 'canceled', 'expired', 'failed'], true)) {
                if ($lockedOrder->status !== 'paid') {
                    $lockedOrder->update(['status' => 'cancelled']);
                    $this->stockReservationService->release($lockedOrder);
                }
            }

            DB::commit();

            $freshOrder = $lockedOrder->fresh();
            $result = $this->withLicensePayload([
                'order_id' => $freshOrder->order_id,
                'status' => $freshOrder->status,
                'provider_status' => $providerStatus,
            ], $freshOrder);

            if ($deliveryPending && empty($result['license_key'] ?? null)) {
                $result['delivery_pending'] = true;
                $result['message'] = 'Payment is verified, but automatic license delivery is not ready for this package. Please join Discord for manual delivery.';
            } elseif ($freshOrder->status !== 'paid') {
                $result['message'] = 'Payment is still being verified.';
            }

            return $result;
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::warning('PAKASIR VERIFY ERROR: '.$e->getMessage(), [
                'order_id' => $order->order_id,
            ]);

            return [
                'order_id' => $order->order_id,
                'status' => $order->fresh()->status,
                'message' => 'Payment is still being verified.',
            ];
        }
    }

    public function scanRecent(int $limit = 50): array
    {
        $orders = Order::query()
            ->where('payment_method', 'pakasir')
            ->where('created_at', '>', now()->subDay())
            ->whereNotNull('payment_payload')
            ->where(function ($query): void {
                $query->where('status', 'pending')
                    ->orWhere(function ($cancelled): void {
                        $cancelled->where('status', 'cancelled')
                            ->where('updated_at', '>', now()->subHours(2))
                            ->where(function ($providerStatus): void {
                                $providerStatus->whereNull('payment_payload->provider_status')
                                    ->orWhereNotIn('payment_payload->provider_status', [
                                        'cancelled',
                                        'canceled',
                                        'expired',
                                        'failed',
                                    ]);
                            });
                    });
            })
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->oldest()
            ->limit(max(1, $limit))
            ->get();

        $summary = [
            'checked' => 0,
            'paid' => 0,
            'cancelled' => 0,
            'pending' => 0,
        ];

        foreach ($orders as $order) {
            $summary['checked']++;
            $result = $this->verify($order);
            $status = (string) ($result['status'] ?? 'pending');

            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            } else {
                $summary['pending']++;
            }
        }

        return $summary;
    }

    public function validPayload(Order $order, array $payload): bool
    {
        $transaction = $this->payloadTransaction($payload);
        $payloadOrderId = $transaction['order_id'] ?? null;
        $amount = $transaction['amount'] ?? null;
        $project = $transaction['project'] ?? null;

        if (
            $order->payment_method !== 'pakasir' ||
            ! is_scalar($payloadOrderId) ||
            ! hash_equals($order->order_id, (string) $payloadOrderId) ||
            ! is_numeric($amount)
        ) {
            return false;
        }

        if ($project && ! hash_equals((string) config('services.pakasir.slug'), (string) $project)) {
            return false;
        }

        return round((float) $amount, 6) === round((float) $order->price, 6);
    }

    private function payloadTransaction(array $payload): array
    {
        if (isset($payload['transaction']) && is_array($payload['transaction'])) {
            return $payload['transaction'];
        }

        return $payload;
    }

    private function rememberProviderStatus(Order $order, array $transaction, string $status): void
    {
        $payload = is_array($order->payment_payload) ? $order->payment_payload : [];
        $payload['provider_status'] = $status;
        $payload['last_checked_at'] = now()->toIso8601String();

        if (filled($transaction['completed_at'] ?? null)) {
            $payload['completed_at'] = (string) $transaction['completed_at'];
        }

        $order->update(['payment_payload' => $payload]);
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
}
