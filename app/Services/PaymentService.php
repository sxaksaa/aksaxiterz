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
    ];

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

    public function createCrypto($user, $productId, $packageId, $coin, ?Order $order = null)
    {
        return $this->createCryptoPayment($user, $productId, $packageId, $coin, $order)['crypto_payment'];
    }

    public function createCryptoPayment($user, $productId, $packageId, $coin, ?Order $order = null): array
    {
        $coin = strtolower($coin);

        if (! in_array($coin, self::ALLOWED_COINS, true)) {
            throw new \Exception('Invalid payment method');
        }

        $this->ensureDirectCryptoConfigured($coin);
        $network = $this->directCryptoNetwork($coin);

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
        $orderId = $order?->order_id ?: 'ORDER-'.strtoupper(Str::random(10));
        $amount = $this->directCryptoAmount($baseAmount, $orderId, $coin);
        $expiresAt = now()->addMinutes(max(5, (int) config('services.crypto_direct.expires_minutes', 1440)));

        if (! $order) {
            $order = Order::create([
                'order_id' => $orderId,
                'product_id' => $product->id,
                'user_id' => $user->id,
                'status' => 'pending',
                'payment_method' => 'crypto',
                'price' => $amount,
                'package_id' => $package->id,
                'expired_at' => $expiresAt,
            ]);
        }

        try {
            $order->update([
                'price' => $amount,
                'payment_url' => null,
                'payment_payload' => $this->normalizeDirectCryptoPayment($order, $network, $coin, $baseAmount, $amount, $expiresAt),
                'expired_at' => $expiresAt,
            ]);

            $freshOrder = $order->fresh();

            return [
                'payment_url' => null,
                'crypto_payment' => $freshOrder->payment_payload,
                'order' => $freshOrder,
            ];
        } catch (\Exception $e) {

            Log::error('CRYPTO ERROR: '.$e->getMessage());

            $order->update(['status' => 'cancelled']);

            throw $e;
        }
    }

    public function findDirectCryptoTransfer(Order $order): ?array
    {
        return $this->inspectDirectCryptoPayment($order)['transfer'] ?? null;
    }

    public function inspectDirectCryptoPayment(Order $order): array
    {
        $payload = $order->payment_payload;

        if (! is_array($payload) || ($payload['type'] ?? null) !== 'direct_crypto') {
            return [
                'transfer' => null,
                'mismatches' => [],
            ];
        }

        $network = strtolower((string) ($payload['network'] ?? ''));

        return match ($network) {
            'usdttrc20' => $this->inspectDirectTrc20Transfers($order, $payload),
            'usdtbsc' => $this->inspectDirectBep20Transfers($order, $payload),
            default => [
                'transfer' => null,
                'mismatches' => [],
            ],
        };
    }

    private function inspectDirectTrc20Transfers(Order $order, array $payload): array
    {
        $address = trim((string) ($payload['address'] ?? ''));
        $contract = trim((string) ($payload['contract'] ?? ''));
        $decimals = (int) ($payload['decimals'] ?? 6);
        $requiredUnits = $this->decimalToTokenUnits($payload['amount'] ?? null, $decimals);

        if ($address === '' || $contract === '' || $requiredUnits === null) {
            return [
                'transfer' => null,
                'mismatches' => [],
            ];
        }

        $network = $this->directCryptoNetwork('usdttrc20');
        $baseUrl = rtrim((string) ($network['api_url'] ?? 'https://api.trongrid.io'), '/');
        $headers = [];

        if (! blank($network['api_key'] ?? null)) {
            $headers['TRON-PRO-API-KEY'] = (string) $network['api_key'];
        }

        $response = Http::withOptions($this->gatewayHttpOptions())
            ->withHeaders($headers)
            ->timeout(20)
            ->get($baseUrl.'/v1/accounts/'.rawurlencode($address).'/transactions/trc20', [
                'limit' => 200,
                'contract_address' => $contract,
                'only_confirmed' => 'true',
                'order_by' => 'block_timestamp,desc',
            ]);

        if (! $response->successful()) {
            throw new \Exception('Unable to verify crypto payment');
        }

        $transactions = $response->json('data') ?: [];
        $createdAtTimestamp = $this->paymentCreatedAtTimestamp($order->created_at);
        $mismatches = [];

        foreach ($transactions as $transaction) {
            if (! is_array($transaction)) {
                continue;
            }

            $tokenInfo = is_array($transaction['token_info'] ?? null) ? $transaction['token_info'] : [];
            $actualContract = (string) ($tokenInfo['address'] ?? $transaction['contract_address'] ?? '');
            $actualTo = (string) ($transaction['to'] ?? '');
            $value = $this->normalizeDecimalString((string) ($transaction['value'] ?? ''));
            $timestamp = (int) floor(((int) ($transaction['block_timestamp'] ?? 0)) / 1000);

            if (! hash_equals($address, $actualTo)) {
                continue;
            }

            if ($actualContract !== '' && ! hash_equals($contract, $actualContract)) {
                continue;
            }

            if ($createdAtTimestamp && $timestamp > 0 && $timestamp < ($createdAtTimestamp - 300)) {
                continue;
            }

            $transfer = [
                'tx_hash' => (string) ($transaction['transaction_id'] ?? ''),
                'network' => 'usdttrc20',
                'amount_units' => $value,
                'amount' => $this->tokenUnitsToDecimal($value, $decimals),
                'to' => $actualTo,
                'confirmed_at' => $timestamp > 0 ? Carbon::createFromTimestamp($timestamp) : null,
            ];

            if ($this->decimalStringCompare($value, $requiredUnits) === 0) {
                return [
                    'transfer' => $transfer,
                    'mismatches' => $mismatches,
                ];
            }

            if (count($mismatches) < 5) {
                $mismatches[] = $this->directCryptoMismatchPayload($transfer, $payload);
            }
        }

        return [
            'transfer' => null,
            'mismatches' => $mismatches,
        ];
    }

    private function inspectDirectBep20Transfers(Order $order, array $payload): array
    {
        $address = strtolower(trim((string) ($payload['address'] ?? '')));
        $contract = strtolower(trim((string) ($payload['contract'] ?? '')));
        $decimals = (int) ($payload['decimals'] ?? 18);
        $requiredUnits = $this->decimalToTokenUnits($payload['amount'] ?? null, $decimals);

        if (! $this->looksLikeEvmAddress($address) || ! $this->looksLikeEvmAddress($contract) || $requiredUnits === null) {
            return [
                'transfer' => null,
                'mismatches' => [],
            ];
        }

        $network = $this->directCryptoNetwork('usdtbsc');
        $response = Http::withOptions($this->gatewayHttpOptions())
            ->timeout(20)
            ->get((string) ($network['api_url'] ?? 'https://api.etherscan.io/v2/api'), [
                'chainid' => (string) ($network['chain_id'] ?? 56),
                'module' => 'account',
                'action' => 'tokentx',
                'contractaddress' => $contract,
                'address' => $address,
                'page' => 1,
                'offset' => 100,
                'sort' => 'desc',
                'apikey' => (string) ($network['api_key'] ?? ''),
            ]);

        if (! $response->successful()) {
            throw new \Exception('Unable to verify crypto payment');
        }

        $data = $response->json() ?: [];
        $transactions = $data['result'] ?? [];

        if (! is_array($transactions)) {
            return [
                'transfer' => null,
                'mismatches' => [],
            ];
        }

        $createdAtTimestamp = $this->paymentCreatedAtTimestamp($order->created_at);
        $mismatches = [];

        foreach ($transactions as $transaction) {
            if (! is_array($transaction)) {
                continue;
            }

            $actualTo = strtolower((string) ($transaction['to'] ?? ''));
            $actualContract = strtolower((string) ($transaction['contractAddress'] ?? ''));
            $value = $this->normalizeDecimalString((string) ($transaction['value'] ?? ''));
            $timestamp = (int) ($transaction['timeStamp'] ?? 0);

            if ($actualTo !== $address || $actualContract !== $contract) {
                continue;
            }

            if ($createdAtTimestamp && $timestamp > 0 && $timestamp < ($createdAtTimestamp - 300)) {
                continue;
            }

            $transfer = [
                'tx_hash' => (string) ($transaction['hash'] ?? ''),
                'network' => 'usdtbsc',
                'amount_units' => $value,
                'amount' => $this->tokenUnitsToDecimal($value, $decimals),
                'to' => $actualTo,
                'confirmed_at' => $timestamp > 0 ? Carbon::createFromTimestamp($timestamp) : null,
            ];

            if ($this->decimalStringCompare($value, $requiredUnits) === 0) {
                return [
                    'transfer' => $transfer,
                    'mismatches' => $mismatches,
                ];
            }

            if (count($mismatches) < 5) {
                $mismatches[] = $this->directCryptoMismatchPayload($transfer, $payload);
            }
        }

        return [
            'transfer' => null,
            'mismatches' => $mismatches,
        ];
    }

    private function directCryptoNetwork(string $coin): array
    {
        $network = config("services.crypto_direct.networks.{$coin}");

        if (! is_array($network)) {
            throw new \Exception('Invalid payment method');
        }

        return $network;
    }

    private function directCryptoAmount(float $baseAmount, string $orderId, string $coin): float
    {
        if ($baseAmount <= 0) {
            throw new \Exception('Invalid crypto amount');
        }

        $uniqueMax = max(1, min(9999, (int) config('services.crypto_direct.unique_max', 9999)));
        $hash = (int) sprintf('%u', crc32($orderId.'|'.$coin));
        $uniqueUnits = ($hash % $uniqueMax) + 1;
        $uniqueAmount = $uniqueUnits / 1000000;

        return round($baseAmount + $uniqueAmount, 6);
    }

    private function normalizeDirectCryptoPayment(Order $order, array $network, string $coin, float $baseAmount, float $amount, Carbon $expiresAt): array
    {
        return [
            'type' => 'direct_crypto',
            'token' => 'USDT',
            'network' => $coin,
            'network_label' => (string) ($network['label'] ?? strtoupper($coin)),
            'network_short_label' => (string) ($network['short_label'] ?? strtoupper($coin)),
            'address' => trim((string) ($network['address'] ?? '')),
            'contract' => trim((string) ($network['contract'] ?? '')),
            'amount' => number_format($amount, 6, '.', ''),
            'base_amount' => number_format($baseAmount, 6, '.', ''),
            'unique_amount' => number_format(max(0, $amount - $baseAmount), 6, '.', ''),
            'decimals' => (int) ($network['decimals'] ?? 6),
            'created_at' => $order->created_at?->toIso8601String() ?: now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    private function directCryptoMismatchPayload(array $transfer, array $payload): array
    {
        $actualAmount = (string) ($transfer['amount'] ?? '0');
        $expectedAmount = (string) ($payload['amount'] ?? '0');

        return [
            'tx_hash' => (string) ($transfer['tx_hash'] ?? ''),
            'network' => (string) ($payload['network'] ?? $transfer['network'] ?? ''),
            'expected_amount' => $expectedAmount,
            'received_amount' => $actualAmount,
            'difference' => number_format(((float) $actualAmount) - ((float) $expectedAmount), 6, '.', ''),
            'checked_at' => now()->toIso8601String(),
            'confirmed_at' => ! empty($transfer['confirmed_at']) && $transfer['confirmed_at'] instanceof \DateTimeInterface
                ? $transfer['confirmed_at']->format(DATE_ATOM)
                : null,
        ];
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

    private function ensureDirectCryptoConfigured(string $coin): void
    {
        $network = $this->directCryptoNetwork($coin);

        if (blank($network['address'] ?? null) || blank($network['contract'] ?? null)) {
            throw new \Exception('Direct crypto checkout is not configured');
        }

        if ($coin === 'usdtbsc' && blank($network['api_key'] ?? null)) {
            throw new \Exception('Direct crypto checkout is not configured');
        }
    }

    private function idrAmount($amount): int
    {
        return max(0, (int) round((float) $amount));
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

    private function decimalStringCompare(string $first, string $second): int
    {
        $first = $this->normalizeDecimalString($first);
        $second = $this->normalizeDecimalString($second);

        if (strlen($first) !== strlen($second)) {
            return strlen($first) <=> strlen($second);
        }

        return $first <=> $second;
    }

    private function normalizeDecimalString(string $number): string
    {
        $number = ltrim($number, '0');

        return $number === '' ? '0' : $number;
    }

    private function tokenUnitsToDecimal(string $units, int $decimals): string
    {
        $units = $this->normalizeDecimalString($units);

        if ($decimals <= 0) {
            return $units;
        }

        $units = str_pad($units, $decimals + 1, '0', STR_PAD_LEFT);
        $whole = substr($units, 0, -$decimals);
        $fraction = substr($units, -$decimals);
        $decimal = $whole.'.'.$fraction;

        return rtrim(rtrim($decimal, '0'), '.') ?: '0';
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
