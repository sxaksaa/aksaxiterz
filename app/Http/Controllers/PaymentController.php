<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

use App\Models\Order;
use App\Models\License;
use App\Models\Package;
use App\Models\LicenseStock;
use App\Services\PaymentService;

class PaymentController extends Controller
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /*
    |--------------------------------------------------------------------------
    | MIDTRANS (PAY)
    |--------------------------------------------------------------------------
    */

    public function payMidtrans(Request $request, $id)
    {
        $user = Auth::user();

        $request->validate([
            'package_id' => 'required|exists:packages,id'
        ]);

        Order::where('user_id', $user->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);

        try {
            $snapToken = $this->paymentService->createMidtrans(
                $user,
                $id,
                $request->package_id
            );

            return view('midtrans-pay', compact('snapToken'));
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
        Log::info('MIDTRANS CALLBACK', $request->all());

        $serverKey = config('midtrans.serverKey');

        $hashed = hash(
            'sha512',
            $request->order_id .
            $request->status_code .
            $request->gross_amount .
            $serverKey
        );

        if ($hashed !== $request->signature_key) {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        DB::beginTransaction();

        try {

            $order = Order::where('order_id', $request->order_id)->firstOrFail();

            if ($order->status === 'paid') {
                return response()->json(['msg' => 'already']);
            }

            if (
                ($request->transaction_status === 'capture' && $request->fraud_status === 'accept') ||
                $request->transaction_status === 'settlement'
            ) {

                $this->generateLicense($order);
            }

            if (in_array($request->transaction_status, ['cancel', 'deny', 'expire'])) {
                $order->update(['status' => 'cancelled']);
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

        $request->validate([
            'package_id' => 'required|exists:packages,id',
            'coin' => 'required|string|max:20'
        ]);

        Order::where('user_id', $user->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);

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
                'payment' => $e->getMessage()
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

        $expected = hash_hmac(
            'sha512',
            $request->getContent(),
            config('services.nowpayments.key')
        );

        if (!$signature || $signature !== $expected) {
            return response()->json(['error' => 'invalid signature']);
        }

        $data = $request->all();

        if (($data['payment_status'] ?? '') !== 'finished') {
            return response()->json(['status' => 'not finished']);
        }

        DB::beginTransaction();

        try {

            $order = Order::where('order_id', $data['order_id'])->firstOrFail();

            if ($order->status === 'paid') {
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
    | 🔥 CORE LOGIC (DIPAKAI SEMUA PAYMENT)
    |--------------------------------------------------------------------------
    */

    private function generateLicense($order)
    {
        $package = Package::findOrFail($order->package_id);

        $stock = LicenseStock::where('product_id', $order->product_id)
            ->where('package_id', $package->id)
            ->where('is_sold', false)
            ->lockForUpdate()
            ->first();

        if (!$stock) {
            throw new \Exception('Stock habis 😭');
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
}