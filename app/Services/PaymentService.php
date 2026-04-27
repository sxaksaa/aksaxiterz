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
use Midtrans\Transaction;

class PaymentService
{
    private const ALLOWED_COINS = [
        'usdttrc20',
        'usdtbsc',
        'usdterc20',
        'usdtmatic',
        'usdtton',
    ];

    private const MIDTRANS_CUSTOMER_FEE_PAYMENT_TYPES = [
        'gopay',
        'qris',
        'other_qris',
        'shopeepay',
        'dana',
        'bca_va',
        'bni_va',
        'bri_va',
        'permata_va',
        'echannel',
        'other_va',
    ];

    private const CRYPTO_BUYER_FEE_RATE = 0.04;

    private const CRYPTO_BUYER_FEE_MINIMUM = 0.25;

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
            throw new \Exception('Out of stock');
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
        Config::$curlOptions = $this->midtransCurlOptions();

        $params = [
            'transaction_details' => [
                'order_id' => $order->order_id,
                'gross_amount' => (int) round((float) $order->price),
            ],
            'customer_imposed_payment_fee' => $this->midtransCustomerFeeConfig(),
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

    public function getMidtransStatus(string $orderId): array
    {
        Config::$serverKey = config('midtrans.serverKey');
        Config::$isProduction = config('midtrans.isProduction', false);
        Config::$curlOptions = $this->midtransCurlOptions();

        $status = Transaction::status($orderId);

        return json_decode(json_encode($status), true) ?: [];
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
            throw new \Exception('Out of stock');
        }

        $baseAmount = (float) ($package->price_usdt ?? 0);
        $amount = $order
            ? (float) $order->price
            : $this->cryptoCustomerTotal($baseAmount);
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
                    'is_fee_paid_by_user' => false,
                    'order_id' => $order->order_id,
                    'order_description' => $this->cryptoOrderDescription($product->name, $package->name, $baseAmount, $amount),
                    'ipn_callback_url' => config('services.nowpayments.ipn'),
                    'success_url' => url('/licenses'),
                    'cancel_url' => url('/'),
                ]);

            $data = $response->json();

            if (! isset($data['invoice_url'])) {
                Log::warning('NOWPayments invoice response missing URL', [
                    'order_id' => $order->order_id,
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
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

    private function cryptoCustomerTotal(float $baseAmount): float
    {
        $fee = max($baseAmount * self::CRYPTO_BUYER_FEE_RATE, self::CRYPTO_BUYER_FEE_MINIMUM);

        return round($baseAmount + $fee, 2);
    }

    private function cryptoOrderDescription(string $productName, string $packageName, float $baseAmount, float $amount): string
    {
        if ($amount <= $baseAmount) {
            return $productName.' - '.$packageName;
        }

        return sprintf(
            '%s - %s (includes $%s crypto fee)',
            $productName,
            $packageName,
            number_format($amount - $baseAmount, 2, '.', '')
        );
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

    private function midtransCustomerFeeConfig(): array
    {
        return [
            'enable' => true,
            'payment_fee_configs' => array_map(
                fn (string $paymentType) => [
                    'payment_type' => $paymentType,
                    'customer_percentage' => 100,
                ],
                self::MIDTRANS_CUSTOMER_FEE_PAYMENT_TYPES
            ),
        ];
    }

    private function gatewayCurlOptions(): array
    {
        return [
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

    private function midtransCurlOptions(): array
    {
        return $this->gatewayCurlOptions() + [
            CURLOPT_HTTPHEADER => [],
        ];
    }
}
