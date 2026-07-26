<?php

namespace App\Http\Controllers;

use App\Exceptions\VoucherException;
use App\Models\License;
use App\Models\Order;
use App\Models\Product;
use App\Services\BinancePayOrderVerifier;
use App\Services\CartService;
use App\Services\CheckoutLockService;
use App\Services\DirectCryptoOrderVerifier;
use App\Services\PaymentService;
use App\Services\PendingOrderExpirationService;
use App\Services\StockReservationService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    protected PaymentService $paymentService;

    public function __construct(
        PaymentService $paymentService,
        private readonly CheckoutLockService $checkoutLockService,
        private readonly BinancePayOrderVerifier $binancePayOrderVerifier,
        private readonly DirectCryptoOrderVerifier $directCryptoOrderVerifier,
        private readonly PendingOrderExpirationService $pendingOrderExpirationService,
        private readonly StockReservationService $stockReservationService,
        private readonly CartService $cartService
    ) {
        $this->paymentService = $paymentService;
    }

    public function payAgain(Request $request, $orderId)
    {
        $user = Auth::user();

        return $this->runCheckoutLocked($request, $user->id, function () use ($request, $orderId, $user) {
            $oldOrder = Order::findOrFail($orderId);

            if ($oldOrder->user_id !== $user->id) {
                abort(403);
            }

            if ($oldOrder->status === 'paid') {
                return back()->withErrors(['msg' => 'Already paid']);
            }

            if ($pendingOrder = $this->activePendingOrder($user->id, $oldOrder->id)) {
                return $this->pendingPaymentResponse($request, $pendingOrder);
            }

            $paymentMethod = in_array($oldOrder->payment_method, ['crypto', 'binance_pay', 'gopay_qris'], true)
                ? $oldOrder->payment_method
                : 'gopay_qris';

            if ($paymentMethod === 'gopay_qris' && ! (bool) config('services.gopay_qris.enabled')) {
                return $this->paymentErrorResponse($request, 'QRIS checkout is currently unavailable.');
            }
            $cryptoNetwork = $this->retryCryptoNetwork($oldOrder);
            $binancePayToken = $this->retryBinancePayToken($oldOrder);
            $retryItems = $oldOrder->items()->with(['product', 'package'])->get();
            $hasStoredItems = $retryItems->isNotEmpty();
            $retryProducts = $hasStoredItems
                ? $retryItems->pluck('product')
                : collect([$oldOrder->product()->first()]);

            if ($retryProducts->contains(
                fn ($product): bool => ! ($product instanceof Product) || ! $product->isReadyForAutomaticCheckout()
            )) {
                return $this->paymentErrorResponse(
                    $request,
                    'This product is not ready for automatic checkout.'
                );
            }

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
                'quantity' => $oldOrder->quantity,
                'voucher_id' => $oldOrder->voucher_id,
                'status' => 'pending',
                'payment_method' => $paymentMethod,
                'price' => $oldOrder->price,
                'expired_at' => now()->addMinutes(max(1, (int) match ($paymentMethod) {
                    'crypto' => config('services.crypto_direct.expires_minutes', 10),
                    'binance_pay' => config('services.binance.pay.expires_minutes', 10),
                    default => config('services.gopay_qris.expires_minutes', 10),
                })),
            ]);

            $oldOrder->update(['replaced_by' => $newOrder->id]);

            try {
                if ($newOrder->payment_method === 'gopay_qris') {
                    $payment = $hasStoredItems
                        ? $this->paymentService->createCartGopayQrisPayment($user, $retryItems, $newOrder)
                        : $this->paymentService->createGopayQrisPayment(
                            $user,
                            $newOrder->product_id,
                            $newOrder->package_id,
                            $newOrder
                        );

                    if ($this->wantsPaymentJson($request)) {
                        return response()->json($this->gopayQrisCheckoutPayload($payment['order']));
                    }

                    return redirect('/orders');
                }

                if ($newOrder->payment_method === 'binance_pay') {
                    $payment = $hasStoredItems
                        ? $this->paymentService->createCartBinancePayPayment(
                            $user,
                            $retryItems,
                            $binancePayToken,
                            $newOrder
                        )
                        : $this->paymentService->createBinancePayPayment(
                            $user,
                            $newOrder->product_id,
                            $newOrder->package_id,
                            $newOrder,
                            null,
                            (int) $newOrder->quantity,
                            $binancePayToken
                        );

                    if ($this->wantsPaymentJson($request)) {
                        return response()->json($this->binancePayCheckoutPayload($payment['order']));
                    }

                    return redirect('/orders');
                }

                $payment = $hasStoredItems
                    ? $this->paymentService->createCartCryptoPayment(
                        $user,
                        $retryItems,
                        $cryptoNetwork,
                        $newOrder
                    )
                    : $this->paymentService->createCryptoPayment(
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
                $this->cancelPendingOrder($newOrder);

                Log::error('PAY AGAIN ERROR: '.$e->getMessage());

                return $this->paymentErrorResponse($request, $e);
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | GOPAY QRIS (PAY)
    |--------------------------------------------------------------------------
    */

    public function payGopayQris(Request $request, $id)
    {
        $user = Auth::user();

        $request->validate([
            'package_id' => 'required|exists:packages,id',
            'quantity' => ['nullable', 'integer', 'min:1'],
            'voucher_code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        return $this->runCheckoutLocked($request, $user->id, function () use ($request, $user, $id) {
            if ($pendingOrder = $this->activePendingOrder($user->id)) {
                return $this->pendingPaymentResponse($request, $pendingOrder);
            }

            if ($this->hasTooManyRecentOrders($user->id)) {
                return $this->paymentErrorResponse($request, 'Too many requests. Please try again later.', 429);
            }

            try {
                $this->cancelPendingOrders($user->id);
                $payment = $this->paymentService->createGopayQrisPayment(
                    $user,
                    $id,
                    $request->package_id,
                    null,
                    $request->string('voucher_code')->toString() ?: null,
                    $request->integer('quantity', 1)
                );

                if ($this->wantsPaymentJson($request)) {
                    return response()->json($this->gopayQrisCheckoutPayload($payment['order']));
                }

                return redirect('/orders');
            } catch (\Exception $error) {
                Log::error('GOPAY QRIS CHECKOUT ERROR: '.$error->getMessage());

                return $this->paymentErrorResponse($request, $error);
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | GOPAY QRIS STATUS SYNC
    |--------------------------------------------------------------------------
    */

    public function syncGopayQrisOrder(Request $request, string $orderId)
    {
        $order = Order::where('order_id', $orderId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($order->payment_method !== 'gopay_qris') {
            abort(404);
        }

        if ($order->status === 'pending' && $order->expired_at?->lte(now())) {
            $this->pendingOrderExpirationService->expire((int) $order->user_id);
            $order->refresh();
        }

        $payload = $this->withLicensePayload([
            'order_id' => $order->order_id,
            'status' => $order->status,
            'message' => $order->status === 'paid'
                ? 'Your QRIS payment has been verified.'
                : 'Waiting for a matching GoPay Merchant notification.',
        ], $order);

        return $this->syncPaymentResponse($request, $payload, $order->status === 'paid' ? 200 : 202);
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

        if ($this->isPastCryptoSelfServiceVerifyWindow($order)) {
            return $this->syncPaymentResponse($request, [
                'order_id' => $order->order_id,
                'status' => $order->status,
                'message' => 'Self-service verification for this crypto invoice has ended. Please contact support with the transaction receipt if payment was already sent.',
            ], 410);
        }

        $result = $this->directCryptoOrderVerifier->verify($order);
        $status = ($result['status'] ?? null) === 'paid' ? 200 : 202;

        return $this->syncPaymentResponse($request, $result, $status);
    }

    public function syncBinancePayOrder(Request $request, string $orderId)
    {
        $order = Order::where('order_id', $orderId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($order->payment_method !== 'binance_pay') {
            abort(404);
        }

        if ($order->status === 'paid') {
            return $this->syncPaymentResponse($request, $this->withLicensePayload([
                'order_id' => $order->order_id,
                'status' => 'paid',
            ], $order));
        }

        if ($this->isPastBinancePaySelfServiceVerifyWindow($order)) {
            return $this->syncPaymentResponse($request, [
                'order_id' => $order->order_id,
                'status' => $order->status,
                'message' => 'Self-service verification for this Binance Pay invoice has ended. Please contact support with the transaction receipt if payment was already sent.',
            ], 410);
        }

        $result = $this->binancePayOrderVerifier->verify($order);
        $status = ($result['status'] ?? null) === 'paid' ? 200 : 202;

        return $this->syncPaymentResponse($request, $result, $status);
    }

    /*
    |--------------------------------------------------------------------------
    | CRYPTO (PAY)
    |--------------------------------------------------------------------------
    */

    public function payCrypto(Request $request, $productId)
    {
        $user = Auth::user();

        $request->validate([
            'package_id' => 'required|exists:packages,id',
            'quantity' => ['nullable', 'integer', 'min:1'],
            'coin' => [
                'required',
                'string',
                'max:20',
                Rule::in(array_keys(config('services.crypto_direct.networks', []))),
            ],
            'voucher_code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        return $this->runCheckoutLocked($request, $user->id, function () use ($request, $user, $productId) {
            if ($pendingOrder = $this->activePendingOrder($user->id)) {
                return $this->pendingPaymentResponse($request, $pendingOrder);
            }

            if ($this->hasTooManyRecentOrders($user->id)) {
                return $this->paymentErrorResponse($request, 'Too many requests. Please try again later.', 429);
            }

            try {
                $this->cancelPendingOrders($user->id);
                $payment = $this->paymentService->createCryptoPayment(
                    $user,
                    $productId,
                    $request->package_id,
                    $request->coin,
                    null,
                    $request->string('voucher_code')->toString() ?: null,
                    $request->integer('quantity', 1)
                );

                if ($this->wantsPaymentJson($request)) {
                    return response()->json($this->cryptoCheckoutPayload($payment['order']));
                }

                return redirect('/orders');
            } catch (\Exception $e) {
                Log::error('CRYPTO ERROR: '.$e->getMessage());

                return $this->paymentErrorResponse($request, $e);
            }
        });
    }

    public function payBinance(Request $request, $productId)
    {
        $user = Auth::user();

        $request->validate([
            'package_id' => 'required|exists:packages,id',
            'quantity' => ['nullable', 'integer', 'min:1'],
            'token' => ['required', 'string', Rule::in(['usdt', 'usdc'])],
            'voucher_code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        return $this->runCheckoutLocked($request, $user->id, function () use ($request, $user, $productId) {
            if ($pendingOrder = $this->activePendingOrder($user->id)) {
                return $this->pendingPaymentResponse($request, $pendingOrder);
            }

            if ($this->hasTooManyRecentOrders($user->id)) {
                return $this->paymentErrorResponse($request, 'Too many requests. Please try again later.', 429);
            }

            try {
                $this->cancelPendingOrders($user->id);
                $payment = $this->paymentService->createBinancePayPayment(
                    $user,
                    $productId,
                    $request->package_id,
                    null,
                    $request->string('voucher_code')->toString() ?: null,
                    $request->integer('quantity', 1),
                    $request->string('token')->toString()
                );

                if ($this->wantsPaymentJson($request)) {
                    return response()->json($this->binancePayCheckoutPayload($payment['order']));
                }

                return redirect('/orders');
            } catch (\Exception $e) {
                Log::error('BINANCE PAY ERROR: '.$e->getMessage());

                return $this->paymentErrorResponse($request, $e);
            }
        });
    }

    public function checkoutCart(Request $request)
    {
        $user = Auth::user();
        $validated = $request->validate([
            'payment_method' => ['required', Rule::in(['gopay_qris', 'crypto', 'binance_pay'])],
            'coin' => [
                'nullable',
                'string',
                'required_if:payment_method,crypto',
                Rule::in(array_keys(config('services.crypto_direct.networks', []))),
            ],
            'token' => [
                'nullable',
                'string',
                'required_if:payment_method,binance_pay',
                Rule::in(['usdt', 'usdc']),
            ],
            'voucher_code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        return $this->runCheckoutLocked($request, $user->id, function () use ($request, $user, $validated) {
            if ($pendingOrder = $this->activePendingOrder($user->id)) {
                return $this->pendingPaymentResponse($request, $pendingOrder);
            }

            if ($this->hasTooManyRecentOrders($user->id)) {
                return $this->paymentErrorResponse($request, 'Too many requests. Please try again later.', 429);
            }

            $items = $this->cartService->items($user);

            try {
                $this->cartService->validateForCheckout($items);
                $this->cancelPendingOrders($user->id);
                $voucherCode = $validated['voucher_code'] ?? null;

                if ($validated['payment_method'] === 'gopay_qris') {
                    $payment = $this->paymentService->createCartGopayQrisPayment(
                        $user,
                        $items,
                        null,
                        $voucherCode
                    );
                    $this->cartService->clear($user);

                    return $this->wantsPaymentJson($request)
                        ? response()->json($this->gopayQrisCheckoutPayload($payment['order']))
                        : redirect('/orders');
                }

                if ($validated['payment_method'] === 'binance_pay') {
                    $payment = $this->paymentService->createCartBinancePayPayment(
                        $user,
                        $items,
                        (string) $validated['token'],
                        null,
                        $voucherCode
                    );
                    $this->cartService->clear($user);

                    return $this->wantsPaymentJson($request)
                        ? response()->json($this->binancePayCheckoutPayload($payment['order']))
                        : redirect('/orders');
                }

                $payment = $this->paymentService->createCartCryptoPayment(
                    $user,
                    $items,
                    (string) $validated['coin'],
                    null,
                    $voucherCode
                );
                $this->cartService->clear($user);

                return $this->wantsPaymentJson($request)
                    ? response()->json($this->cryptoCheckoutPayload($payment['order']))
                    : redirect('/orders');
            } catch (\Exception $error) {
                Log::error('CART CHECKOUT ERROR: '.$error->getMessage());

                return $this->paymentErrorResponse($request, $error);
            }
        });
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

        if ($order->payment_method === 'binance_pay') {
            $result = $this->binancePayOrderVerifier->verify($order);

            if (($result['status'] ?? null) === 'paid') {
                return $this->syncPaymentResponse($request, $result);
            }
        }

        return $this->cancelOrderResponse($request, $this->cancelPendingOrder($order));
    }

    /*
    |--------------------------------------------------------------------------
    | CORE LOGIC
    |--------------------------------------------------------------------------
    */

    private function gopayQrisCheckoutPayload(Order $order): array
    {
        return [
            'method' => 'gopay_qris',
            'payment_url' => null,
            'status_url' => url('/sync-gopay-qris-order/'.$order->order_id),
            'order_id' => $order->order_id,
            'quantity' => (int) $order->quantity,
            'qris_payment' => $this->publicGopayQrisPaymentPayload($order),
        ];
    }

    private function cryptoCheckoutPayload(Order $order): array
    {
        return [
            'method' => 'crypto',
            'payment_url' => $order->payment_url,
            'order_id' => $order->order_id,
            'quantity' => (int) $order->quantity,
            'crypto_payment' => $this->publicDirectCryptoPaymentPayload($order),
        ];
    }

    private function binancePayCheckoutPayload(Order $order): array
    {
        return [
            'method' => 'binance_pay',
            'payment_url' => null,
            'order_id' => $order->order_id,
            'quantity' => (int) $order->quantity,
            'binance_pay_payment' => $this->publicBinancePayPaymentPayload($order),
        ];
    }

    private function publicGopayQrisPaymentPayload(Order $order): ?array
    {
        $payload = $order->payment_payload;

        if (! is_array($payload) || ($payload['type'] ?? null) !== 'gopay_qris_notification') {
            return null;
        }

        return [
            'qr_payload' => (string) ($payload['qr_payload'] ?? $payload['payment_number'] ?? ''),
            'payment_number' => (string) ($payload['payment_number'] ?? $payload['qr_payload'] ?? ''),
            'base_amount' => (int) ($payload['base_amount'] ?? 0),
            'platform_fee' => (int) ($payload['platform_fee'] ?? 0),
            'unique_amount' => (int) ($payload['unique_amount'] ?? 0),
            'amount' => (int) ($payload['total_payment'] ?? $order->price),
            'total_payment' => (int) ($payload['total_payment'] ?? $order->price),
            'requires_manual_amount' => (bool) ($payload['requires_manual_amount'] ?? true),
            'expired_at' => $order->expired_at?->toIso8601String() ?: (string) ($payload['expires_at'] ?? ''),
            'remaining_seconds' => $this->remainingSeconds($order),
            'quantity' => (int) $order->quantity,
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
            'quantity' => (int) $order->quantity,
        ];
    }

    private function publicBinancePayPaymentPayload(Order $order): ?array
    {
        $payload = $order->payment_payload;

        if (! is_array($payload) || ($payload['type'] ?? null) !== 'binance_pay_personal') {
            return null;
        }

        return [
            'token' => (string) ($payload['token'] ?? 'USDT'),
            'pay_id' => (string) ($payload['pay_id'] ?? ''),
            'qr_content' => (string) ($payload['qr_content'] ?? ''),
            'amount' => (string) ($payload['amount'] ?? number_format((float) $order->price, 6, '.', '')),
            'base_amount' => (string) ($payload['base_amount'] ?? ''),
            'unique_amount' => (string) ($payload['unique_amount'] ?? ''),
            'expired_at' => $order->expired_at?->toIso8601String() ?: (string) ($payload['expires_at'] ?? ''),
            'remaining_seconds' => $this->remainingSeconds($order),
            'quantity' => (int) $order->quantity,
        ];
    }

    private function remainingSeconds(Order $order): int
    {
        if (! $order->expired_at) {
            return 0;
        }

        return max(0, (int) now()->diffInSeconds($order->expired_at, false));
    }

    private function isPastCryptoSelfServiceVerifyWindow(Order $order): bool
    {
        if (! $order->expired_at || $order->expired_at->isFuture()) {
            return false;
        }

        $verifyMinutes = max(0, (int) config('services.crypto_direct.self_service_verify_minutes', 60));

        return $order->expired_at->copy()->addMinutes($verifyMinutes)->lte(now());
    }

    private function isPastBinancePaySelfServiceVerifyWindow(Order $order): bool
    {
        if (! $order->expired_at || $order->expired_at->isFuture()) {
            return false;
        }

        $verifyMinutes = max(0, (int) config('services.binance.pay.self_service_verify_minutes', 60));

        return $order->expired_at->copy()->addMinutes($verifyMinutes)->lte(now());
    }

    private function retryCryptoNetwork(Order $order): string
    {
        $payload = $order->payment_payload;
        $network = is_array($payload) ? strtolower(trim((string) ($payload['network'] ?? ''))) : '';
        $configuredNetworks = array_keys(config('services.crypto_direct.networks', []));

        return in_array($network, $configuredNetworks, true) ? $network : 'usdttrc20';
    }

    private function retryBinancePayToken(Order $order): string
    {
        $payload = $order->payment_payload;
        $token = is_array($payload) ? strtoupper(trim((string) ($payload['token'] ?? ''))) : '';

        return in_array($token, ['USDT', 'USDC'], true)
            ? $token
            : strtoupper((string) config('services.binance.pay.token', 'USDT'));
    }

    private function syncPaymentResponse(Request $request, array $payload, int $status = 200)
    {
        if ($this->wantsPaymentJson($request)) {
            return response()->json($payload, $status);
        }

        if (($payload['status'] ?? null) === 'paid') {
            $orderId = (string) ($payload['order_id'] ?? '');
            $target = $orderId !== ''
                ? '/licenses?order='.rawurlencode($orderId).'#license-'.$orderId
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

        $licenses = License::where('order_id', $order->order_id)->oldest('id')->get();
        $payload['quantity'] = max(1, (int) $order->quantity);
        $payload['delivered_count'] = $licenses->count();

        if ($licenses->isEmpty()) {
            return $payload;
        }

        $payload['license_key'] = $licenses->first()->license_key;
        $payload['license_keys'] = $licenses->pluck('license_key')->all();

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
            if ($order->payment_method === 'binance_pay') {
                $result = $this->binancePayOrderVerifier->verify($order);

                if (($result['status'] ?? null) === 'paid') {
                    throw new \Exception('A previous payment was already completed');
                }
            }

            if (
                $order->payment_method === 'crypto' &&
                $order->expired_at &&
                $order->expired_at
                    ->copy()
                    ->addMinutes(max(0, (int) config('services.crypto_direct.grace_minutes', 2)))
                    ->isFuture()
            ) {
                continue;
            }

            $cancelledOrder = $this->cancelPendingOrder($order);

            if ($cancelledOrder->status === 'paid') {
                throw new \Exception('A previous payment was already completed');
            }
        }
    }

    private function cancelBeforeReplacement(Order $order): bool
    {
        if ($order->payment_method === 'binance_pay') {
            $result = $this->binancePayOrderVerifier->verify($order);

            if (($result['status'] ?? null) === 'paid') {
                return false;
            }
        }

        return $this->cancelPendingOrder($order)->status === 'cancelled';
    }

    private function activePendingOrder(int $userId, ?int $exceptOrderId = null): ?Order
    {
        $cryptoGraceCutoff = now()->subMinutes(max(0, (int) config('services.crypto_direct.grace_minutes', 2)));

        return Order::where('user_id', $userId)
            ->where('status', 'pending')
            ->when($exceptOrderId, fn ($query) => $query->whereKeyNot($exceptOrderId))
            ->where(function ($query) use ($cryptoGraceCutoff) {
                $query->whereNull('expired_at')
                    ->orWhere(function ($activeCrypto) use ($cryptoGraceCutoff): void {
                        $activeCrypto->where('payment_method', 'crypto')
                            ->where('expired_at', '>', $cryptoGraceCutoff);
                    })
                    ->orWhere(function ($activeNonCrypto): void {
                        $activeNonCrypto->where('payment_method', '!=', 'crypto')
                            ->where('expired_at', '>', now());
                    });
            })
            ->latest()
            ->first();
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

    private function cancelOrderResponse(Request $request, Order $order)
    {
        if ($order->status === 'paid') {
            return $this->syncPaymentResponse($request, $this->withLicensePayload([
                'order_id' => $order->order_id,
                'status' => 'paid',
                'message' => 'Payment was completed before the cancellation finished.',
            ], $order));
        }

        if ($order->status !== 'cancelled') {
            return $this->orderActionResponse($request, [
                'order_id' => $order->order_id,
                'status' => $order->status,
                'message' => 'This order could not be cancelled. Please check its latest status.',
            ], 409);
        }

        return $this->orderActionResponse($request, [
            'order_id' => $order->order_id,
            'status' => 'cancelled',
            'message' => 'Order cancelled. You can start a new checkout now.',
        ]);
    }

    private function wantsPaymentJson(Request $request): bool
    {
        return $request->expectsJson() || $request->ajax();
    }

    private function runCheckoutLocked(Request $request, int $userId, callable $callback)
    {
        try {
            return $this->checkoutLockService->run($userId, $callback);
        } catch (LockTimeoutException) {
            return $this->paymentErrorResponse(
                $request,
                'Another checkout is being prepared. Please wait a moment and try again.',
                409
            );
        }
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

        if ($message === 'Binance Pay checkout is not configured') {
            $message = 'Binance Pay checkout is not configured yet.';
        }

        if (in_array($message, [
            'GoPay QRIS checkout is not configured',
            'GoPay QRIS merchant identity does not match',
        ], true)) {
            $message = 'QRIS checkout is not configured yet.';
        }

        if (
            ! in_array($message, [
                'Crypto checkout is not configured yet.',
                'Binance Pay checkout is not configured yet.',
                'QRIS checkout is not configured yet.',
                'Invalid crypto amount',
            ], true) &&
            ! $this->isPublicCheckoutMessage($message) &&
            $error instanceof \Exception &&
            ! $error instanceof VoucherException
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

    private function isPublicCheckoutMessage(string $message): bool
    {
        return Str::startsWith($message, [
            'Your cart',
            'A product in your cart',
            'This product is not ready',
            'Only ',
            'Automatic delivery does not have enough license stock',
            'Select at least one license key',
            'QRIS checkout is currently unavailable.',
        ]);
    }
}
