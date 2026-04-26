<?php

namespace App\Services;

use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Midtrans\Config;
use Midtrans\Snap;

class PaymentService
{
    private const ALLOWED_COINS = [
        'usdttrc20',
        'usdtbsc',
        'usdterc20',
        'usdtmatic',
        'usdtton',
    ];

    public function createMidtrans($user, $productId, $packageId, ?Order $order = null)
    {
        return $this->createMidtransPayment($user, $productId, $packageId, $order)['snap_token'];
    }

    public function createMidtransPayment($user, $productId, $packageId, ?Order $order = null): array
    {
        $product = Product::findOrFail($productId);

        $package = Package::where('id', $packageId)
            ->where('product_id', $productId)
            ->firstOrFail();

        if ($order) {
            $this->ensurePayableOrder($order, $user, $product->id, $package->id, 'midtrans');
        }

        $stock = LicenseStock::where('product_id', $product->id)
            ->where('package_id', $package->id)
            ->where('is_sold', false)
            ->first();

        if (! $stock) {
            throw new \Exception('Stock habis');
        }

        if (! $order) {
            $order = Order::create([
                'order_id' => 'ORD-'.strtoupper(Str::random(10)),
                'product_id' => $product->id,
                'user_id' => $user->id,
                'status' => 'pending',
                'payment_method' => 'midtrans',
                'price' => $package->price,
                'package_id' => $package->id,
                'expired_at' => now()->addMinutes(10),
            ]);
        }

        Config::$serverKey = config('midtrans.serverKey');
        Config::$isProduction = config('midtrans.isProduction', false);
        Config::$curlOptions = $this->gatewayCurlOptions();

        $params = [
            'transaction_details' => [
                'order_id' => $order->order_id,
                'gross_amount' => (int) round((float) $order->price),
            ],
            'customer_details' => [
                'first_name' => $user->name,
                'email' => $user->email,
            ],
        ];

        try {
            $snapToken = Snap::getSnapToken($params);
        } catch (\Exception $e) {
            $order->update(['status' => 'cancelled']);

            throw $e;
        }

        return [
            'snap_token' => $snapToken,
            'order' => $order->fresh(),
        ];
    }

    public function createCrypto($user, $productId, $packageId, $coin, ?Order $order = null)
    {
        return $this->createCryptoPayment($user, $productId, $packageId, $coin, $order)['payment_url'];
    }

    public function createCryptoPayment($user, $productId, $packageId, $coin, ?Order $order = null): array
    {
        $coin = strtolower($coin);

        if (! in_array($coin, self::ALLOWED_COINS, true)) {
            throw new \Exception('Invalid payment method');
        }

        $product = Product::findOrFail($productId);

        $package = Package::where('id', $packageId)
            ->where('product_id', $productId)
            ->firstOrFail();

        if ($order) {
            $this->ensurePayableOrder($order, $user, $product->id, $package->id, 'crypto');
        }

        $stock = LicenseStock::where('product_id', $product->id)
            ->where('package_id', $package->id)
            ->where('is_sold', false)
            ->first();

        if (! $stock) {
            throw new \Exception('Stock habis');
        }

        $amount = (float) ($order?->price ?? $package->price_usdt);
        $customMin = [
            'usdttrc20' => 10,
            'usdtbsc' => 1,
            'usdterc20' => 1,
            'usdtmatic' => 1,
            'usdtton' => 1,
        ];

        if (isset($customMin[$coin]) && $amount < $customMin[$coin]) {
            throw new \Exception("Minimum crypto payment for {$coin} is \${$customMin[$coin]}");
        }

        if (! $order) {
            $order = Order::create([
                'order_id' => 'ORD-'.strtoupper(Str::random(10)),
                'product_id' => $product->id,
                'user_id' => $user->id,
                'status' => 'pending',
                'payment_method' => 'crypto',
                'price' => $amount,
                'package_id' => $package->id,
                'expired_at' => now()->addMinutes(10),
            ]);
        }

        try {
            $response = Http::withOptions($this->gatewayHttpOptions())
                ->withHeaders([
                    'x-api-key' => config('services.nowpayments.key'),
                ])->post(config('services.nowpayments.url').'/invoice', [
                    'price_amount' => $amount,
                    'price_currency' => 'usd',
                    'pay_currency' => $coin,
                    'is_fixed_rate' => false,
                    'order_id' => $order->order_id,
                    'order_description' => $product->name.' - '.$package->name,
                    'ipn_callback_url' => config('services.nowpayments.ipn'),
                    'success_url' => url('/licenses'),
                    'cancel_url' => url('/'),
                ]);

            $data = $response->json();

            if (! isset($data['invoice_url'])) {
                Log::warning('NOWPayments invoice response missing URL', [
                    'order_id' => $order->order_id,
                    'status' => $response->status(),
                ]);

                throw new \Exception('No invoice URL');
            }

            $order->update([
                'payment_url' => $data['invoice_url'],
            ]);

            return [
                'payment_url' => $data['invoice_url'],
                'order' => $order->fresh(),
            ];
        } catch (\Exception $e) {

            Log::error('CRYPTO ERROR: '.$e->getMessage());

            $order->update(['status' => 'cancelled']);

            throw $e;
        }
    }

    private function ensureNowpaymentsMinimum(string $coin, float $amount): void
    {
        $apiKey = config('services.nowpayments.key');
        $baseUrl = config('services.nowpayments.url');

        if (! $apiKey || ! $baseUrl) {
            return;
        }

        try {
            $response = Http::withOptions($this->gatewayHttpOptions())
                ->withHeaders([
                    'x-api-key' => $apiKey,
                ])->get(rtrim($baseUrl, '/').'/min-amount', [
                    'currency_from' => 'usd',
                    'currency_to' => $coin,
                    'fiat_equivalent' => 'usd',
                    'is_fixed_rate' => false,
                ]);

            if (! $response->successful()) {
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

            Log::warning('NOWPayments minimum check failed: '.$e->getMessage());
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

    private function ensurePayableOrder(Order $order, $user, int $productId, int $packageId, string $method): void
    {
        if (
            (int) $order->user_id !== (int) $user->id ||
            (int) $order->product_id !== $productId ||
            (int) $order->package_id !== $packageId ||
            $order->payment_method !== $method ||
            $order->status !== 'pending'
        ) {
            throw new \Exception('Invalid order');
        }
    }

    private function gatewayCurlOptions(): array
    {
        return [
            CURLOPT_HTTPHEADER => [],
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ];
    }

    private function gatewayHttpOptions(): array
    {
        return [
            'proxy' => '',
            'curl' => $this->gatewayCurlOptions(),
        ];
    }
}
