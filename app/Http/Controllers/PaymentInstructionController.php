<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\PendingOrderExpirationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PaymentInstructionController extends Controller
{
    public function __construct(
        private readonly PendingOrderExpirationService $pendingOrderExpirationService
    ) {}

    public function show(Request $request, string $orderId): View
    {
        $userId = (int) $request->user()->id;

        $this->pendingOrderExpirationService->expire($userId);

        $order = Order::query()
            ->with(['items.product', 'items.package', 'product', 'package', 'voucher'])
            ->withCount('licenses')
            ->where('order_id', $orderId)
            ->where('user_id', $userId)
            ->firstOrFail();

        $rawPayload = is_array($order->payment_payload)
            ? $order->payment_payload
            : [];
        $payloadSupported = $this->supportsPaymentPayload($order, $rawPayload);
        $paymentState = $this->paymentState($order, $payloadSupported);
        $payment = $this->publicPaymentPayload(
            $order,
            $rawPayload,
            $paymentState['instruction_active']
        );
        $items = $order->lineItems()
            ->map(function ($item): array {
                $quantity = max(1, (int) $item->quantity);

                return [
                    'product_name' => (string) ($item->product_name ?: $item->product?->name ?: 'Product'),
                    'package_name' => (string) ($item->package_name ?: $item->package?->name ?: 'Package'),
                    'quantity' => $quantity,
                    'unit_price_idr' => max(0, (int) $item->unit_price_idr),
                    'unit_price_usdt' => max(0, (float) $item->unit_price_usdt),
                    'line_total_idr' => max(0, (int) $item->line_total_idr),
                    'line_total_usdt' => max(0, (float) $item->line_total_usdt),
                ];
            })
            ->values();
        $totalQuantity = max(1, (int) $items->sum('quantity'));
        $deliveredCount = (int) $order->licenses_count;

        return view('payment-instruction', [
            'orderSummary' => [
                'order_id' => $order->order_id,
                'payment_method' => $order->payment_method,
                'payment_method_label' => $this->paymentMethodLabel($order->payment_method),
                'status' => $order->status,
                'created_at' => $order->created_at?->timezone(config('app.timezone'))->format('d M Y, H:i'),
                'expired_at' => $order->expired_at?->timezone(config('app.timezone'))->format('d M Y, H:i'),
                'quantity' => $totalQuantity,
                'item_count' => $items->count(),
                'voucher_code' => $order->voucher?->code,
                'delivered_count' => $deliveredCount,
                'delivery_pending' => $order->status === 'paid' && $deliveredCount < $totalQuantity,
            ],
            'orderItems' => $items,
            'orderSubtotalIdr' => (int) $items->sum('line_total_idr'),
            'orderSubtotalUsdt' => round((float) $items->sum('line_total_usdt'), 6),
            'paymentState' => $paymentState,
            'payment' => $payment,
            'paymentRoutes' => [
                'sync' => $this->syncUrl($order),
                'cancel' => url('/cancel-order/'.$order->id),
                'orders' => url('/orders'),
                'licenses' => url('/licenses').'?order='.rawurlencode($order->order_id)
                    .'#license-'.rawurlencode($order->order_id),
            ],
        ]);
    }

    private function supportsPaymentPayload(Order $order, array $payload): bool
    {
        return match ($order->payment_method) {
            'gopay_qris' => ($payload['type'] ?? null) === 'gopay_qris_notification'
                && filled($payload['qr_payload'] ?? $payload['payment_number'] ?? null),
            'crypto' => ($payload['type'] ?? null) === 'direct_crypto'
                && filled($payload['address'] ?? null),
            'binance_pay' => ($payload['type'] ?? null) === 'binance_pay_personal'
                && filled($payload['pay_id'] ?? null),
            default => false,
        };
    }

    private function paymentState(Order $order, bool $payloadSupported): array
    {
        $now = now();
        $expiresAt = $order->expired_at;
        $pastExpiry = $expiresAt?->lte($now) ?? false;
        $expiredByCancellation = $order->status === 'cancelled'
            && $expiresAt
            && $order->updated_at
            && $order->updated_at->gte($expiresAt);
        $isPaid = $order->status === 'paid';
        $isExpired = $order->status === 'expired'
            || ($order->status === 'pending' && $pastExpiry)
            || $expiredByCancellation;
        $isActive = $order->status === 'pending' && ! $pastExpiry;
        $isCancelled = $order->status === 'cancelled' && ! $isExpired;
        $recoveryHours = match ($order->payment_method) {
            'gopay_qris' => max(1, (int) config('services.gopay_qris.recovery_hours', 72)),
            'crypto' => max(1, (int) config('services.crypto_direct.recovery_hours', 24)),
            'binance_pay' => max(1, (int) config('services.binance.pay.recovery_hours', 24)),
            default => 0,
        };
        $recoveryEndsAt = $expiresAt && $recoveryHours > 0
            ? $expiresAt->copy()->addHours($recoveryHours)
            : null;
        $withinRecovery = ! $isPaid
            && in_array($order->status, ['pending', 'cancelled', 'expired'], true)
            && $recoveryEndsAt
            && $now->lt($recoveryEndsAt);
        $selfServiceMinutes = match ($order->payment_method) {
            'crypto' => max(0, (int) config('services.crypto_direct.self_service_verify_minutes', 60)),
            'binance_pay' => max(0, (int) config('services.binance.pay.self_service_verify_minutes', 60)),
            default => 0,
        };
        $selfServiceEndsAt = $expiresAt && $selfServiceMinutes > 0
            ? $expiresAt->copy()->addMinutes($selfServiceMinutes)
            : null;
        $canRecoverWithSelfService = $withinRecovery
            && $selfServiceEndsAt
            && $now->lt($selfServiceEndsAt);
        $canSync = $payloadSupported && match ($order->payment_method) {
            'gopay_qris' => $isActive,
            'crypto', 'binance_pay' => $isActive || $canRecoverWithSelfService,
            default => false,
        };
        $statusKey = match (true) {
            $isPaid => 'paid',
            $isActive => 'pending',
            $isExpired => 'expired',
            $isCancelled => 'cancelled',
            default => 'closed',
        };

        return [
            'key' => $statusKey,
            'label' => match ($statusKey) {
                'paid' => 'Payment verified',
                'pending' => 'Waiting for payment',
                'expired' => 'Payment window expired',
                'cancelled' => 'Checkout cancelled',
                default => 'Checkout closed',
            },
            'message' => match ($statusKey) {
                'paid' => 'Your payment has been verified.',
                'pending' => 'Use only the payment details shown on this page and send the exact amount.',
                'expired' => 'Do not send a new payment using these expired instructions.',
                'cancelled' => 'This checkout is closed. Do not send a new payment using its old instructions.',
                default => 'This checkout can no longer accept a new payment.',
            },
            'payload_supported' => $payloadSupported,
            'instruction_active' => $isActive && $payloadSupported,
            'is_paid' => $isPaid,
            'is_expired' => $isExpired,
            'is_cancelled' => $isCancelled,
            'can_sync' => (bool) $canSync,
            'can_cancel' => $isActive,
            'within_recovery' => (bool) $withinRecovery,
            'self_service_recovery' => (bool) $canRecoverWithSelfService,
            'automatic_recovery' => $withinRecovery && $order->payment_method === 'gopay_qris',
            'recovery_ends_at' => $recoveryEndsAt?->toIso8601String(),
            'recovery_ends_at_label' => $recoveryEndsAt?->timezone(config('app.timezone'))->format('d M Y, H:i'),
            'self_service_ends_at_label' => $selfServiceEndsAt?->timezone(config('app.timezone'))->format('d M Y, H:i'),
            'expires_at' => $expiresAt?->toIso8601String(),
            'remaining_seconds' => $expiresAt
                ? max(0, (int) $now->diffInSeconds($expiresAt, false))
                : 0,
        ];
    }

    private function publicPaymentPayload(Order $order, array $payload, bool $showCredentials): array
    {
        $expiresAt = $order->expired_at?->toIso8601String();
        $remainingSeconds = $order->expired_at
            ? max(0, (int) now()->diffInSeconds($order->expired_at, false))
            : 0;

        if ($order->payment_method === 'gopay_qris') {
            return [
                'method' => 'gopay_qris',
                'method_label' => 'QRIS',
                'supported' => ($payload['type'] ?? null) === 'gopay_qris_notification',
                'qr_payload' => $showCredentials
                    ? (string) ($payload['qr_payload'] ?? $payload['payment_number'] ?? '')
                    : '',
                'base_amount' => (int) ($payload['base_amount'] ?? 0),
                'platform_fee' => (int) ($payload['platform_fee'] ?? 0),
                'unique_amount' => (int) ($payload['unique_amount'] ?? 0),
                'amount' => (int) ($payload['total_payment'] ?? $order->price),
                'requires_manual_amount' => (bool) ($payload['requires_manual_amount'] ?? true),
                'expires_at' => $expiresAt,
                'remaining_seconds' => $remainingSeconds,
            ];
        }

        if ($order->payment_method === 'crypto') {
            $token = $this->stablecoinToken($payload);

            return [
                'method' => 'crypto',
                'method_label' => $token.' Address',
                'supported' => ($payload['type'] ?? null) === 'direct_crypto',
                'token' => $token,
                'network' => (string) ($payload['network'] ?? ''),
                'network_label' => (string) ($payload['network_label'] ?? $payload['network_short_label'] ?? 'Selected network'),
                'network_short_label' => (string) ($payload['network_short_label'] ?? ''),
                'address' => $showCredentials ? (string) ($payload['address'] ?? '') : '',
                'contract' => $showCredentials ? (string) ($payload['contract'] ?? '') : '',
                'amount' => (string) ($payload['amount'] ?? number_format((float) $order->price, 6, '.', '')),
                'base_amount' => (string) ($payload['base_amount'] ?? ''),
                'unique_amount' => (string) ($payload['unique_amount'] ?? ''),
                'expires_at' => $expiresAt,
                'remaining_seconds' => $remainingSeconds,
            ];
        }

        if ($order->payment_method === 'binance_pay') {
            $token = $this->stablecoinToken($payload);

            return [
                'method' => 'binance_pay',
                'method_label' => 'Binance Pay',
                'supported' => ($payload['type'] ?? null) === 'binance_pay_personal',
                'token' => $token,
                'pay_id' => $showCredentials ? (string) ($payload['pay_id'] ?? '') : '',
                'qr_content' => $showCredentials ? (string) ($payload['qr_content'] ?? '') : '',
                'amount' => (string) ($payload['amount'] ?? number_format((float) $order->price, 6, '.', '')),
                'base_amount' => (string) ($payload['base_amount'] ?? ''),
                'unique_amount' => (string) ($payload['unique_amount'] ?? ''),
                'expires_at' => $expiresAt,
                'remaining_seconds' => $remainingSeconds,
            ];
        }

        return [
            'method' => (string) $order->payment_method,
            'method_label' => $this->paymentMethodLabel($order->payment_method),
            'supported' => false,
            'amount' => (string) $order->price,
            'expires_at' => $expiresAt,
            'remaining_seconds' => $remainingSeconds,
        ];
    }

    private function stablecoinToken(array $payload): string
    {
        $token = strtoupper(trim((string) ($payload['token'] ?? 'USDT')));

        return in_array($token, ['USDT', 'USDC'], true) ? $token : 'USDT';
    }

    private function paymentMethodLabel(?string $method): string
    {
        return match ($method) {
            'gopay_qris', 'pakasir' => 'QRIS',
            'crypto' => 'Crypto Address',
            'binance_pay' => 'Binance Pay',
            default => 'Payment',
        };
    }

    private function syncUrl(Order $order): ?string
    {
        return match ($order->payment_method) {
            'gopay_qris' => route('gopay-qris.sync', ['orderId' => $order->order_id], false),
            'crypto' => '/sync-crypto-order/'.rawurlencode($order->order_id),
            'binance_pay' => '/sync-binance-pay-order/'.rawurlencode($order->order_id),
            default => null,
        };
    }
}
