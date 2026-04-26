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
            'order_id' => 'ORD-'.strtoupper(\Str::random(10)),
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

                return redirect()->route('midtrans.pay.page', ['token' => $payment['snap_token']]);
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

            return redirect()->route('midtrans.pay.page', ['token' => $payment['snap_token']]);
        } catch (\Exception $e) {
            Log::error('MIDTRANS ERROR: '.$e->getMessage());

            return $this->paymentErrorResponse($request, $e);
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

            if (
                $order->payment_method !== 'midtrans' ||
                ! $this->sameAmount($request->gross_amount, $order->price)
            ) {
                DB::rollBack();

                return response()->json(['error' => 'Invalid amount'], 403);
            }

            if ($order->status === 'paid') {
                DB::commit();

                return response()->json(['msg' => 'already']);
            }

            if (
                ($request->transaction_status === 'capture' && $request->fraud_status === 'accept') ||
                $request->transaction_status === 'settlement'
            ) {

                $this->generateLicense($order);
            }

            if (in_array($request->transaction_status, ['cancel', 'deny', 'expire'])) {

                if ($order->status !== 'paid') {
                    $order->update(['status' => 'cancelled']);
                }
            }

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

    private function generateLicense($order)
    {
        if ($order->status === 'paid' || License::where('order_id', $order->order_id)->exists()) {
            return;
        }

        $package = Package::findOrFail($order->package_id);

        $stock = LicenseStock::where('product_id', $order->product_id)
            ->where('package_id', $package->id)
            ->where('is_sold', false)
            ->lockForUpdate()
            ->first();

        if (! $stock || $stock->is_sold) {
            throw new \Exception('Stock invalid');
        }

        $stock->update([
            'is_sold' => true,
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
            ->where('created_at', '>', now()->subMinute())
            ->count() >= 5;
    }

    private function cancelPendingOrders(int $userId): void
    {
        Order::where('user_id', $userId)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);
    }

    private function sameAmount($first, $second): bool
    {
        return round((float) $first, 4) === round((float) $second, 4);
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

    private function paymentErrorResponse(Request $request, \Exception|string $error, int $status = 422)
    {
        $message = $error instanceof \Exception ? $error->getMessage() : $error;

        if (! str_starts_with($message, 'Minimum crypto payment') && $error instanceof \Exception) {
            $message = 'Payment failed';
        }

        if ($this->wantsPaymentJson($request)) {
            return response()->json([
                'message' => $message,
            ], $status);
        }

        return back()->withErrors([
            'payment' => $message,
        ]);
    }
}
