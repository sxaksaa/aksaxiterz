<?php

namespace App\Http\Controllers;

use App\Models\License;
use App\Models\Order;
use App\Services\DirectCryptoOrderVerifier;
use App\Services\PakasirOrderVerifier;
use App\Services\PaymentService;
use App\Services\StockReservationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    protected PaymentService $paymentService;

    public function __construct(
        PaymentService $paymentService,
        private readonly DirectCryptoOrderVerifier $directCryptoOrderVerifier,
        private readonly PakasirOrderVerifier $pakasirOrderVerifier,
        private readonly StockReservationService $stockReservationService
    )
    {
        $this->paymentService = $paymentService;
    }

    public function payAgain(Request $request, $orderId)
    {
        $user = Auth::user();

        $oldOrder = Order::findOrFail($orderId);

        if ($oldOrder->user_id !== $user->id) {
            abort(403);
        }

        if ($oldOrder->status === 'paid') {
            return back()->withErrors(['msg' => 'Already paid']);
        }

        $paymentMethod = $oldOrder->payment_method === 'crypto' ? 'crypto' : 'pakasir';
        $cryptoNetwork = $this->retryCryptoNetwork($oldOrder);

        if (! $this->cancelBeforeReplacement($oldOrder)) {
            return $this->paymentErrorResponse(
                $request,
                'The previous payment could not be closed. Check its status before trying again.',
                409
            );
        }

        $newOrder = Order::create([
            'order_id' => 'ORDER-'.strtoupper(Str::random(10)),
            'user_id' => $user->id,
            'product_id' => $oldOrder->product_id,
            'package_id' => $oldOrder->package_id,
            'status' => 'pending',
            'payment_method' => $paymentMethod,
            'price' => $oldOrder->price,
            'expired_at' => now()->addMinutes(10),
        ]);

        $oldOrder->update(['replaced_by' => $newOrder->id]);

        Order::where('user_id', $user->id)
            ->where('status', 'pending')
            ->where('id', '!=', $newOrder->id)
            ->update(['status' => 'cancelled']);

        try {
            if ($newOrder->payment_method === 'pakasir') {

                $payment = $this->paymentService->createPakasirPayment(
                    $user,
                    $newOrder->product_id,
                    $newOrder->package_id,
                    $newOrder
                );

                if ($this->wantsPaymentJson($request)) {
                    return response()->json($this->pakasirCheckoutPayload($payment['order']));
                }

                return redirect($payment['payment_url']);
            }

            $payment = $this->paymentService->createCryptoPayment(
                $user,
                $newOrder->product_id,
                $newOrder->package_id,
                $cryptoNetwork,
                $newOrder
            );

            if ($this->wantsPaymentJson($request)) {
                return response()->json($this->cryptoCheckoutPayload($payment['order']));
            }

            return redirect('/orders');
        } catch (\Exception $e) {
            $newOrder->update(['status' => 'cancelled']);
            $this->stockReservationService->release($newOrder);

            Log::error('PAY AGAIN ERROR: '.$e->getMessage());

            return $this->paymentErrorResponse($request, $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PAKASIR (PAY)
    |--------------------------------------------------------------------------
    */

    public function payPakasir(Request $request, $id)
    {
        $user = Auth::user();

        if ($pendingOrder = $this->activePendingOrder($user->id)) {
            return $this->pendingPaymentResponse($request, $pendingOrder);
        }

        if ($this->hasTooManyRecentOrders($user->id)) {
            return $this->paymentErrorResponse($request, 'Too many requests. Please try again later.', 429);
        }

        $request->validate([
            'package_id' => 'required|exists:packages,id',
        ]);

        try {
            $this->cancelPendingOrders($user->id);
            $payment = $this->paymentService->createPakasirPayment(
                $user,
                $id,
                $request->package_id
            );

            if ($this->wantsPaymentJson($request)) {
                return response()->json($this->pakasirCheckoutPayload($payment['order']));
            }

            return redirect($payment['payment_url']);
        } catch (\Exception $e) {
            Log::error('PAKASIR ERROR: '.$e->getMessage());

            return $this->paymentErrorResponse($request, $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PAKASIR STATUS SYNC
    |--------------------------------------------------------------------------
    */

    public function syncPakasirOrder(Request $request, string $orderId)
    {
        $order = Order::where('order_id', $orderId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($order->payment_method !== 'pakasir') {
            abort(404);
        }

        if ($order->status === 'paid') {
            return $this->syncPaymentResponse($request, $this->withLicensePayload([
                'order_id' => $order->order_id,
                'status' => $order->status,
            ], $order));
        }

        $result = $this->pakasirOrderVerifier->verify($order);
        $status = ($result['status'] ?? null) === 'paid' ? 200 : 202;

        return $this->syncPaymentResponse($request, $result, $status);
    }

    public function syncCryptoOrder(Request $request, string $orderId)
    {
        $order = Order::where('order_id', $orderId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($order->payment_method !== 'crypto') {
            abort(404);
        }

        if ($order->status === 'paid') {
            return $this->syncPaymentResponse($request, $this->withLicensePayload([
                'order_id' => $order->order_id,
                'status' => $order->status,
            ], $order));
        }

        $result = $this->directCryptoOrderVerifier->verify($order);
        $status = ($result['status'] ?? null) === 'paid' ? 200 : 202;

        return $this->syncPaymentResponse($request, $result, $status);
    }

    /*
    |--------------------------------------------------------------------------
    | PAKASIR CALLBACK
    |--------------------------------------------------------------------------
    */

    public function pakasirCallback(Request $request)
    {
        try {
            $order = Order::where('order_id', $request->order_id)
                ->firstOrFail();

            if (! $this->pakasirOrderVerifier->validPayload($order, $request->all())) {
                return response()->json(['error' => 'Invalid amount'], 403);
            }

            return response()->json($this->pakasirOrderVerifier->verify($order));
        } catch (\Exception $e) {
            Log::error('PAKASIR CALLBACK ERROR: '.$e->getMessage());

            return response()->json(['error' => 'failed'], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CRYPTO (PAY)
    |--------------------------------------------------------------------------
    */

    public function payCrypto(Request $request, $productId)
    {
        $user = Auth::user();

        if ($pendingOrder = $this->activePendingOrder($user->id)) {
            return $this->pendingPaymentResponse($request, $pendingOrder);
        }

        if ($this->hasTooManyRecentOrders($user->id)) {
            return $this->paymentErrorResponse($request, 'Too many requests. Please try again later.', 429);
        }

        $request->validate([
            'package_id' => 'required|exists:packages,id',
            'coin' => [
                'required',
                'string',
                'max:20',
                Rule::in(array_keys(config('services.crypto_direct.networks', []))),
            ],
        ]);

        try {
            $this->cancelPendingOrders($user->id);
            $payment = $this->paymentService->createCryptoPayment(
                $user,
                $productId,
                $request->package_id,
                $request->coin
            );

            if ($this->wantsPaymentJson($request)) {
                return response()->json($this->cryptoCheckoutPayload($payment['order']));
            }

            return redirect('/orders');
        } catch (\Exception $e) {

            Log::error('CRYPTO ERROR: '.$e->getMessage());

            return $this->paymentErrorResponse($request, $e);
        }
    }

    public function cancelOrder(Request $request, $orderId)
    {
        $order = Order::whereKey($orderId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($order->status === 'paid') {
            return $this->orderActionResponse($request, [
                'message' => 'Paid orders cannot be cancelled.',
            ], 422);
        }

        if (
            $order->payment_method === 'pakasir' &&
            is_array($order->payment_payload) &&
            filled($order->payment_payload['payment_number'] ?? null)
        ) {
            try {
                $this->paymentService->cancelPakasir($order);
            } catch (\Exception $e) {
                $result = $this->pakasirOrderVerifier->verify($order);

                if (($result['status'] ?? null) === 'paid') {
                    return $this->syncPaymentResponse($request, $result);
                }

                if (in_array(($result['provider_status'] ?? null), ['cancelled', 'canceled', 'expired', 'failed'], true)) {
                    $order->update([
                        'status' => 'cancelled',
                        'expired_at' => now(),
                    ]);
                    $this->stockReservationService->release($order);

                    return $this->orderActionResponse($request, [
                        'order_id' => $order->order_id,
                        'status' => 'cancelled',
                        'message' => 'Order cancelled. You can start a new checkout now.',
                    ]);
                }

                return $this->orderActionResponse($request, [
                    'order_id' => $order->order_id,
                    'status' => $order->fresh()->status,
                    'message' => 'The QRIS payment could not be cancelled yet. Please check its status and try again.',
                ], 409);
            }
        }

        if ($order->status !== 'cancelled') {
            $order->update([
                'status' => 'cancelled',
                'expired_at' => now(),
            ]);
        }
        $this->stockReservationService->release($order);

        return $this->orderActionResponse($request, [
            'order_id' => $order->order_id,
            'status' => 'cancelled',
            'message' => 'Order cancelled. You can start a new checkout now.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CORE LOGIC
    |--------------------------------------------------------------------------
    */

    private function pakasirCheckoutPayload(Order $order): array
    {
        return [
            'method' => 'pakasir',
            'payment_url' => $order->payment_url,
            'order_id' => $order->order_id,
            'pakasir_payment' => $this->publicPakasirPaymentPayload($order),
        ];
    }

    private function cryptoCheckoutPayload(Order $order): array
    {
        return [
            'method' => 'crypto',
            'payment_url' => $order->payment_url,
            'order_id' => $order->order_id,
            'crypto_payment' => $this->publicDirectCryptoPaymentPayload($order),
        ];
    }

    private function publicPakasirPaymentPayload(Order $order): ?array
    {
        $payload = $order->payment_payload;

        if (! is_array($payload) || blank($payload['payment_number'] ?? null)) {
            return null;
        }

        return [
            'amount' => (int) ($payload['amount'] ?? $order->price),
            'fee' => (int) ($payload['fee'] ?? 0),
            'total_payment' => (int) ($payload['total_payment'] ?? $payload['amount'] ?? $order->price),
            'payment_method' => (string) ($payload['payment_method'] ?? 'qris'),
            'payment_number' => (string) $payload['payment_number'],
            'expired_at' => $order->expired_at?->toIso8601String() ?: (string) ($payload['expired_at'] ?? ''),
            'remaining_seconds' => $this->remainingSeconds($order),
        ];
    }

    private function publicDirectCryptoPaymentPayload(Order $order): ?array
    {
        $payload = $order->payment_payload;

        if (! is_array($payload) || ($payload['type'] ?? null) !== 'direct_crypto') {
            return null;
        }

        return [
            'token' => (string) ($payload['token'] ?? 'USDT'),
            'network' => (string) ($payload['network'] ?? ''),
            'network_label' => (string) ($payload['network_label'] ?? 'USDT'),
            'network_short_label' => (string) ($payload['network_short_label'] ?? ''),
            'address' => (string) ($payload['address'] ?? ''),
            'contract' => (string) ($payload['contract'] ?? ''),
            'amount' => (string) ($payload['amount'] ?? number_format((float) $order->price, 6, '.', '')),
            'base_amount' => (string) ($payload['base_amount'] ?? ''),
            'unique_amount' => (string) ($payload['unique_amount'] ?? ''),
            'expired_at' => $order->expired_at?->toIso8601String() ?: (string) ($payload['expires_at'] ?? ''),
            'remaining_seconds' => $this->remainingSeconds($order),
        ];
    }

    private function remainingSeconds(Order $order): int
    {
        if (! $order->expired_at) {
            return 0;
        }

        return max(0, (int) now()->diffInSeconds($order->expired_at, false));
    }

    private function retryCryptoNetwork(Order $order): string
    {
        $payload = $order->payment_payload;
        $network = is_array($payload) ? strtolower(trim((string) ($payload['network'] ?? ''))) : '';
        $configuredNetworks = array_keys(config('services.crypto_direct.networks', []));

        return in_array($network, $configuredNetworks, true) ? $network : 'usdttrc20';
    }

    private function syncPaymentResponse(Request $request, array $payload, int $status = 200)
    {
        if ($this->wantsPaymentJson($request)) {
            return response()->json($payload, $status);
        }

        if (($payload['status'] ?? null) === 'paid') {
            $orderId = (string) ($payload['order_id'] ?? '');
            $target = $orderId !== ''
                ? '/licenses?order=' . rawurlencode($orderId) . '#license-' . $orderId
                : '/licenses';

            return redirect($target);
        }

        return redirect('/orders')->with(
            'info',
            $payload['message'] ?? $payload['error'] ?? 'Payment is still being verified.'
        );
    }

    private function withLicensePayload(array $payload, Order $order): array
    {
        if (($payload['status'] ?? null) !== 'paid') {
            return $payload;
        }

        $license = License::where('order_id', $order->order_id)->first();

        if (! $license) {
            return $payload;
        }

        $payload['license_key'] = $license->license_key;

        return $payload;
    }

    private function hasTooManyRecentOrders(int $userId): bool
    {
        return Order::where('user_id', $userId)
            ->where('status', 'pending')
            ->where('created_at', '>', now()->subMinute())
            ->count() >= 5;
    }

    private function cancelPendingOrders(int $userId): void
    {
        $orders = Order::where('user_id', $userId)
            ->where('status', 'pending')
            ->oldest()
            ->get();

        foreach ($orders as $order) {
            if ($order->payment_method === 'pakasir' && is_array($order->payment_payload)) {
                $result = $this->pakasirOrderVerifier->verify($order);

                if (($result['status'] ?? null) === 'paid') {
                    throw new \Exception('A previous payment was already completed');
                }

                if (! array_key_exists('provider_status', $result)) {
                    throw new \Exception('Unable to verify the previous Pakasir payment');
                }

                if (! in_array($result['provider_status'], ['cancelled', 'canceled', 'expired', 'failed'], true)) {
                    $this->paymentService->cancelPakasir($order);
                }
            }

            if (
                $order->payment_method === 'crypto' &&
                $order->expired_at &&
                $order->expired_at
                    ->copy()
                    ->addMinutes(max(0, (int) config('services.crypto_direct.grace_minutes', 15)))
                    ->isFuture()
            ) {
                continue;
            }

            $order->update(['status' => 'cancelled']);
            $this->stockReservationService->release($order);
        }
    }

    private function cancelBeforeReplacement(Order $order): bool
    {
        if ($order->payment_method === 'pakasir' && is_array($order->payment_payload)) {
            $result = $this->pakasirOrderVerifier->verify($order);

            if (($result['status'] ?? null) === 'paid' || ! array_key_exists('provider_status', $result)) {
                return false;
            }

            if (! in_array($result['provider_status'], ['cancelled', 'canceled', 'expired', 'failed'], true)) {
                try {
                    $this->paymentService->cancelPakasir($order);
                } catch (\Exception $e) {
                    return false;
                }
            }
        }

        if ($order->status !== 'cancelled') {
            $order->update(['status' => 'cancelled']);
        }

        $this->stockReservationService->release($order);

        return true;
    }

    private function activePendingOrder(int $userId): ?Order
    {
        return Order::where('user_id', $userId)
            ->where('status', 'pending')
            ->where(function ($query) {
                $query->whereNull('expired_at')
                    ->orWhere('expired_at', '>', now());
            })
            ->latest()
            ->first();
    }

    private function wantsPaymentJson(Request $request): bool
    {
        return $request->expectsJson() || $request->ajax();
    }

    private function pendingPaymentResponse(Request $request, Order $order)
    {
        $message = 'You already have an unfinished payment. Continue, verify, or cancel it from Orders first.';
        $redirectUrl = url('/orders?payment_notice=pending-order');

        if ($this->wantsPaymentJson($request)) {
            return response()->json([
                'message' => $message,
                'redirect_url' => $redirectUrl,
                'order_id' => $order->order_id,
            ], 409);
        }

        return redirect($redirectUrl)->with('info', $message);
    }

    private function orderActionResponse(Request $request, array $payload, int $status = 200)
    {
        if ($this->wantsPaymentJson($request)) {
            return response()->json($payload, $status);
        }

        return redirect('/orders')->with('info', $payload['message'] ?? 'Order updated.');
    }

    private function paymentErrorResponse(Request $request, \Exception|string $error, int $status = 422)
    {
        $message = $error instanceof \Exception ? $error->getMessage() : $error;

        if ($message === 'Direct crypto checkout is not configured') {
            $message = 'Crypto checkout is not configured yet.';
        }

        if (
            ! in_array($message, ['Crypto checkout is not configured yet.', 'Invalid crypto amount'], true) &&
            $error instanceof \Exception
        ) {
            $message = 'Payment failed';
        }

        if ($this->wantsPaymentJson($request)) {
            $payload = [
                'message' => $message,
            ];

            if ($status === 429) {
                $payload['message'] = 'Too many payment attempts. Open Orders and cancel unfinished payments before trying again.';
                $payload['redirect_url'] = url('/orders?payment_notice=too-many-attempts');
            }

            return response()->json($payload, $status);
        }

        if ($status === 429) {
            return redirect('/orders?payment_notice=too-many-attempts')
                ->with('info', 'Too many payment attempts. Open Orders and cancel unfinished payments before trying again.');
        }

        return back()->withErrors([
            'payment' => $message,
        ]);
    }
}
