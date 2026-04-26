<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\LicenseStock;
use App\Models\Product;
use App\Models\Order;
use App\Models\Package;

class PaymentService
{
    private const ALLOWED_COINS = [
        'usdttrc20',
        'usdtbsc',
        'usdterc20',
        'usdtmatic',
        'usdtton',
    ];

    public function createMidtrans($user, $productId, $packageId)
    {
        $product = Product::findOrFail($productId);

        $package = Package::where('id', $packageId)
            ->where('product_id', $productId)
            ->firstOrFail();

        $stock = LicenseStock::where('product_id', $product->id)
            ->where('package_id', $package->id)
            ->where('is_sold', false)
            ->first();

        if (!$stock) {
            throw new \Exception('Stock habis');
        }

        $orderId = 'ORD-' . strtoupper(Str::random(10));

        $order = Order::create([
            'order_id' => $orderId,
            'product_id' => $product->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'payment_method' => 'midtrans',
            'price' => $package->price,
            'package_id' => $package->id,
            'expired_at' => now()->addMinutes(10),
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

        try {
            $snapToken = \Midtrans\Snap::getSnapToken($params);
        } catch (\Exception $e) {
            $order->update(['status' => 'cancelled']);

            throw $e;
        }

        return $snapToken;
    }

    public function createCrypto($user, $productId, $packageId, $coin)
    {
        $coin = strtolower($coin);

        if (!in_array($coin, self::ALLOWED_COINS, true)) {
            throw new \Exception('Invalid payment method');
        }

        $product = Product::findOrFail($productId);

        $package = Package::where('id', $packageId)
            ->where('product_id', $productId)
            ->firstOrFail();

        $stock = LicenseStock::where('product_id', $product->id)
            ->where('package_id', $package->id)
            ->where('is_sold', false)
            ->first();

        if (!$stock) {
            throw new \Exception('Stock habis');
        }

        $amount = $package->price_usdt;
        // 🔥 CUSTOM MINIMUM (PUNYA KAMU SENDIRI)
        $customMin = [
            'usdttrc20' => 10, // TRC20 = $10
            'usdtbsc' => 1,
            'usdterc20' => 1,
            'usdtmatic' => 1,
            'usdtton' => 1,
        ];

        if (isset($customMin[$coin]) && $amount < $customMin[$coin]) {
            throw new \Exception("Minimum payment {$coin} is \${$customMin[$coin]}");
        }

        $orderId = 'ORD-' . strtoupper(Str::random(10));

        $order = Order::create([
            'order_id' => $orderId,
            'product_id' => $product->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'payment_method' => 'crypto',
            'price' => $package->price_usdt,
            'package_id' => $package->id,
            'expired_at' => now()->addMinutes(10),
        ]);

        try {
            $response = Http::withHeaders([
                'x-api-key' => config('services.nowpayments.key')
            ])->post(config('services.nowpayments.url') . '/invoice', [
                'price_amount' => $package->price_usdt,
                'price_currency' => 'usd',
                'pay_currency' => $coin,
                'is_fixed_rate' => false,
                'order_id' => $orderId,
                'order_description' => $product->name . ' - ' . $package->name,
                'ipn_callback_url' => config('services.nowpayments.ipn'),
                'success_url' => url('/licenses'),
                'cancel_url' => url('/'),
            ]);

            $data = $response->json();

            if (!isset($data['invoice_url'])) {
                Log::warning('NOWPayments invoice response missing URL', [
                    'order_id' => $orderId,
                    'status' => $response->status(),
                ]);

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

    private function ensureNowpaymentsMinimum(string $coin, float $amount): void
    {
        $apiKey = config('services.nowpayments.key');
        $baseUrl = config('services.nowpayments.url');

        if (!$apiKey || !$baseUrl) {
            return;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
            ])->get(rtrim($baseUrl, '/') . '/min-amount', [
                'currency_from' => 'usd',
                'currency_to' => $coin,
                'fiat_equivalent' => 'usd',
                'is_fixed_rate' => false,
            ]);

            if (!$response->successful()) {
                return;
            }

            $data = $response->json();
            $minimum = $this->extractMinimumUsd($data);

            if ($minimum !== null && $amount < $minimum) {
                throw new \Exception("Minimum crypto payment for {$coin} is about \${$minimum}");
            }
        } catch (\Exception $e) {
            if (str_starts_with($e->getMessage(), 'Minimum crypto payment')) {
                throw $e;
            }

            Log::warning('NOWPayments minimum check failed: ' . $e->getMessage());
        }
    }

    private function extractMinimumUsd(array $data): ?float
    {
        foreach (['fiat_equivalent', 'min_amount', 'min_amount_fiat'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (float) $data[$key];
            }
        }

        return null;
    }
}
