<?php

namespace App\Services;

use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use Carbon\Carbon;
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
        'shopeepay',
        'dana',
        'bca_va',
        'bni_va',
        'bri_va',
        'permata_va',
        'echannel',
    ];

    private const DEFAULT_CRYPTO_BUYER_FEE_RATE = 0.02;

    private const DEFAULT_CRYPTO_BUYER_FEE_MINIMUM = 0.10;

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
            'customer_details' => [
                'first_name' => $user->name,
                'email' => $user->email,
            ],
        ];

        $customerFeeConfig = $this->midtransCustomerFeeConfig();

        if ($customerFeeConfig) {
            $params['customer_imposed_payment_fee'] = $customerFeeConfig;
        }

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

    public function getNowpaymentsPayment(string $paymentId): array
    {
        $baseUrl = rtrim(config('services.nowpayments.url') ?: 'https://api.nowpayments.io/v1', '/');

        $response = Http::withOptions($this->gatewayHttpOptions())
            ->withHeaders([
                'x-api-key' => config('services.nowpayments.key'),
            ])
            ->get($baseUrl.'/payment/'.$paymentId);

        if (! $response->successful()) {
            throw new \Exception('Unable to verify crypto payment');
        }

        return $response->json() ?: [];
    }

    public function findNowpaymentsPaymentByInvoice(string $invoiceId, string $orderId): ?array
    {
        $email = config('services.nowpayments.email');
        $password = config('services.nowpayments.password');

        if (! $email || ! $password) {
            return null;
        }

        $baseUrl = rtrim(config('services.nowpayments.url') ?: 'https://api.nowpayments.io/v1', '/');
        $tokenResponse = Http::withOptions($this->gatewayHttpOptions())
            ->post($baseUrl.'/auth', [
                'email' => $email,
                'password' => $password,
            ]);

        if (! $tokenResponse->successful()) {
            throw new \Exception('Unable to authenticate NOWPayments');
        }

        $token = $tokenResponse->json('token');

        if (! $token) {
            throw new \Exception('Unable to authenticate NOWPayments');
        }

        $response = Http::withOptions($this->gatewayHttpOptions())
            ->withToken($token)
            ->withHeaders([
                'x-api-key' => config('services.nowpayments.key'),
            ])
            ->get($baseUrl.'/payment/', [
                'limit' => 50,
                'page' => 0,
                'sortBy' => 'created_at',
                'orderBy' => 'desc',
                'invoiceid' => $invoiceId,
            ]);

        if (! $response->successful()) {
            throw new \Exception('Unable to search NOWPayments invoice payments');
        }

        $payload = $response->json() ?: [];
        $payments = $payload['data'] ?? $payload['payments'] ?? $payload;

        if (! is_array($payments)) {
            return null;
        }

        foreach ($payments as $payment) {
            if (! is_array($payment)) {
                continue;
            }

            if (hash_equals($orderId, (string) ($payment['order_id'] ?? ''))) {
                return $payment;
            }
        }

        return null;
    }

    public function findUsdtBscInvoiceTransfer(array $payment): ?array
    {
        if (strtolower((string) ($payment['pay_currency'] ?? '')) !== 'usdtbsc') {
            return null;
        }

        $payAddress = strtolower((string) ($payment['pay_address'] ?? ''));

        if (! $this->looksLikeEvmAddress($payAddress)) {
            return null;
        }

        $requiredUnits = $this->decimalToTokenUnits($payment['pay_amount'] ?? null);

        if ($requiredUnits === null) {
            return null;
        }

        $latestBlock = $this->hexToInt((string) $this->bscRpc('eth_blockNumber', []));
        $blockWindow = max(1000, (int) config('services.bsc.log_blocks', 50000));
        $fromBlock = max(0, $latestBlock - $blockWindow);
        $createdAtTimestamp = $this->paymentCreatedAtTimestamp($payment['created_at'] ?? null);

        $logs = $this->bscRpc('eth_getLogs', [[
            'fromBlock' => '0x'.dechex($fromBlock),
            'toBlock' => 'latest',
            'address' => config('services.bsc.usdt_contract'),
            'topics' => [
                '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
                null,
                $this->addressTopic($payAddress),
            ],
        ]]);

        if (! is_array($logs)) {
            return null;
        }

        foreach ($logs as $log) {
            if (! is_array($log) || ! isset($log['data'], $log['transactionHash'], $log['blockNumber'])) {
                continue;
            }

            if ($this->decimalStringCompare($this->hexToDecimalString($log['data']), $requiredUnits) < 0) {
                continue;
            }

            if ($createdAtTimestamp && ! $this->logIsAfterPaymentCreated($log['blockNumber'], $createdAtTimestamp)) {
                continue;
            }

            return [
                'tx_hash' => $log['transactionHash'],
                'block_number' => $this->hexToInt((string) $log['blockNumber']),
                'amount_units' => $this->hexToDecimalString($log['data']),
                'to' => $payAddress,
            ];
        }

        return null;
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
        $fee = max($baseAmount * $this->cryptoBuyerFeeRate(), $this->cryptoBuyerFeeMinimum());

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

    private function midtransCustomerFeeConfig(): ?array
    {
        $customerPercentage = $this->midtransCustomerFeePercentage();

        if ($customerPercentage <= 0) {
            return null;
        }

        return [
            'enable' => true,
            'payment_fee_configs' => array_map(
                fn (string $paymentType) => [
                    'payment_type' => $paymentType,
                    'customer_percentage' => $customerPercentage,
                ],
                self::MIDTRANS_CUSTOMER_FEE_PAYMENT_TYPES
            ),
        ];
    }

    private function cryptoBuyerFeeRate(): float
    {
        return max(0.0, (float) config('payment.crypto_buyer_fee_rate', self::DEFAULT_CRYPTO_BUYER_FEE_RATE));
    }

    private function cryptoBuyerFeeMinimum(): float
    {
        return max(0.0, (float) config('payment.crypto_buyer_fee_minimum', self::DEFAULT_CRYPTO_BUYER_FEE_MINIMUM));
    }

    private function midtransCustomerFeePercentage(): int
    {
        return max(0, min(100, (int) config('payment.midtrans_customer_fee_percentage', 50)));
    }

    private function bscRpc(string $method, array $params)
    {
        $rpcUrl = config('services.bsc.rpc_url');

        if (! $rpcUrl) {
            throw new \Exception('BSC RPC is not configured');
        }

        $response = Http::withOptions($this->gatewayHttpOptions())
            ->timeout(20)
            ->post($rpcUrl, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => $method,
                'params' => $params,
            ]);

        $data = $response->json();

        if (! $response->successful() || ! is_array($data) || isset($data['error'])) {
            throw new \Exception('BSC verification failed');
        }

        return $data['result'] ?? null;
    }

    private function logIsAfterPaymentCreated(string $blockNumber, int $createdAtTimestamp): bool
    {
        $block = $this->bscRpc('eth_getBlockByNumber', [$blockNumber, false]);

        if (! is_array($block) || ! isset($block['timestamp'])) {
            return false;
        }

        return $this->hexToInt((string) $block['timestamp']) >= ($createdAtTimestamp - 300);
    }

    private function paymentCreatedAtTimestamp($createdAt): ?int
    {
        if (! $createdAt) {
            return null;
        }

        try {
            return Carbon::parse($createdAt)->timestamp;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function addressTopic(string $address): string
    {
        return '0x'.str_pad(substr($address, 2), 64, '0', STR_PAD_LEFT);
    }

    private function looksLikeEvmAddress(string $address): bool
    {
        return (bool) preg_match('/^0x[a-f0-9]{40}$/', $address);
    }

    private function decimalToTokenUnits($amount, int $decimals = 18): ?string
    {
        if (! is_numeric($amount)) {
            return null;
        }

        $value = trim((string) $amount);

        if (stripos($value, 'e') !== false) {
            $value = rtrim(rtrim(sprintf('%.'.$decimals.'F', (float) $amount), '0'), '.');
        }

        if (! preg_match('/^\d+(\.\d+)?$/', $value)) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = substr(str_pad($fraction, $decimals, '0'), 0, $decimals);

        return $this->normalizeDecimalString($whole.$fraction);
    }

    private function hexToDecimalString(string $hex): string
    {
        $hex = strtolower(preg_replace('/^0x/', '', $hex));
        $decimal = '0';

        foreach (str_split($hex) as $char) {
            $decimal = $this->decimalStringMultiply($decimal, 16);
            $decimal = $this->decimalStringAdd($decimal, (int) hexdec($char));
        }

        return $this->normalizeDecimalString($decimal);
    }

    private function decimalStringCompare(string $first, string $second): int
    {
        $first = $this->normalizeDecimalString($first);
        $second = $this->normalizeDecimalString($second);

        if (strlen($first) !== strlen($second)) {
            return strlen($first) <=> strlen($second);
        }

        return $first <=> $second;
    }

    private function decimalStringMultiply(string $number, int $multiplier): string
    {
        $carry = 0;
        $result = '';

        for ($index = strlen($number) - 1; $index >= 0; $index--) {
            $product = ((int) $number[$index] * $multiplier) + $carry;
            $result = ($product % 10).$result;
            $carry = intdiv($product, 10);
        }

        while ($carry > 0) {
            $result = ($carry % 10).$result;
            $carry = intdiv($carry, 10);
        }

        return $this->normalizeDecimalString($result);
    }

    private function decimalStringAdd(string $number, int $addend): string
    {
        $carry = $addend;
        $result = '';

        for ($index = strlen($number) - 1; $index >= 0; $index--) {
            $sum = ((int) $number[$index]) + $carry;
            $result = ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        while ($carry > 0) {
            $result = ($carry % 10).$result;
            $carry = intdiv($carry, 10);
        }

        return $this->normalizeDecimalString($result);
    }

    private function normalizeDecimalString(string $number): string
    {
        $number = ltrim($number, '0');

        return $number === '' ? '0' : $number;
    }

    private function hexToInt(string $hex): int
    {
        return (int) hexdec(preg_replace('/^0x/', '', strtolower($hex)));
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
