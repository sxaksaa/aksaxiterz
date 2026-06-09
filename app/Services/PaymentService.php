<?php

namespace App\Services;

use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    private const ALLOWED_COINS = [
        'usdttrc20',
        'usdtbsc',
        'usdcbsc',
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
            throw new \Exception('Automatic delivery is unavailable for this package. Please join Discord to order manually.');
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
            throw new \Exception('Automatic delivery is unavailable for this package. Please join Discord to order manually.');
        }

        $baseAmount = (float) ($package->price_usdt ?? 0);
        $orderId = $order?->order_id ?: 'ORDER-'.strtoupper(Str::random(10));
        $expiresAt = now()->addMinutes(max(5, (int) config('services.crypto_direct.expires_minutes', 60)));

        if (! $order) {
            $order = Order::create([
                'order_id' => $orderId,
                'product_id' => $product->id,
                'user_id' => $user->id,
                'status' => 'pending',
                'payment_method' => 'crypto',
                'price' => $baseAmount,
                'package_id' => $package->id,
                'expired_at' => $expiresAt,
            ]);
        }

        try {
            $amount = $this->claimDirectCryptoAmount($order, $network, $coin, $baseAmount);

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
        $binanceFirst = (bool) config('services.binance.deposit_fallback.primary', false);
        $binanceInspection = null;

        if ($binanceFirst) {
            $binanceInspection = $this->inspectDirectBinanceDeposits($order, $payload);

            if (! empty($binanceInspection['transfer'])) {
                return $binanceInspection;
            }
        }

        try {
            $inspection = match ($network) {
                'usdttrc20' => $this->inspectDirectTrc20Transfers($order, $payload),
                'usdtbsc' => $this->inspectDirectBep20Transfers($order, $payload, 'usdtbsc'),
                'usdcbsc' => $this->inspectDirectBep20Transfers($order, $payload, 'usdcbsc'),
                default => [
                    'transfer' => null,
                    'mismatches' => [],
                ],
            };
        } catch (\Exception $e) {
            if ($binanceInspection === null) {
                $binanceInspection = $this->inspectDirectBinanceDeposits($order, $payload);
            }

            if (! empty($binanceInspection['transfer'])) {
                return $binanceInspection;
            }

            throw $e;
        }

        if (! empty($inspection['transfer'])) {
            return $inspection;
        }

        if ($binanceInspection === null) {
            $binanceInspection = $this->inspectDirectBinanceDeposits($order, $payload);
        }

        if (! $binanceInspection) {
            return $inspection;
        }

        $mismatches = $binanceFirst
            ? array_merge($binanceInspection['mismatches'] ?? [], $inspection['mismatches'] ?? [])
            : array_merge($inspection['mismatches'] ?? [], $binanceInspection['mismatches'] ?? []);

        return [
            'transfer' => $binanceInspection['transfer'] ?? null,
            'mismatches' => array_slice($mismatches, 0, 5),
            'last_scanned_block' => $inspection['last_scanned_block'] ?? null,
        ];
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

            if (
                $this->decimalStringCompare($value, $requiredUnits) === 0 &&
                $this->paymentReferenceAvailable($order, $transfer)
            ) {
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

    private function inspectDirectBep20Transfers(Order $order, array $payload, string $coin = 'usdtbsc'): array
    {
        $network = $this->directCryptoNetwork($coin);

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

        $rpcUrl = rtrim((string) ($network['rpc_url'] ?? ''), '/');

        if ($rpcUrl === '') {
            throw new \Exception('Unable to verify crypto payment');
        }

        $chainHead = $this->bscRpcBlockNumber($rpcUrl);
        $confirmations = max(0, min(500, (int) ($network['rpc_confirmations'] ?? 5)));
        $latestBlock = max(0, $chainHead - $confirmations);
        $scanBlocks = $this->directBep20ScanBlocks($order, $network);
        $chunkBlocks = max(100, min(5000, (int) ($network['rpc_chunk_blocks'] ?? 3000)));
        $createdAtTimestamp = $this->paymentCreatedAtTimestamp($order->created_at);
        $toTopic = '0x'.str_pad(substr($address, 2), 64, '0', STR_PAD_LEFT);
        $eventTopic = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
        $mismatches = [];
        $oldestBlock = max(0, $latestBlock - $scanBlocks);
        $lastScannedBlock = max(0, (int) ($payload['last_scanned_block'] ?? 0));
        $overlapBlocks = max(1, min(10000, (int) ($network['rpc_overlap_blocks'] ?? 1000)));

        if ($lastScannedBlock > 0 && $lastScannedBlock <= $latestBlock) {
            $oldestBlock = max($oldestBlock, $lastScannedBlock - $overlapBlocks);
        }

        for ($toBlock = $latestBlock; $toBlock >= $oldestBlock; $toBlock -= ($chunkBlocks + 1)) {
            $fromBlock = max($oldestBlock, $toBlock - $chunkBlocks);
            $logs = $this->bscRpcGetLogs($rpcUrl, [
                'fromBlock' => $this->hexQuantity($fromBlock),
                'toBlock' => $this->hexQuantity($toBlock),
                'address' => $contract,
                'topics' => [
                    $eventTopic,
                    null,
                    $toTopic,
                ],
            ]);

            foreach (array_reverse($logs) as $log) {
                if (! is_array($log) || (bool) ($log['removed'] ?? false)) {
                    continue;
                }

                $value = $this->hexToDecimalString((string) ($log['data'] ?? '0x0'));
                $blockNumber = hexdec((string) ($log['blockNumber'] ?? '0x0'));

                if ($blockNumber > $latestBlock) {
                    continue;
                }

                $confirmedAt = $this->bscRpcBlockTimestamp($rpcUrl, $blockNumber);

                if ($createdAtTimestamp && $confirmedAt && $confirmedAt->timestamp < ($createdAtTimestamp - 300)) {
                    continue;
                }

                $transfer = [
                    'tx_hash' => (string) ($log['transactionHash'] ?? ''),
                    'network' => $coin,
                    'amount_units' => $value,
                    'amount' => $this->tokenUnitsToDecimal($value, $decimals),
                    'to' => $address,
                    'confirmed_at' => $confirmedAt,
                ];

                if (
                    $this->decimalStringCompare($value, $requiredUnits) === 0 &&
                    $this->paymentReferenceAvailable($order, $transfer)
                ) {
                    return [
                        'transfer' => $transfer,
                        'mismatches' => $mismatches,
                    ];
                }

                if (count($mismatches) < 5) {
                    $mismatches[] = $this->directCryptoMismatchPayload($transfer, $payload);
                }
            }
        }

        return [
            'transfer' => null,
            'mismatches' => $mismatches,
            'last_scanned_block' => $latestBlock,
        ];
    }

    private function inspectDirectBinanceDeposits(Order $order, array $payload): ?array
    {
        $fallback = config('services.binance.deposit_fallback', []);

        if (! is_array($fallback) || ! ($fallback['enabled'] ?? false)) {
            return null;
        }

        if (blank($fallback['api_key'] ?? null) || blank($fallback['api_secret'] ?? null)) {
            return null;
        }

        $coin = strtolower((string) ($payload['network'] ?? ''));

        if (! in_array($coin, self::ALLOWED_COINS, true)) {
            return null;
        }

        $network = $this->directCryptoNetwork($coin);
        $token = $this->directCryptoToken($payload, $network);
        $binanceNetwork = strtoupper(trim((string) ($network['binance_network'] ?? '')));
        $address = trim((string) ($payload['address'] ?? ''));
        $decimals = (int) ($payload['decimals'] ?? $network['decimals'] ?? 6);
        $requiredUnits = $this->decimalToTokenUnits($payload['amount'] ?? null, $decimals);
        $createdAtTimestamp = $this->paymentCreatedAtTimestamp($order->created_at);

        if ($address === '' || $binanceNetwork === '' || $requiredUnits === null) {
            return null;
        }

        $deposits = $this->signedBinanceGet('/sapi/v1/capital/deposit/hisrec', [
            'coin' => $token,
            'status' => 1,
            'startTime' => max(0, (($createdAtTimestamp ?: now()->subDay()->timestamp) - 300) * 1000),
            'endTime' => (now()->addMinutes(5)->timestamp) * 1000,
            'limit' => 1000,
            'recvWindow' => max(1000, (int) ($fallback['recv_window'] ?? 5000)),
            'timestamp' => $this->millisecondsTimestamp(),
        ], $fallback);

        if (! is_array($deposits)) {
            return null;
        }

        $mismatches = [];

        foreach ($deposits as $deposit) {
            if (! is_array($deposit)) {
                continue;
            }

            $actualNetwork = strtoupper(trim((string) ($deposit['network'] ?? '')));
            $actualAddress = trim((string) ($deposit['address'] ?? ''));
            $value = $this->decimalToTokenUnits($deposit['amount'] ?? null, $decimals);
            $timestampMs = (int) (($deposit['completeTime'] ?? null) ?: ($deposit['insertTime'] ?? 0));
            $timestamp = (int) floor($timestampMs / 1000);

            if (
                strtoupper((string) ($deposit['coin'] ?? '')) !== $token ||
                (int) ($deposit['status'] ?? -1) !== 1 ||
                ! $this->sameBinanceDepositNetwork($coin, $binanceNetwork, $actualNetwork) ||
                ! $this->sameCryptoAddress($address, $actualAddress) ||
                $value === null
            ) {
                continue;
            }

            if ($createdAtTimestamp && $timestamp > 0 && $timestamp < ($createdAtTimestamp - 300)) {
                continue;
            }

            $transfer = [
                'tx_hash' => (string) ($deposit['txId'] ?? $deposit['id'] ?? ''),
                'network' => $coin,
                'amount_units' => $value,
                'amount' => $this->tokenUnitsToDecimal($value, $decimals),
                'to' => $actualAddress,
                'confirmed_at' => $timestamp > 0 ? Carbon::createFromTimestamp($timestamp) : null,
                'source' => 'binance_deposit_history',
            ];

            if (
                $this->decimalStringCompare($value, $requiredUnits) === 0 &&
                $this->paymentReferenceAvailable($order, $transfer)
            ) {
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

    private function directCryptoToken(array $payload, array $network): string
    {
        $token = strtoupper(trim((string) ($network['token'] ?? $payload['token'] ?? 'USDT')));

        return $token !== '' ? $token : 'USDT';
    }

    private function paymentReferenceAvailable(Order $order, array $transfer): bool
    {
        $reference = strtolower(trim((string) ($transfer['tx_hash'] ?? '')));

        if ($reference === '') {
            return false;
        }

        if (! $order->exists) {
            return true;
        }

        if (blank($order->payment_match_key)) {
            return false;
        }

        return ! Order::query()
            ->where('payment_reference', $reference)
            ->whereKeyNot($order->getKey())
            ->exists();
    }

    private function directCryptoAmount(float $baseAmount, string $orderId, string $coin, int $attempt = 0): float
    {
        if ($baseAmount <= 0) {
            throw new \Exception('Invalid crypto amount');
        }

        $uniqueMax = max(1, min(9999, (int) config('services.crypto_direct.unique_max', 9999)));
        $hash = (int) sprintf('%u', crc32($orderId.'|'.$coin));
        $uniqueUnits = (($hash + max(0, $attempt)) % $uniqueMax) + 1;
        $uniqueAmount = $uniqueUnits / 1000000;

        return round($baseAmount + $uniqueAmount, 6);
    }

    private function claimDirectCryptoAmount(Order $order, array $network, string $coin, float $baseAmount): float
    {
        Order::whereNotNull('payment_match_key')
            ->where(function ($query): void {
                $query->where('status', 'paid')
                    ->orWhere('created_at', '<', now()->subDay());
            })
            ->update(['payment_match_key' => null]);

        $uniqueMax = max(1, min(9999, (int) config('services.crypto_direct.unique_max', 9999)));

        for ($attempt = 0; $attempt < $uniqueMax; $attempt++) {
            $amount = $this->directCryptoAmount($baseAmount, $order->order_id, $coin, $attempt);
            $matchKey = $this->directCryptoMatchKey($network, $coin, $amount);

            try {
                $order->update([
                    'price' => $amount,
                    'payment_match_key' => $matchKey,
                ]);

                return $amount;
            } catch (QueryException $error) {
                if (! str_contains(strtolower($error->getMessage()), 'payment_match_key')) {
                    throw $error;
                }
            }
        }

        throw new \Exception('No unique crypto amount is currently available');
    }

    private function directCryptoMatchKey(array $network, string $coin, float $amount): string
    {
        return hash('sha256', implode('|', [
            strtolower($coin),
            strtolower(trim((string) ($network['address'] ?? ''))),
            strtolower(trim((string) ($network['contract'] ?? ''))),
            number_format($amount, 6, '.', ''),
        ]));
    }

    private function normalizeDirectCryptoPayment(Order $order, array $network, string $coin, float $baseAmount, float $amount, Carbon $expiresAt): array
    {
        return [
            'type' => 'direct_crypto',
            'token' => $this->directCryptoToken([], $network),
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
            return Carbon::parse($normalized)->timezone(config('app.timezone'));
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

        if (str_ends_with($coin, 'bsc') && blank($network['rpc_url'] ?? null)) {
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

    private function directBep20ScanBlocks(Order $order, array $network): int
    {
        $configuredScanBlocks = max(1, (int) ($network['rpc_scan_blocks'] ?? 40000));
        $blockSeconds = max(0.1, min(10, (float) ($network['rpc_block_seconds'] ?? 0.4)));
        $createdAtTimestamp = $this->paymentCreatedAtTimestamp($order->created_at) ?? now()->timestamp;
        $orderAgeSeconds = max(600, now()->timestamp - $createdAtTimestamp + 600);
        $requiredScanBlocks = (int) ceil($orderAgeSeconds / $blockSeconds);

        return min(300000, max($configuredScanBlocks, $requiredScanBlocks));
    }

    private function looksLikeEvmAddress(string $address): bool
    {
        return (bool) preg_match('/^0x[a-f0-9]{40}$/', $address);
    }

    private function sameCryptoAddress(string $expected, string $actual): bool
    {
        if ($expected === '' || $actual === '') {
            return false;
        }

        if (str_starts_with(strtolower($expected), '0x') || str_starts_with(strtolower($actual), '0x')) {
            return hash_equals(strtolower($expected), strtolower($actual));
        }

        return hash_equals($expected, $actual);
    }

    private function sameBinanceDepositNetwork(string $coin, string $expected, string $actual): bool
    {
        $actual = strtoupper(trim($actual));

        if ($actual === '') {
            return false;
        }

        return in_array($actual, $this->binanceDepositNetworkAliases($coin, $expected), true);
    }

    private function binanceDepositNetworkAliases(string $coin, string $configuredNetwork): array
    {
        $aliases = [strtoupper(trim($configuredNetwork))];

        $aliases = array_merge($aliases, match ($coin) {
            'usdttrc20' => ['TRX', 'TRC20', 'TRON'],
            'usdtbsc', 'usdcbsc' => ['BSC', 'BEP20'],
            default => [],
        });

        return array_values(array_unique(array_filter($aliases)));
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

    private function bscRpcBlockNumber(string $rpcUrl): int
    {
        $result = $this->bscRpc($rpcUrl, 'eth_blockNumber');

        if (! is_string($result)) {
            throw new \Exception('Unable to verify crypto payment');
        }

        return hexdec($result);
    }

    private function bscRpcGetLogs(string $rpcUrl, array $filter): array
    {
        $result = $this->bscRpc($rpcUrl, 'eth_getLogs', [$filter]);

        if (! is_array($result)) {
            throw new \Exception('Unable to verify crypto payment');
        }

        return $result;
    }

    private function bscRpcBlockTimestamp(string $rpcUrl, int $blockNumber): ?Carbon
    {
        $result = $this->bscRpc($rpcUrl, 'eth_getBlockByNumber', [$this->hexQuantity($blockNumber), false]);

        if (! is_array($result) || ! is_string($result['timestamp'] ?? null)) {
            return null;
        }

        return Carbon::createFromTimestamp(hexdec($result['timestamp']));
    }

    private function bscRpc(string $rpcUrl, string $method, array $params = []): mixed
    {
        $response = Http::withOptions($this->gatewayHttpOptions())
            ->timeout(20)
            ->asJson()
            ->post($rpcUrl, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => $method,
                'params' => $params,
            ]);

        $payload = $response->json() ?: [];

        if (! $response->successful() || isset($payload['error'])) {
            Log::warning('BSC RPC verification request failed', [
                'method' => $method,
                'status' => $response->status(),
                'error' => $payload['error']['message'] ?? null,
            ]);

            throw new \Exception('Unable to verify crypto payment');
        }

        return $payload['result'] ?? null;
    }

    private function signedBinanceGet(string $path, array $params, array $config): ?array
    {
        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.binance.com'), '/');
        $apiKey = (string) ($config['api_key'] ?? '');
        $apiSecret = (string) ($config['api_secret'] ?? '');

        if ($baseUrl === '' || $apiKey === '' || $apiSecret === '') {
            return null;
        }

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $signature = hash_hmac('sha256', $query, $apiSecret);
        $url = $baseUrl.$path.'?'.$query.'&signature='.$signature;

        $response = Http::withOptions($this->gatewayHttpOptions())
            ->withHeaders([
                'X-MBX-APIKEY' => $apiKey,
            ])
            ->timeout(20)
            ->get($url);

        $payload = $response->json();

        if (! $response->successful() || ! is_array($payload)) {
            Log::warning('Binance deposit history verification request failed', [
                'path' => $path,
                'status' => $response->status(),
                'code' => is_array($payload) ? ($payload['code'] ?? null) : null,
                'message' => is_array($payload) ? ($payload['msg'] ?? null) : null,
            ]);

            return null;
        }

        return $payload;
    }

    private function millisecondsTimestamp(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function hexQuantity(int $value): string
    {
        return '0x'.dechex(max(0, $value));
    }

    private function hexToDecimalString(string $hex): string
    {
        $hex = strtolower(trim($hex));
        $hex = str_starts_with($hex, '0x') ? substr($hex, 2) : $hex;
        $hex = ltrim($hex, '0');

        if ($hex === '') {
            return '0';
        }

        $decimal = '0';

        foreach (str_split($hex) as $digit) {
            $decimal = $this->decimalStringMultiplySmall($decimal, 16);
            $decimal = $this->decimalStringAddSmall($decimal, hexdec($digit));
        }

        return $this->normalizeDecimalString($decimal);
    }

    private function decimalStringMultiplySmall(string $number, int $multiplier): string
    {
        $carry = 0;
        $result = '';

        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $product = ((int) $number[$i] * $multiplier) + $carry;
            $result = ($product % 10).$result;
            $carry = intdiv($product, 10);
        }

        while ($carry > 0) {
            $result = ($carry % 10).$result;
            $carry = intdiv($carry, 10);
        }

        return $this->normalizeDecimalString($result);
    }

    private function decimalStringAddSmall(string $number, int $addend): string
    {
        $carry = $addend;
        $result = '';

        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $sum = ((int) $number[$i]) + ($carry % 10);
            $carry = intdiv($carry, 10) + intdiv($sum, 10);
            $result = ($sum % 10).$result;
        }

        while ($carry > 0) {
            $result = ($carry % 10).$result;
            $carry = intdiv($carry, 10);
        }

        return $this->normalizeDecimalString($result);
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
