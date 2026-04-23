<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

use App\Models\Product;
use App\Models\Order;
use App\Models\Package;

class PaymentService
{
    public function createMidtrans($user, $productId, $packageId)
    {
        $product = Product::findOrFail($productId);

        $package = Package::where('id', $packageId)
            ->where('product_id', $productId)
            ->firstOrFail();

        $orderId = 'ORD-' . strtoupper(Str::random(10));

        $order = Order::create([
            'order_id' => $orderId,
            'product_id' => $product->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'payment_method' => 'midtrans',
            'price' => $package->price,
            'package_id' => $package->id
        ]);

        \Midtrans\Config::$serverKey = config('midtrans.serverKey');
        \Midtrans\Config::$isProduction = config('midtrans.isProduction', false);

        $params = [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => $package->price,
            ],
            'customer_details' => [
                'first_name' => $user->name,
                'email' => $user->email,
            ]
        ];

        $snapToken = \Midtrans\Snap::getSnapToken($params);

        return $snapToken;
    }

    public function createCrypto($user, $productId, $packageId, $coin)
    {
        $allowedCoins = ['usdttrc20', 'usdtbsc', 'usdterc20', 'usdtmatic', 'usdtton'];

        if (!in_array($coin, $allowedCoins)) {
            throw new \Exception('Invalid payment method');
        }

        $product = Product::findOrFail($productId);

        $package = Package::where('id', $packageId)
            ->where('product_id', $productId)
            ->firstOrFail();

        $orderId = 'ORD-' . strtoupper(Str::random(10));

        $order = Order::create([
            'order_id' => $orderId,
            'product_id' => $product->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'payment_method' => 'crypto',
            'price' => $package->price_usdt,
            'package_id' => $package->id
        ]);
        $minMap = [
            'usdterc20' => 1,
            'usdttrc20' => 10,
            'usdtbsc' => 1,
            'usdtmatic' => 1,
            'usdtton' => 1
        ];

        $amount = $package->price_usdt;

        if (isset($minMap[$coin]) && $amount < $minMap[$coin]) {
            throw new \Exception("Miniimum Payment {$coin} is \${$minMap[$coin]}");
        }
        try {

            $response = Http::withHeaders([
                'x-api-key' => config('services.nowpayments.key')
            ])->post(config('services.nowpayments.url') . '/invoice', [

                "price_amount" => $package->price_usdt,
                "price_currency" => "usd",
                "pay_currency" => $coin,

                "order_id" => $orderId,
                "order_description" => $product->name . ' - ' . $package->name,

                "ipn_callback_url" => config('services.nowpayments.ipn'),

                "success_url" => url('/licenses'),
                "cancel_url" => url('/'),
            ]);

            $data = $response->json();

            if (!isset($data['invoice_url'])) {
                throw new \Exception('No invoice URL');
            }

            $order->update([
                'payment_url' => $data['invoice_url']
            ]);

            return $data['invoice_url'];
        } catch (\Exception $e) {

            Log::error('CRYPTO ERROR: ' . $e->getMessage());

            $order->update(['status' => 'cancelled']);

            throw $e;
        }
    }
}
