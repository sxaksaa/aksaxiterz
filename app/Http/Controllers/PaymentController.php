<?php

namespace App\Http\Controllers;

use App\Models\License;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    protected PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
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

        $newOrder = Order::create([
            'order_id' => 'ORD-'.strtoupper(Str::random(10)),
            'user_id' => $user->id,
            'product_id' => $oldOrder->product_id,
            'package_id' => $oldOrder->package_id,
            'status' => 'pending',
            'payment_method' => $oldOrder->payment_method,
            'price' => $oldOrder->price,
            'expired_at' => now()->addMinutes(10),
        ]);

        $oldOrder->update([
            'status' => 'cancelled',
            'replaced_by' => $newOrder->id,
        ]);

        Order::where('user_id', $user->id)
            ->where('status', 'pending')
            ->where('id', '!=', $newOrder->id)
            ->update(['status' => 'cancelled']);

        try {
            if ($newOrder->payment_method === 'midtrans') {

                $payment = $this->paymentService->createMidtransPayment(
                    $user,
                    $newOrder->product_id,
                    $newOrder->package_id,
                    $newOrder
                );

                if ($this->wantsPaymentJson($request)) {
                    return response()->json([
                        'method' => 'midtrans',
                        'snap_token' => $payment['snap_token'],
                        'order_id' => $payment['order']->order_id,
                    ]);
                }

                return redirect()->route('midtrans.pay.page', [
                    'token' => $payment['snap_token'],
                    'order_id' => $payment['order']->order_id,
                ]);
            }

            $payment = $this->paymentService->createCryptoPayment(
                $user,
                $newOrder->product_id,
                $newOrder->package_id,
                'usdttrc20',
                $newOrder
            );

            if ($this->wantsPaymentJson($request)) {
                return response()->json([
                    'method' => 'crypto',
                    'payment_url' => $payment['payment_url'],
                    'order_id' => $payment['order']->order_id,
                ]);
            }

            return redirect($payment['payment_url']);
        } catch (\Exception $e) {
            $newOrder->update(['status' => 'cancelled']);

            Log::error('PAY AGAIN ERROR: '.$e->getMessage());

            return $this->paymentErrorResponse($request, $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | MIDTRANS (PAY)
    |--------------------------------------------------------------------------
    */

    public function payMidtrans(Request $request, $id)
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

        $this->cancelPendingOrders($user->id);

        try {
            $payment = $this->paymentService->createMidtransPayment(
                $user,
                $id,
                $request->package_id
            );

            if ($this->wantsPaymentJson($request)) {
                return response()->json([
                    'method' => 'midtrans',
                    'snap_token' => $payment['snap_token'],
                    'order_id' => $payment['order']->order_id,
                ]);
            }

            return redirect()->route('midtrans.pay.page', [
                'token' => $payment['snap_token'],
                'order_id' => $payment['order']->order_id,
            ]);
        } catch (\Exception $e) {
            Log::error('MIDTRANS ERROR: '.$e->getMessage());

            return $this->paymentErrorResponse($request, $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | MIDTRANS STATUS SYNC
    |--------------------------------------------------------------------------
    */

    public function syncMidtransOrder(Request $request, string $orderId)
    {
        $order = Order::where('order_id', $orderId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($order->payment_method !== 'midtrans') {
            abort(404);
        }

        if ($order->status === 'paid') {
            return $this->syncCryptoResponse($request, [
                'order_id' => $order->order_id,
                'status' => $order->status,
            ]);
        }

        try {
            $payload = $this->paymentService->getMidtransStatus($order->order_id);

            DB::beginTransaction();

            $lockedOrder = Order::whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->validMidtransOrderPayload($lockedOrder, $payload)) {
                DB::rollBack();

                return response()->json(['error' => 'Invalid Midtrans status'], 403);
            }

            if ($lockedOrder->status !== 'paid') {
                $this->applyMidtransStatus($lockedOrder, $payload);
            }

            DB::commit();

            return response()->json([
                'order_id' => $lockedOrder->order_id,
                'status' => $lockedOrder->fresh()->status,
            ]);
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::warning('MIDTRANS SYNC ERROR: '.$e->getMessage(), [
                'order_id' => $order->order_id,
            ]);

            return response()->json([
                'order_id' => $order->order_id,
                'status' => $order->fresh()->status,
                'message' => 'Payment is still being verified.',
            ], 202);
        }
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
            return $this->syncCryptoResponse($request, [
                'order_id' => $order->order_id,
                'status' => $order->status,
            ]);
        }

        $paymentId = $this->nowpaymentsPaymentIdFromRequest($request) ?? $this->nowpaymentsPaymentId($order);
        $payload = null;

        try {
            if (! $paymentId) {
                $invoiceId = $this->nowpaymentsInvoiceId($order);

                if ($invoiceId) {
                    $payload = $this->paymentService->findNowpaymentsPaymentByInvoice($invoiceId, $order->order_id);
                    $paymentId = $payload['payment_id'] ?? null;
                }
            }

            if (! $paymentId) {
                return $this->syncCryptoResponse($request, [
                    'order_id' => $order->order_id,
                    'status' => $order->status,
                    'message' => 'Payment is not visible from NOWPayments yet. Please click Verify again after the invoice updates.',
                ], 202);
            }

            if (! $payload) {
                $payload = $this->paymentService->getNowpaymentsPayment($paymentId);
            }

            $this->rememberNowpaymentsPaymentId($order, (string) $paymentId);

            if (! $this->validNowpaymentsOrderPayload($order, $payload)) {
                Log::warning('CRYPTO SYNC INVALID PAYLOAD', [
                    'order_id' => $order->order_id,
                    'payment_id' => $paymentId,
                    'order_price' => (string) $order->price,
                    'provider_order_id' => $payload['order_id'] ?? null,
                    'provider_price_amount' => $payload['price_amount'] ?? null,
                    'provider_price_currency' => $payload['price_currency'] ?? null,
                    'provider_status' => $payload['payment_status'] ?? null,
                ]);

                return $this->syncCryptoResponse($request, [
                    'error' => 'NOWPayments data does not match this order.',
                ], 403);
            }

            $providerStatus = strtolower((string) ($payload['payment_status'] ?? ''));
            $chainTransfer = null;
            $paymentVerified = $providerStatus === 'finished';

            if (! $paymentVerified) {
                $chainTransfer = $this->paymentService->findUsdtBscInvoiceTransfer($payload);
                $paymentVerified = $chainTransfer !== null;
            }

            if (! $paymentVerified) {
                return $this->syncCryptoResponse($request, [
                    'order_id' => $order->order_id,
                    'status' => $order->fresh()->status,
                    'provider_status' => $providerStatus,
                    'message' => 'Crypto payment is still being verified.',
                ], 202);
            }

            DB::beginTransaction();

            $lockedOrder = Order::whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->validNowpaymentsOrderPayload($lockedOrder, $payload)) {
                DB::rollBack();

                return $this->syncCryptoResponse($request, [
                    'error' => 'NOWPayments data does not match this order.',
                ], 403);
            }

            if ($lockedOrder->status !== 'paid') {
                $this->generateLicense($lockedOrder);
            }

            DB::commit();

            if ($chainTransfer) {
                Log::info('CRYPTO ON-CHAIN FALLBACK VERIFIED', [
                    'order_id' => $lockedOrder->order_id,
                    'tx_hash' => $chainTransfer['tx_hash'] ?? null,
                ]);
            }

            return $this->syncCryptoResponse($request, [
                'order_id' => $lockedOrder->order_id,
                'status' => $lockedOrder->fresh()->status,
                'provider_status' => $providerStatus,
                'tx_hash' => $chainTransfer['tx_hash'] ?? null,
            ]);
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::warning('CRYPTO SYNC ERROR: '.$e->getMessage(), [
                'order_id' => $order->order_id,
                'payment_id' => $paymentId,
            ]);

            return $this->syncCryptoResponse($request, [
                'order_id' => $order->order_id,
                'status' => $order->fresh()->status,
                'message' => $this->publicCryptoSyncError($e),
            ], 202);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | MIDTRANS CALLBACK
    |--------------------------------------------------------------------------
    */

    public function midtransCallback(Request $request)
    {
        $serverKey = config('midtrans.serverKey');

        if (! $serverKey) {
            Log::error('MIDTRANS CALLBACK ERROR: missing server key');

            return response()->json(['error' => 'server not configured'], 500);
        }

        $hashed = hash(
            'sha512',
            $request->order_id.
                $request->status_code.
                $request->gross_amount.
                $serverKey
        );

        if (! hash_equals($hashed, (string) $request->signature_key)) {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        DB::beginTransaction();

        try {
            $order = Order::where('order_id', $request->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->validMidtransOrderPayload($order, $request->all())) {
                DB::rollBack();

                return response()->json(['error' => 'Invalid amount'], 403);
            }

            if ($order->status === 'paid') {
                DB::commit();

                return response()->json(['msg' => 'already']);
            }

            $this->applyMidtransStatus($order, $request->all());

            DB::commit();

            return response()->json(['msg' => 'ok']);
        } catch (\Exception $e) {

            DB::rollBack();

            Log::error('MIDTRANS CALLBACK ERROR: '.$e->getMessage());

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
            'coin' => 'required|string|max:20',
        ]);

        $this->cancelPendingOrders($user->id);

        try {
            $payment = $this->paymentService->createCryptoPayment(
                $user,
                $productId,
                $request->package_id,
                $request->coin
            );

            if ($this->wantsPaymentJson($request)) {
                return response()->json([
                    'method' => 'crypto',
                    'payment_url' => $payment['payment_url'],
                    'order_id' => $payment['order']->order_id,
                ]);
            }

            return redirect($payment['payment_url']);
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

        if ($order->status !== 'cancelled') {
            $order->update([
                'status' => 'cancelled',
                'expired_at' => now(),
            ]);
        }

        return $this->orderActionResponse($request, [
            'order_id' => $order->order_id,
            'status' => 'cancelled',
            'message' => 'Order cancelled. You can start a new checkout now.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CRYPTO CALLBACK
    |--------------------------------------------------------------------------
    */

    public function cryptoCallback(Request $request)
    {
        $signature = $request->header('x-nowpayments-sig');
        $ipnSecret = config('services.nowpayments.ipn_secret');

        if (! $ipnSecret) {
            Log::error('NOWPayments CALLBACK ERROR: missing IPN secret');

            return response()->json(['error' => 'server not configured'], 500);
        }

        $data = json_decode($request->getContent(), true);

        if (! is_array($data)) {
            return response()->json(['error' => 'invalid payload'], 400);
        }

        $expected = $this->nowpaymentsSignature($data, $ipnSecret);

        if (! $signature || ! hash_equals($expected, $signature)) {
            return response()->json(['error' => 'invalid signature'], 403);
        }

        if (($data['payment_status'] ?? '') !== 'finished') {
            return response()->json(['status' => 'not finished']);
        }

        DB::beginTransaction();

        try {
            $order = Order::where('order_id', $data['order_id'] ?? null)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $order->payment_method !== 'crypto' ||
                strtolower((string) ($data['price_currency'] ?? '')) !== 'usd' ||
                ! $this->sameAmount($data['price_amount'] ?? null, $order->price)
            ) {
                DB::rollBack();

                return response()->json(['error' => 'invalid amount'], 403);
            }

            if ($order->status === 'paid') {
                DB::commit();

                return response()->json(['status' => 'already']);
            }

            $this->generateLicense($order);

            DB::commit();

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {

            DB::rollBack();

            Log::error('CRYPTO CALLBACK ERROR: '.$e->getMessage());

            return response()->json(['error' => 'failed'], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CORE LOGIC
    |--------------------------------------------------------------------------
    */

    private function validMidtransOrderPayload(Order $order, array $payload): bool
    {
        $payloadOrderId = $payload['order_id'] ?? null;
        $grossAmount = $payload['gross_amount'] ?? null;
        $originalAmount = $payload['extra_info']['gross_amount_info']['original_amount'] ?? null;

        if (
            $order->payment_method !== 'midtrans' ||
            ! is_scalar($payloadOrderId) ||
            ! hash_equals($order->order_id, (string) $payloadOrderId) ||
            ! is_numeric($grossAmount)
        ) {
            return false;
        }

        if (is_numeric($originalAmount)) {
            return $this->sameAmount($originalAmount, $order->price);
        }

        return $this->sameAmount($grossAmount, $order->price);
    }

    private function syncCryptoResponse(Request $request, array $payload, int $status = 200)
    {
        if ($this->wantsPaymentJson($request)) {
            return response()->json($payload, $status);
        }

        if (($payload['status'] ?? null) === 'paid') {
            return redirect('/licenses');
        }

        return redirect('/orders')->with(
            'info',
            $payload['message'] ?? $payload['error'] ?? 'Crypto payment is still being verified.'
        );
    }

    private function publicCryptoSyncError(\Exception $error): string
    {
        if ($error->getMessage() === 'No license stock available for this package') {
            return 'Payment is verified, but no license stock is available for this package.';
        }

        if ($error->getMessage() === 'Unable to verify crypto payment') {
            return 'NOWPayments could not be reached. Please try Verify again.';
        }

        return 'Crypto payment is still being verified.';
    }

    private function validNowpaymentsOrderPayload(Order $order, array $payload): bool
    {
        return $order->payment_method === 'crypto' &&
            hash_equals($order->order_id, (string) ($payload['order_id'] ?? '')) &&
            strtolower((string) ($payload['price_currency'] ?? '')) === 'usd' &&
            $this->sameAmount($payload['price_amount'] ?? null, $order->price);
    }

    private function applyMidtransStatus(Order $order, array $payload): void
    {
        $transactionStatus = $payload['transaction_status'] ?? null;
        $fraudStatus = $payload['fraud_status'] ?? null;

        if (
            ($transactionStatus === 'capture' && $fraudStatus === 'accept') ||
            $transactionStatus === 'settlement'
        ) {
            $this->generateLicense($order);

            return;
        }

        if (in_array($transactionStatus, ['cancel', 'deny', 'expire'], true) && $order->status !== 'paid') {
            $order->update(['status' => 'cancelled']);
        }
    }

    private function generateLicense($order)
    {
        if ($order->status === 'paid') {
            return;
        }

        if (License::where('order_id', $order->order_id)->exists()) {
            $order->update([
                'status' => 'paid',
            ]);

            return;
        }

        $package = Package::findOrFail($order->package_id);

        $stock = LicenseStock::where('product_id', $order->product_id)
            ->where('package_id', $package->id)
            ->where('is_sold', false)
            ->lockForUpdate()
            ->first();

        if (! $stock || $stock->is_sold) {
            throw new \Exception('No license stock available for this package');
        }

        $stock->update([
            'is_sold' => true,
            'sold_at' => now(),
        ]);

        $order->update([
            'status' => 'paid',
        ]);

        License::create([
            'user_id' => $order->user_id,
            'product_id' => $order->product_id,
            'license_key' => $stock->license_key,
            'duration' => $package->name,
            'order_id' => $order->order_id,
        ]);
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
        Order::where('user_id', $userId)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);
    }

    private function activePendingOrder(int $userId): ?Order
    {
        return Order::where('user_id', $userId)
            ->where('status', 'pending')
            ->where(function ($query) {
                $query->whereNull('expired_at')
                    ->orWhere('expired_at', '>', now())
                    ->orWhere(function ($cryptoQuery) {
                        $cryptoQuery->where('payment_method', 'crypto')
                            ->where('created_at', '>', now()->subDay());
                    });
            })
            ->latest()
            ->first();
    }

    private function sameAmount($first, $second): bool
    {
        return round((float) $first, 4) === round((float) $second, 4);
    }

    private function nowpaymentsPaymentId(Order $order): ?string
    {
        if (! $order->payment_url) {
            return null;
        }

        $query = parse_url($order->payment_url, PHP_URL_QUERY);

        if (! $query) {
            return null;
        }

        parse_str($query, $params);

        $paymentId = $params['paymentId'] ?? $params['payment_id'] ?? null;

        if (! is_scalar($paymentId) || ! ctype_digit((string) $paymentId)) {
            return null;
        }

        return (string) $paymentId;
    }

    private function nowpaymentsPaymentIdFromRequest(Request $request): ?string
    {
        $paymentId = $request->query('paymentId') ?? $request->query('payment_id');

        if (! is_scalar($paymentId) || ! ctype_digit((string) $paymentId)) {
            return null;
        }

        return (string) $paymentId;
    }

    private function nowpaymentsInvoiceId(Order $order): ?string
    {
        if (! $order->payment_url) {
            return null;
        }

        $query = parse_url($order->payment_url, PHP_URL_QUERY);

        if (! $query) {
            return null;
        }

        parse_str($query, $params);

        $invoiceId = $params['iid'] ?? $params['invoice_id'] ?? $params['invoiceId'] ?? null;

        if (! is_scalar($invoiceId) || ! ctype_digit((string) $invoiceId)) {
            return null;
        }

        return (string) $invoiceId;
    }

    private function rememberNowpaymentsPaymentId(Order $order, string $paymentId): void
    {
        if (! $order->payment_url || $this->nowpaymentsPaymentId($order)) {
            return;
        }

        $parts = parse_url($order->payment_url);
        $query = [];

        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $query['paymentId'] = $paymentId;

        $url = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'nowpayments.io');

        if (! empty($parts['path'])) {
            $url .= $parts['path'];
        }

        $url .= '?'.http_build_query($query);

        $order->update([
            'payment_url' => $url,
        ]);
    }

    private function nowpaymentsSignature(array $payload, string $secret): string
    {
        $sortedPayload = $this->sortPayload($payload);
        $json = json_encode($sortedPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha512', $json, $secret);
    }

    private function sortPayload(array $payload): array
    {
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sortPayload($value);
            }
        }

        return $payload;
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

        if (! str_starts_with($message, 'Minimum crypto payment') && $error instanceof \Exception) {
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
