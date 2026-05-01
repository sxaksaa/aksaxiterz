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

class PaymentService
{
    private const ALLOWED_COINS = [
        'usdttrc20',
        'usdtbsc',
        'usdterc20',
        'usdtmatic',
        'usdtton',
    ];

    private const DEFAULT_CRYPTO_BUYER_FEE_RATE = 0.02;

    private const DEFAULT_CRYPTO_BUYER_FEE_MINIMUM = 0.10;

    public function createPakasir($user, $productId, $packageId, ?Order $order = null)
    {
        return $this->createPakasirPayment($user, $productId, $packageId, $order)['payment_url'];
    }

    public function createPakasirPayment($user, $productId, $packageId, ?Order $order = null): array
    {
        $this->ensurePakasirConfigured();

        $product = Product::findOrFail($productId);

        $package = Package::where('id', $packageId)
            ->where('product_id', $productId)
            ->firstOrFail();

        if ($order) {
            $this->ensurePayableOrder($order, $user, $product->id, $package->id, 'pakasir');
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
                'order_id' => 'ORDER-'.strtoupper(Str::random(10)),
                'product_id' => $product->id,
                'user_id' => $user->id,
                'status' => 'pending',
                'payment_method' => 'pakasir',
                'price' => $package->price,
                'package_id' => $package->id,
                'expired_at' => now()->addMinutes(10),
            ]);
        }

        $paymentUrl = $this->pakasirPaymentUrl($order->order_id, $order->price);

        try {
            $payment = $this->createPakasirQrisTransaction($order);

            $order->update([
                'payment_url' => $paymentUrl,
                'payment_payload' => $this->normalizePakasirPayment($payment),
                'expired_at' => $this->pakasirExpiredAt($payment['expired_at'] ?? null) ?? $order->expired_at,
            ]);
        } catch (\Exception $e) {
            $order->update(['status' => 'cancelled']);

            throw $e;
        }

        return [
            'payment_url' => $paymentUrl,
            'pakasir_payment' => $order->fresh()->payment_payload,
            'order' => $order->fresh(),
        ];
    }

    public function getPakasirStatus(Order $order): array
    {
        $this->ensurePakasirConfigured();

        $baseUrl = rtrim(config('services.pakasir.url') ?: 'https://app.pakasir.com', '/');

        $response = Http::withOptions($this->gatewayHttpOptions())
            ->get($baseUrl.'/api/transactiondetail', [
                'project' => config('services.pakasir.slug'),
                'amount' => $this->idrAmount($order->price),
                'order_id' => $order->order_id,
                'api_key' => config('services.pakasir.api_key'),
            ]);

        if (! $response->successful()) {
            throw new \Exception('Unable to verify Pakasir payment');
        }

        return $response->json() ?: [];
    }

    public function getNowpaymentsPayment(string $paymentId): array
    {
        $this->ensureNowpaymentsConfigured();

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

        $this->ensureNowpaymentsConfigured();

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

        $this->ensureNowpaymentsConfigured();

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
                'order_id' => 'ORDER-'.strtoupper(Str::random(10)),
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
            $baseUrl = rtrim(config('services.nowpayments.url') ?: 'https://api.nowpayments.io/v1', '/');

            $response = Http::withOptions($this->gatewayHttpOptions())
                ->withHeaders([
                    'x-api-key' => config('services.nowpayments.key'),
                ])->post($baseUrl.'/invoice', [
                    'price_amount' => $amount,
                    'price_currency' => 'usd',
                    'pay_currency' => $coin,
                    'is_fixed_rate' => false,
                    'is_fee_paid_by_user' => false,
                    'order_id' => $order->order_id,
                    'order_description' => $this->cryptoOrderDescription($product->name, $package->name, $baseAmount, $amount),
                    'ipn_callback_url' => config('services.nowpayments.ipn'),
                    'success_url' => url('/licenses') . '?order=' . rawurlencode($order->order_id) . '#license-' . $order->order_id,
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

    private function cryptoBuyerFeeRate(): float
    {
        return max(0.0, (float) config('payment.crypto_buyer_fee_rate', self::DEFAULT_CRYPTO_BUYER_FEE_RATE));
    }

    private function cryptoBuyerFeeMinimum(): float
    {
        return max(0.0, (float) config('payment.crypto_buyer_fee_minimum', self::DEFAULT_CRYPTO_BUYER_FEE_MINIMUM));
    }

    private function pakasirPaymentUrl(string $orderId, $amount): string
    {
        $baseUrl = rtrim(config('services.pakasir.url') ?: 'https://app.pakasir.com', '/');
        $slug = trim((string) config('services.pakasir.slug'));
        $query = [
            'order_id' => $orderId,
            'redirect' => config('services.pakasir.return_url') ?: url('/orders'),
        ];

        if ((bool) config('services.pakasir.qris_only', false)) {
            $query['qris_only'] = 1;
        }

        return sprintf(
            '%s/pay/%s/%d?%s',
            $baseUrl,
            rawurlencode($slug),
            $this->idrAmount($amount),
            http_build_query($query)
        );
    }

    private function createPakasirQrisTransaction(Order $order): array
    {
        $baseUrl = rtrim(config('services.pakasir.url') ?: 'https://app.pakasir.com', '/');

        $response = Http::withOptions($this->gatewayHttpOptions())
            ->asJson()
            ->post($baseUrl.'/api/transactioncreate/qris', [
                'project' => config('services.pakasir.slug'),
                'order_id' => $order->order_id,
                'amount' => $this->idrAmount($order->price),
                'api_key' => config('services.pakasir.api_key'),
            ]);

        $payload = $response->json() ?: [];
        $payment = $payload['payment'] ?? null;

        if (! $response->successful() || ! is_array($payment) || blank($payment['payment_number'] ?? null)) {
            Log::warning('Pakasir QRIS response missing payment number', [
                'order_id' => $order->order_id,
                'status' => $response->status(),
                'body' => $payload ?: $response->body(),
            ]);

            throw new \Exception('Unable to create Pakasir QRIS payment');
        }

        return $payment;
    }

    private function normalizePakasirPayment(array $payment): array
    {
        return [
            'project' => (string) ($payment['project'] ?? config('services.pakasir.slug')),
            'order_id' => (string) ($payment['order_id'] ?? ''),
            'amount' => $this->idrAmount($payment['amount'] ?? 0),
            'fee' => $this->idrAmount($payment['fee'] ?? 0),
            'total_payment' => $this->idrAmount($payment['total_payment'] ?? ($payment['amount'] ?? 0)),
            'payment_method' => (string) ($payment['payment_method'] ?? 'qris'),
            'payment_number' => (string) ($payment['payment_number'] ?? ''),
            'expired_at' => (string) ($payment['expired_at'] ?? ''),
        ];
    }

    private function pakasirExpiredAt(?string $expiredAt): ?Carbon
    {
        if (! $expiredAt) {
            return null;
        }

        $normalized = preg_replace('/\.(\d{6})\d+(Z|[+-]\d{2}:\d{2})$/', '.$1$2', $expiredAt);

        try {
            return Carbon::parse($normalized);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function ensurePakasirConfigured(): void
    {
        if (! config('services.pakasir.slug') || ! config('services.pakasir.api_key')) {
            throw new \Exception('Pakasir is not configured');
        }
    }

    private function ensureNowpaymentsConfigured(): void
    {
        if (
            ! config('services.nowpayments.key') ||
            ! config('services.nowpayments.url') ||
            ! config('services.nowpayments.ipn') ||
            ! config('services.nowpayments.ipn_secret')
        ) {
            throw new \Exception('NOWPayments is not configured');
        }
    }

    private function idrAmount($amount): int
    {
        return max(0, (int) round((float) $amount));
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
}
