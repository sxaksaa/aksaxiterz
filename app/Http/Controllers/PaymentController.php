<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

use App\Models\Order;
use App\Models\License;
use App\Models\Package;
use App\Models\LicenseStock;
use App\Services\PaymentService;

class PaymentController extends Controller
{
    protected PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function payAgain($orderId)
    {
        $user = Auth::user();

        $oldOrder = Order::findOrFail($orderId);

        if ($oldOrder->user_id !== $user->id) {
            abort(403);
        }

        if ($oldOrder->status === 'paid') {
            return back()->withErrors(['msg' => 'Already paid']);
        }

        // 🔥 buat order baru
        $newOrder = Order::create([
            'order_id' => 'ORD-' . strtoupper(\Str::random(10)),
            'user_id' => $user->id,
            'product_id' => $oldOrder->product_id,
            'package_id' => $oldOrder->package_id,
            'status' => 'pending',
            'payment_method' => $oldOrder->payment_method,
            'price' => $oldOrder->price,
            'expired_at' => now()->addMinutes(10),
        ]);

        // 🔥 tandai order lama diganti
        $oldOrder->update([
            'status' => 'cancelled',
            'replaced_by' => $newOrder->id
        ]);

        // 🔥 cancel pending lain (opsional)
        Order::where('user_id', $user->id)
            ->where('status', 'pending')
            ->where('id', '!=', $newOrder->id)
            ->update(['status' => 'cancelled']);

        // 🔥 lanjut payment
        if ($newOrder->payment_method === 'midtrans') {

            $snapToken = $this->paymentService->createMidtrans(
                $user,
                $newOrder->product_id,
                $newOrder->package_id
            );

            return redirect()->route('midtrans.pay.page', ['token' => $snapToken]);
        } else {

            $url = $this->paymentService->createCrypto(
                $user,
                $newOrder->product_id,
                $newOrder->package_id,
                'usdttrc20'
            );

            return redirect($url);
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
            return back()->withErrors([
                'payment' => 'Terlalu banyak request, coba lagi nanti'
            ]);
        }

        if ($this->hasPendingOrder($user->id)) {
            return redirect('/orders')
                ->with('info', 'Kamu masih punya pembayaran aktif, lanjutkan di halaman Orders');
        }

        $request->validate([
            'package_id' => 'required|exists:packages,id'
        ]);

        try {
            $snapToken = $this->paymentService->createMidtrans(
                $user,
                $id,
                $request->package_id
            );

            return redirect()->route('midtrans.pay.page', ['token' => $snapToken]);
        } catch (\Exception $e) {
            Log::error('MIDTRANS ERROR: ' . $e->getMessage());

            return back()->withErrors([
                'payment' => 'Payment failed'
            ]);
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

        if (!$serverKey) {
            Log::error('MIDTRANS CALLBACK ERROR: missing server key');

            return response()->json(['error' => 'server not configured'], 500);
        }

        $hashed = hash(
            'sha512',
            $request->order_id .
                $request->status_code .
                $request->gross_amount .
                $serverKey
        );

        if (!hash_equals($hashed, (string) $request->signature_key)) {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        DB::beginTransaction();

        try {
            $order = Order::where('order_id', $request->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $order->payment_method !== 'midtrans' ||
                !$this->sameAmount($request->gross_amount, $order->price)
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

            Log::error('MIDTRANS CALLBACK ERROR: ' . $e->getMessage());

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
            return back()->withErrors([
                'payment' => 'Terlalu banyak request'
            ]);
        }

        if ($this->hasPendingOrder($user->id)) {
            return redirect('/orders')
                ->with('info', 'Kamu masih punya pembayaran aktif, lanjutkan di halaman Orders');
        }

        $request->validate([
            'package_id' => 'required|exists:packages,id',
            'coin' => 'required|string|max:20'
        ]);

        try {
            $url = $this->paymentService->createCrypto(
                $user,
                $productId,
                $request->package_id,
                $request->coin
            );

            return redirect($url);
        } catch (\Exception $e) {

            Log::error('CRYPTO ERROR: ' . $e->getMessage());

            return back()->withErrors([
                'payment' => str_starts_with($e->getMessage(), 'Minimum crypto payment')
                    ? $e->getMessage()
                    : 'Payment failed'
            ]);
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

        if (!$ipnSecret) {
            Log::error('NOWPayments CALLBACK ERROR: missing IPN secret');

            return response()->json(['error' => 'server not configured'], 500);
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return response()->json(['error' => 'invalid payload'], 400);
        }

        $expected = $this->nowpaymentsSignature($data, $ipnSecret);

        if (!$signature || !hash_equals($expected, $signature)) {
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
                !$this->sameAmount($data['price_amount'] ?? null, $order->price)
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

            Log::error('CRYPTO CALLBACK ERROR: ' . $e->getMessage());

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

        if (!$stock || $stock->is_sold) {
            throw new \Exception('Stock invalid');
        }

        $stock->update([
            'is_sold' => true
        ]);

        $order->update([
            'status' => 'paid'
        ]);

        License::create([
            'user_id' => $order->user_id,
            'product_id' => $order->product_id,
            'license_key' => $stock->license_key,
            'duration' => $package->name,
            'order_id' => $order->order_id
        ]);
    }

    private function hasTooManyRecentOrders(int $userId): bool
    {
        return Order::where('user_id', $userId)
            ->where('created_at', '>', now()->subMinute())
            ->count() >= 5;
    }

    private function hasPendingOrder(int $userId): bool
    {
        return Order::where('user_id', $userId)
            ->where('status', 'pending')
            ->where(function ($query) {
                $query->whereNull('expired_at')
                    ->orWhere('expired_at', '>', now());
            })
            ->exists();
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
}
