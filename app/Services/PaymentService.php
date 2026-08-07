<?php

namespace App\Services;

use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    private const CRYPTO_PAYMENT_PRECISION = 5;

    private StockReservationService $stockReservationService;

    private VoucherService $voucherService;

    private ?array $lastBinanceRequestDiagnostics = null;

    private const ALLOWED_COINS = [
        'usdttrc20',
        'usdtbsc',
        'usdcbsc',
    ];

    public function __construct(
        ?StockReservationService $stockReservationService = null,
        ?VoucherService $voucherService = null
    ) {
        $this->stockReservationService = $stockReservationService ?: app(StockReservationService::class);
        $this->voucherService = $voucherService ?: app(VoucherService::class);
    }

    public function createGopayQrisPayment(
        $user,
        $productId,
        $packageId,
        ?Order $order = null,
        ?string $voucherCode = null,
        int $quantity = 1
    ): array {
        $product = Product::findOrFail($productId);
        $this->ensureProductPurchasable($product);
        $this->ensureGopayQrisConfigured();

        $package = Package::where('id', $packageId)
            ->where('product_id', $productId)
            ->firstOrFail();

        if ($order) {
            $this->ensurePayableOrder($order, $user, $product->id, $package->id, 'gopay_qris');
        }

        $quantity = $this->checkoutQuantity($order ? max(1, (int) ($order->quantity ?: 1)) : $quantity);

        if ($this->availableStockCount($product, $package, $order) < $quantity) {
            throw new \Exception('Automatic delivery does not have enough license stock for this quantity.');
        }

        $expiresAt = now()->addMinutes(max(1, (int) config('services.gopay_qris.expires_minutes', 10)));
        $order = $this->prepareOrder(
            $user,
            $product,
            $package,
            'gopay_qris',
            $order,
            $voucherCode,
            $expiresAt,
            $order?->order_id,
            null,
            $quantity
        );
        $baseAmount = $this->idrAmount($order->price);

        try {
            $this->stockReservationService->reserve($order);
            $amountBreakdown = $this->claimGopayQrisAmount($order, $baseAmount);
            $platformFee = $amountBreakdown['platform_fee'];
            $uniqueAmount = $amountBreakdown['unique_amount'];
            $totalAmount = $amountBreakdown['total_amount'];
            $qrPayload = trim((string) config('services.gopay_qris.static_payload'));

            $order->update([
                'price' => $totalAmount,
                'payment_url' => null,
                'payment_payload' => $this->normalizeGopayQrisPayment(
                    $order,
                    $baseAmount,
                    $platformFee,
                    $uniqueAmount,
                    $totalAmount,
                    $qrPayload,
                    $expiresAt
                ),
                'expired_at' => $expiresAt,
            ]);
            $freshOrder = $order->fresh(['items']);
            $this->stockReservationService->reserve($freshOrder);

            return [
                'payment_url' => null,
                'gopay_qris_payment' => $freshOrder->payment_payload,
                'order' => $freshOrder,
            ];
        } catch (\Exception $error) {
            Log::error('GOPAY QRIS ERROR: '.$error->getMessage());
            $this->cancelPendingOrder($order);

            throw $error;
        }
    }

    public function createCrypto($user, $productId, $packageId, $coin, ?Order $order = null)
    {
        return $this->createCryptoPayment($user, $productId, $packageId, $coin, $order)['crypto_payment'];
    }

    public function createCryptoPayment(
        $user,
        $productId,
        $packageId,
        $coin,
        ?Order $order = null,
        ?string $voucherCode = null,
        int $quantity = 1
    ): array {
        $coin = strtolower($coin);

        if (! in_array($coin, self::ALLOWED_COINS, true)) {
            throw new \Exception('Invalid payment method');
        }

        $product = Product::findOrFail($productId);
        $this->ensureProductPurchasable($product);
        $this->ensureDirectCryptoConfigured($coin);
        $network = $this->directCryptoNetwork($coin);

        $package = Package::where('id', $packageId)
            ->where('product_id', $productId)
            ->firstOrFail();

        if ($order) {
            $this->ensurePayableOrder($order, $user, $product->id, $package->id, 'crypto');
        }

        $quantity = $this->checkoutQuantity($order ? max(1, (int) ($order->quantity ?: 1)) : $quantity);
        $stockCount = $this->availableStockCount($product, $package, $order);

        if ($stockCount < $quantity) {
            throw new \Exception('Automatic delivery does not have enough license stock for this quantity.');
        }

        $orderId = $order?->order_id ?: 'ORDER-'.strtoupper(Str::random(10));
        $expiresAt = now()->addMinutes(max(5, (int) config('services.crypto_direct.expires_minutes', 10)));

        $order = $this->prepareOrder(
            $user,
            $product,
            $package,
            'crypto',
            $order,
            $voucherCode,
            $expiresAt,
            $orderId,
            $coin,
            $quantity
        );
        $baseAmount = round((float) $order->price, self::CRYPTO_PAYMENT_PRECISION);

        try {
            $this->stockReservationService->reserve($order);
            $amount = $this->claimDirectCryptoAmount($order, $network, $coin, $baseAmount);

            $order->update([
                'price' => $amount,
                'payment_url' => null,
                'payment_payload' => $this->normalizeDirectCryptoPayment($order, $network, $coin, $baseAmount, $amount, $expiresAt),
                'expired_at' => $expiresAt,
            ]);

            $freshOrder = $order->fresh();
            $this->stockReservationService->reserve($freshOrder);

            return [
                'payment_url' => null,
                'crypto_payment' => $freshOrder->payment_payload,
                'order' => $freshOrder,
            ];
        } catch (\Exception $e) {

            Log::error('CRYPTO ERROR: '.$e->getMessage());

            $this->cancelPendingOrder($order);

            throw $e;
        }
    }

    public function createBinancePayPayment(
        $user,
        $productId,
        $packageId,
        ?Order $order = null,
        ?string $voucherCode = null,
        int $quantity = 1,
        ?string $selectedToken = null
    ): array {
        $product = Product::findOrFail($productId);
        $this->ensureProductPurchasable($product);
        $this->ensureBinancePayConfigured();
        $pay = config('services.binance.pay', []);
        $token = strtoupper(trim((string) ($selectedToken ?: ($pay['token'] ?? 'USDT'))));
        $coin = strtolower($token);

        if (! in_array($token, ['USDT', 'USDC'], true)) {
            throw new \Exception('Unsupported Binance Pay token');
        }

        $pay['token'] = $token;
        $tokenQrContent = $pay['qr_contents'][$token] ?? null;
        $pay['qr_content'] = filled($tokenQrContent)
            ? $tokenQrContent
            : ($pay['qr_content'] ?? null);

        $package = Package::where('id', $packageId)
            ->where('product_id', $productId)
            ->firstOrFail();

        if ($order) {
            $this->ensurePayableOrder($order, $user, $product->id, $package->id, 'binance_pay');
        }

        $quantity = $this->checkoutQuantity($order ? max(1, (int) ($order->quantity ?: 1)) : $quantity);
        $stockCount = $this->availableStockCount($product, $package, $order);

        if ($stockCount < $quantity) {
            throw new \Exception('Automatic delivery does not have enough license stock for this quantity.');
        }

        $expiresAt = now()->addMinutes(max(5, (int) ($pay['expires_minutes'] ?? 10)));
        $order = $this->prepareOrder(
            $user,
            $product,
            $package,
            'binance_pay',
            $order,
            $voucherCode,
            $expiresAt,
            $order?->order_id,
            $coin,
            $quantity
        );
        $baseAmount = round((float) $order->price, self::CRYPTO_PAYMENT_PRECISION);

        try {
            $this->stockReservationService->reserve($order);
            $amount = $this->claimBinancePayAmount($order, $baseAmount, $token, (string) $pay['pay_id']);

            $order->update([
                'price' => $amount,
                'payment_url' => null,
                'payment_payload' => $this->normalizeBinancePayPayment(
                    $order,
                    $baseAmount,
                    $amount,
                    $expiresAt,
                    $pay
                ),
                'expired_at' => $expiresAt,
            ]);

            $freshOrder = $order->fresh();
            $this->stockReservationService->reserve($freshOrder);

            return [
                'payment_url' => null,
                'binance_pay_payment' => $freshOrder->payment_payload,
                'order' => $freshOrder,
            ];
        } catch (\Exception $e) {
            Log::error('BINANCE PAY ERROR: '.$e->getMessage());
            $this->cancelPendingOrder($order);

            throw $e;
        }
    }

    public function createCartGopayQrisPayment(
        $user,
        Collection $items,
        ?Order $order = null,
        ?string $voucherCode = null
    ): array {
        $this->ensureCartProductsPurchasable($items);
        $this->ensureGopayQrisConfigured();
        $expiresAt = now()->addMinutes(max(1, (int) config('services.gopay_qris.expires_minutes', 10)));
        $order = $this->prepareCartOrder(
            $user,
            $items,
            'gopay_qris',
            $order,
            $voucherCode,
            $expiresAt
        );
        $baseAmount = $this->idrAmount($order->price);

        try {
            $this->stockReservationService->reserve($order);
            $amountBreakdown = $this->claimGopayQrisAmount($order, $baseAmount);
            $platformFee = $amountBreakdown['platform_fee'];
            $uniqueAmount = $amountBreakdown['unique_amount'];
            $totalAmount = $amountBreakdown['total_amount'];
            $qrPayload = trim((string) config('services.gopay_qris.static_payload'));
            $payload = $this->normalizeGopayQrisPayment(
                $order,
                $baseAmount,
                $platformFee,
                $uniqueAmount,
                $totalAmount,
                $qrPayload,
                $expiresAt
            );
            $payload['quantity'] = $order->total_quantity;
            $payload['item_count'] = $order->item_count;

            $order->update([
                'price' => $totalAmount,
                'payment_url' => null,
                'payment_payload' => $payload,
                'expired_at' => $expiresAt,
            ]);
            $freshOrder = $order->fresh(['items']);
            $this->stockReservationService->reserve($freshOrder);

            return [
                'payment_url' => null,
                'gopay_qris_payment' => $freshOrder->payment_payload,
                'order' => $freshOrder,
            ];
        } catch (\Exception $error) {
            Log::error('CART GOPAY QRIS ERROR: '.$error->getMessage());
            $this->cancelPendingOrder($order);

            throw $error;
        }
    }

    public function createCartCryptoPayment(
        $user,
        Collection $items,
        string $coin,
        ?Order $order = null,
        ?string $voucherCode = null
    ): array {
        $coin = strtolower($coin);

        if (! in_array($coin, self::ALLOWED_COINS, true)) {
            throw new \Exception('Invalid payment method');
        }

        $this->ensureCartProductsPurchasable($items);
        $this->ensureDirectCryptoConfigured($coin);
        $network = $this->directCryptoNetwork($coin);
        $expiresAt = now()->addMinutes(max(5, (int) config('services.crypto_direct.expires_minutes', 10)));
        $order = $this->prepareCartOrder(
            $user,
            $items,
            'crypto',
            $order,
            $voucherCode,
            $expiresAt,
            $coin
        );
        $baseAmount = round((float) $order->price, self::CRYPTO_PAYMENT_PRECISION);

        try {
            $this->stockReservationService->reserve($order);
            $amount = $this->claimDirectCryptoAmount($order, $network, $coin, $baseAmount);
            $order->update([
                'price' => $amount,
                'payment_url' => null,
                'payment_payload' => $this->normalizeDirectCryptoPayment(
                    $order,
                    $network,
                    $coin,
                    $baseAmount,
                    $amount,
                    $expiresAt
                ),
                'expired_at' => $expiresAt,
            ]);
            $freshOrder = $order->fresh(['items']);
            $this->stockReservationService->reserve($freshOrder);

            return [
                'payment_url' => null,
                'crypto_payment' => $freshOrder->payment_payload,
                'order' => $freshOrder,
            ];
        } catch (\Exception $error) {
            Log::error('CART CRYPTO ERROR: '.$error->getMessage());
            $this->cancelPendingOrder($order);

            throw $error;
        }
    }

    public function createCartBinancePayPayment(
        $user,
        Collection $items,
        string $selectedToken,
        ?Order $order = null,
        ?string $voucherCode = null
    ): array {
        $this->ensureCartProductsPurchasable($items);
        $this->ensureBinancePayConfigured();
        $pay = config('services.binance.pay', []);
        $token = strtoupper(trim($selectedToken));

        if (! in_array($token, ['USDT', 'USDC'], true)) {
            throw new \Exception('Unsupported Binance Pay token');
        }

        $pay['token'] = $token;
        $tokenQrContent = $pay['qr_contents'][$token] ?? null;
        $pay['qr_content'] = filled($tokenQrContent) ? $tokenQrContent : ($pay['qr_content'] ?? null);
        $expiresAt = now()->addMinutes(max(5, (int) ($pay['expires_minutes'] ?? 10)));
        $order = $this->prepareCartOrder(
            $user,
            $items,
            'binance_pay',
            $order,
            $voucherCode,
            $expiresAt,
            strtolower($token)
        );
        $baseAmount = round((float) $order->price, self::CRYPTO_PAYMENT_PRECISION);

        try {
            $this->stockReservationService->reserve($order);
            $amount = $this->claimBinancePayAmount($order, $baseAmount, $token, (string) $pay['pay_id']);
            $order->update([
                'price' => $amount,
                'payment_url' => null,
                'payment_payload' => $this->normalizeBinancePayPayment(
                    $order,
                    $baseAmount,
                    $amount,
                    $expiresAt,
                    $pay
                ),
                'expired_at' => $expiresAt,
            ]);
            $freshOrder = $order->fresh(['items']);
            $this->stockReservationService->reserve($freshOrder);

            return [
                'payment_url' => null,
                'binance_pay_payment' => $freshOrder->payment_payload,
                'order' => $freshOrder,
            ];
        } catch (\Exception $error) {
            Log::error('CART BINANCE PAY ERROR: '.$error->getMessage());
            $this->cancelPendingOrder($order);

            throw $error;
        }
    }

    public function getBinancePayTransactions(Carbon $startAt, Carbon $endAt): array
    {
        $pay = config('services.binance.pay', []);

        if (
            ! is_array($pay) ||
            ! ($pay['enabled'] ?? false) ||
            blank($pay['api_key'] ?? null) ||
            blank($pay['api_secret'] ?? null)
        ) {
            throw new \Exception('Binance Pay automatic verification is not configured');
        }

        $payload = $this->signedBinanceGet('/sapi/v1/pay/transactions', [
            'startTime' => $startAt->copy()->utc()->getTimestampMs(),
            'endTime' => $endAt->copy()->utc()->getTimestampMs(),
            'limit' => 100,
            'recvWindow' => max(1000, (int) ($pay['recv_window'] ?? 5000)),
            'timestamp' => $this->millisecondsTimestamp(),
        ], $pay);

        if (
            ! is_array($payload) ||
            (string) ($payload['code'] ?? '') !== '000000' ||
            ($payload['success'] ?? false) !== true ||
            ! is_array($payload['data'] ?? null)
        ) {
            throw new \Exception('Unable to verify Binance Pay payment');
        }

        return [
            'transactions' => $payload['data'],
            'diagnostics' => [
                'status' => 'request_succeeded',
                'returned_records' => count($payload['data']),
            ],
        ];
    }

    public function findDirectCryptoTransfer(Order $order): ?array
    {
        return $this->inspectDirectCryptoPayment($order)['transfer'] ?? null;
    }

    public function inspectDirectBinancePayment(Order $order): ?array
    {
        $payload = $order->payment_payload;

        if (! is_array($payload) || ($payload['type'] ?? null) !== 'direct_crypto') {
            return null;
        }

        return $this->inspectDirectBinanceDeposits($order, $payload);
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

            // A primary Binance deposit-history verifier is authoritative. Do not
            // fall through to public chain RPCs when Binance reports no match or
            // a provider error; the next scheduler run will retry Binance safely.
            if ($binanceInspection !== null) {
                return $binanceInspection;
            }

            return [
                'transfer' => null,
                'mismatches' => [],
                'binance_diagnostics' => [
                    'status' => 'unsupported_payment_payload',
                    'returned_records' => 0,
                ],
            ];
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

            if ($binanceInspection !== null) {
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
            'binance_diagnostics' => $binanceInspection['binance_diagnostics'] ?? null,
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
        foreach ($transactions as $transaction) {
            if (! is_array($transaction)) {
                continue;
            }

            $tokenInfo = is_array($transaction['token_info'] ?? null) ? $transaction['token_info'] : [];
            $actualContract = (string) ($tokenInfo['address'] ?? $transaction['contract_address'] ?? '');
            $actualTo = (string) ($transaction['to'] ?? '');
            $value = $this->normalizeDecimalString((string) ($transaction['value'] ?? ''));
            $timestamp = (int) floor(((int) ($transaction['block_timestamp'] ?? 0)) / 1000);

            if ($timestamp <= 0) {
                continue;
            }

            if (! hash_equals($address, $actualTo)) {
                continue;
            }

            if ($actualContract !== '' && ! hash_equals($contract, $actualContract)) {
                continue;
            }

            if ($createdAtTimestamp && $timestamp < ($createdAtTimestamp - 300)) {
                continue;
            }

            $transfer = [
                'tx_hash' => (string) ($transaction['transaction_id'] ?? ''),
                'network' => 'usdttrc20',
                'amount_units' => $value,
                'amount' => $this->tokenUnitsToDecimal($value, $decimals),
                'to' => $actualTo,
                'confirmed_at' => Carbon::createFromTimestamp($timestamp),
            ];

            if (
                $this->decimalStringCompare($value, $requiredUnits) === 0 &&
                $this->paymentReferenceAvailable($order, $transfer)
            ) {
                return [
                    'transfer' => $transfer,
                    'mismatches' => [],
                ];
            }
        }

        return [
            'transfer' => null,
            'mismatches' => [],
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

                if (! $confirmedAt) {
                    continue;
                }

                if ($createdAtTimestamp && $confirmedAt->timestamp < ($createdAtTimestamp - 300)) {
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
                        'mismatches' => [],
                    ];
                }
            }
        }

        return [
            'transfer' => null,
            'mismatches' => [],
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
            return [
                'transfer' => null,
                'mismatches' => [],
                'binance_diagnostics' => [
                    'status' => 'missing_api_credentials',
                    'returned_records' => 0,
                ],
            ];
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
            return [
                'transfer' => null,
                'mismatches' => [],
                'binance_diagnostics' => $this->lastBinanceRequestDiagnostics ?: [
                    'status' => 'request_failed',
                    'returned_records' => 0,
                ],
            ];
        }

        $diagnostics = [
            'status' => 'no_matching_deposit',
            'returned_records' => count($deposits),
            'expected_coin' => $token,
            'expected_network' => $binanceNetwork,
            'expected_amount' => $this->tokenUnitsToDecimal($requiredUnits, $decimals),
            'expected_address_suffix' => $this->addressSuffix($address),
            'rejections' => [],
            'closest_record' => null,
        ];

        foreach ($deposits as $deposit) {
            if (! is_array($deposit)) {
                $this->incrementDiagnosticRejection($diagnostics, 'invalid_record');

                continue;
            }

            $actualNetwork = strtoupper(trim((string) ($deposit['network'] ?? '')));
            $actualAddress = trim((string) ($deposit['address'] ?? ''));
            $value = $this->decimalToTokenUnits($deposit['amount'] ?? null, $decimals);
            $timestampMs = (int) (($deposit['completeTime'] ?? null) ?: ($deposit['insertTime'] ?? 0));
            $timestamp = (int) floor($timestampMs / 1000);
            $record = [
                'coin' => strtoupper((string) ($deposit['coin'] ?? '')),
                'network' => $actualNetwork,
                'status' => (int) ($deposit['status'] ?? -1),
                'amount' => is_scalar($deposit['amount'] ?? null) ? (string) $deposit['amount'] : null,
                'address_suffix' => $this->addressSuffix($actualAddress),
                'reference' => (string) ($deposit['txId'] ?? $deposit['id'] ?? ''),
                'confirmed_at' => $timestamp > 0 ? Carbon::createFromTimestamp($timestamp)->toIso8601String() : null,
                'transfer_type' => isset($deposit['transferType']) ? (int) $deposit['transferType'] : null,
                'wallet_type' => isset($deposit['walletType']) ? (int) $deposit['walletType'] : null,
            ];

            if (
                $diagnostics['closest_record'] === null ||
                ($value !== null && $this->decimalStringCompare($value, $requiredUnits) === 0)
            ) {
                $diagnostics['closest_record'] = $record;
            }

            if ($timestamp <= 0) {
                $this->incrementDiagnosticRejection($diagnostics, 'missing_timestamp');

                continue;
            }

            if ($record['coin'] !== $token) {
                $this->incrementDiagnosticRejection($diagnostics, 'coin');

                continue;
            }

            if ($record['status'] !== 1) {
                $this->incrementDiagnosticRejection($diagnostics, 'status');

                continue;
            }

            if (! $this->sameBinanceDepositNetwork($coin, $binanceNetwork, $actualNetwork)) {
                $this->incrementDiagnosticRejection($diagnostics, 'network');

                continue;
            }

            if (! $this->sameCryptoAddress($address, $actualAddress)) {
                $this->incrementDiagnosticRejection($diagnostics, 'address');

                continue;
            }

            if ($value === null) {
                $this->incrementDiagnosticRejection($diagnostics, 'invalid_amount');

                continue;
            }

            if ($createdAtTimestamp && $timestamp < ($createdAtTimestamp - 300)) {
                $this->incrementDiagnosticRejection($diagnostics, 'before_order');

                continue;
            }

            $transfer = [
                'tx_hash' => (string) ($deposit['txId'] ?? $deposit['id'] ?? ''),
                'network' => $coin,
                'amount_units' => $value,
                'amount' => $this->tokenUnitsToDecimal($value, $decimals),
                'to' => $actualAddress,
                'confirmed_at' => Carbon::createFromTimestamp($timestamp),
                'source' => 'binance_deposit_history',
            ];

            if (
                $this->decimalStringCompare($value, $requiredUnits) === 0 &&
                $this->paymentReferenceAvailable($order, $transfer)
            ) {
                return [
                    'transfer' => $transfer,
                    'mismatches' => [],
                    'binance_diagnostics' => [
                        'status' => 'matched',
                        'returned_records' => count($deposits),
                        'matched_record' => $record,
                    ],
                ];
            }

            $this->incrementDiagnosticRejection(
                $diagnostics,
                $this->decimalStringCompare($value, $requiredUnits) === 0
                    ? 'reference_unavailable'
                    : 'amount'
            );
        }

        return [
            'transfer' => null,
            'mismatches' => [],
            'binance_diagnostics' => $diagnostics,
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
        $uniqueAmount = $uniqueUnits / (10 ** self::CRYPTO_PAYMENT_PRECISION);

        return round($baseAmount + $uniqueAmount, self::CRYPTO_PAYMENT_PRECISION);
    }

    private function claimDirectCryptoAmount(Order $order, array $network, string $coin, float $baseAmount): float
    {
        $recoveryHours = max(1, (int) config('services.crypto_direct.recovery_hours', 24));

        Order::where('payment_method', 'crypto')
            ->whereNotNull('payment_match_key')
            ->where(function ($query) use ($recoveryHours): void {
                $query->where('status', 'paid')
                    ->orWhere(function ($expired) use ($recoveryHours): void {
                        $expired->where('status', 'cancelled')
                            ->where(function ($deadline) use ($recoveryHours): void {
                                $deadline->where('expired_at', '<=', now()->subHours($recoveryHours))
                                    ->orWhere(function ($missingExpiry) use ($recoveryHours): void {
                                        $missingExpiry->whereNull('expired_at')
                                            ->where('created_at', '<=', now()->subHours($recoveryHours + 1));
                                    });
                            });
                    });
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

    private function claimBinancePayAmount(Order $order, float $baseAmount, string $token, string $payId): float
    {
        $recoveryHours = max(1, (int) config('services.binance.pay.recovery_hours', 24));

        Order::where('payment_method', 'binance_pay')
            ->whereNotNull('payment_match_key')
            ->where(function ($query) use ($recoveryHours): void {
                $query->where('status', 'paid')
                    ->orWhere(function ($expired) use ($recoveryHours): void {
                        $expired->where('status', 'cancelled')
                            ->where(function ($deadline) use ($recoveryHours): void {
                                $deadline->where('expired_at', '<=', now()->subHours($recoveryHours))
                                    ->orWhere(function ($missingExpiry) use ($recoveryHours): void {
                                        $missingExpiry->whereNull('expired_at')
                                            ->where('created_at', '<=', now()->subHours($recoveryHours + 1));
                                    });
                            });
                    });
            })
            ->update(['payment_match_key' => null]);

        $uniqueMax = max(1, min(9999, (int) config('services.binance.pay.unique_max', 9999)));

        for ($attempt = 0; $attempt < $uniqueMax; $attempt++) {
            $amount = $this->binancePayAmount($baseAmount, $order->order_id, $token, $attempt, $uniqueMax);
            $matchKey = hash('sha256', implode('|', [
                'binance_pay',
                strtolower(trim($payId)),
                strtolower($token),
                number_format($amount, self::CRYPTO_PAYMENT_PRECISION, '.', ''),
            ]));

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

        throw new \Exception('No unique Binance Pay amount is currently available');
    }

    /**
     * @return array{platform_fee: int, unique_amount: int, total_amount: int}
     */
    private function claimGopayQrisAmount(Order $order, int $baseAmount): array
    {
        if ($baseAmount < 1) {
            throw new \Exception('Invalid GoPay QRIS amount');
        }

        $quarantineHours = max(168, (int) config('services.gopay_qris.amount_quarantine_hours', 168));

        Order::where('payment_method', 'gopay_qris')
            ->whereNotNull('payment_match_key')
            ->where(function ($query) use ($quarantineHours): void {
                $query->where(function ($paid) use ($quarantineHours): void {
                    $paid->where('status', 'paid')
                        ->where(function ($settled) use ($quarantineHours): void {
                            $settled->where('paid_at', '<=', now()->subHours($quarantineHours))
                                ->orWhere(function ($missingPaidAt) use ($quarantineHours): void {
                                    $missingPaidAt->whereNull('paid_at')
                                        ->where('updated_at', '<=', now()->subHours($quarantineHours));
                                });
                        });
                })
                    ->orWhere(function ($expired) use ($quarantineHours): void {
                        $expired->where('status', 'cancelled')
                            ->where(function ($deadline) use ($quarantineHours): void {
                                $deadline->where('expired_at', '<=', now()->subHours($quarantineHours))
                                    ->orWhere(function ($missingExpiry) use ($quarantineHours): void {
                                        $missingExpiry->whereNull('expired_at')
                                            ->where('created_at', '<=', now()->subHours($quarantineHours + 1));
                                    });
                            });
                    });
            })
            ->update(['payment_match_key' => null]);

        $uniqueMax = max(1, min(999, (int) config('services.gopay_qris.unique_max', 999)));
        $merchantReference = strtolower(trim((string) config('services.gopay_qris.merchant_reference')));
        $hash = (int) sprintf('%u', crc32($order->order_id.'|gopay-qris'));

        // Gross up the subtotal so a 0.7% deduction still leaves the merchant
        // with at least the original base amount.
        $platformFee = (int) ceil($baseAmount / 0.993) - $baseAmount;

        for ($attempt = 0; $attempt < $uniqueMax; $attempt++) {
            $uniqueAmount = (($hash + $attempt) % $uniqueMax) + 1;

            $amount = $baseAmount + $platformFee + $uniqueAmount;
            $matchKey = hash('sha256', implode('|', ['gopay_qris', $merchantReference, $amount]));

            try {
                $order->update([
                    'price' => $amount,
                    'payment_match_key' => $matchKey,
                ]);

                return [
                    'platform_fee' => $platformFee,
                    'unique_amount' => $uniqueAmount,
                    'total_amount' => $amount,
                ];
            } catch (QueryException $error) {
                if (! str_contains(strtolower($error->getMessage()), 'payment_match_key')) {
                    throw $error;
                }
            }
        }

        throw new \Exception('No unique GoPay QRIS amount is currently available');
    }

    private function binancePayAmount(
        float $baseAmount,
        string $orderId,
        string $token,
        int $attempt,
        int $uniqueMax
    ): float {
        if ($baseAmount <= 0) {
            throw new \Exception('Invalid Binance Pay amount');
        }

        $hash = (int) sprintf('%u', crc32($orderId.'|binance-pay|'.$token));
        $uniqueUnits = (($hash + max(0, $attempt)) % $uniqueMax) + 1;

        return round(
            $baseAmount + ($uniqueUnits / (10 ** self::CRYPTO_PAYMENT_PRECISION)),
            self::CRYPTO_PAYMENT_PRECISION
        );
    }

    private function directCryptoMatchKey(array $network, string $coin, float $amount): string
    {
        return hash('sha256', implode('|', [
            strtolower($coin),
            strtolower(trim((string) ($network['address'] ?? ''))),
            strtolower(trim((string) ($network['contract'] ?? ''))),
            number_format($amount, self::CRYPTO_PAYMENT_PRECISION, '.', ''),
        ]));
    }

    private function cancelPendingOrder(Order $order): void
    {
        $cancelled = Order::whereKey($order->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);

        if ($cancelled > 0) {
            $this->stockReservationService->release($order);
        }
    }

    private function ensureProductPurchasable(Product $product): void
    {
        if (! $product->is_visible) {
            throw new \Exception('This product is not available for purchase.');
        }

        if (! $product->isReadyForAutomaticCheckout()) {
            throw new \Exception('This product is not ready for automatic checkout.');
        }
    }

    private function ensureCartProductsPurchasable(Collection $items): void
    {
        foreach ($items as $item) {
            $product = $item->product ?? Product::find($item->product_id);

            if (! $product || ! $product->is_visible) {
                throw new \Exception('A product in this order is no longer available for purchase.');
            }

            if (! $product->isReadyForAutomaticCheckout()) {
                throw new \Exception('This product is not ready for automatic checkout.');
            }
        }
    }

    private function prepareOrder(
        $user,
        Product $product,
        Package $package,
        string $paymentMethod,
        ?Order $order,
        ?string $voucherCode,
        Carbon $expiresAt,
        ?string $orderId = null,
        ?string $coin = null,
        int $quantity = 1
    ): Order {
        return DB::transaction(function () use (
            $user,
            $product,
            $package,
            $paymentMethod,
            $order,
            $voucherCode,
            $expiresAt,
            $orderId,
            $coin,
            $quantity
        ): Order {
            $quote = $this->voucherService->quote(
                $package,
                $user,
                $order?->voucher_id ? null : $voucherCode,
                $order?->voucher_id,
                $order?->id,
                true,
                $paymentMethod,
                $coin,
                $quantity
            );

            $attributes = [
                'price' => $this->voucherService->checkoutPrice($quote, $paymentMethod),
                'voucher_id' => $quote['voucher_id'],
                'expired_at' => $expiresAt,
                'quantity' => $quantity,
            ];

            if ($order) {
                $order->update($attributes);
                $preparedOrder = $order->fresh();
            } else {
                $preparedOrder = Order::create($attributes + [
                    'order_id' => $orderId ?: 'ORDER-'.strtoupper(Str::random(10)),
                    'product_id' => $product->id,
                    'user_id' => $user->id,
                    'status' => 'pending',
                    'payment_method' => $paymentMethod,
                    'package_id' => $package->id,
                ]);
            }

            $this->syncOrderItems($preparedOrder, collect([(object) [
                'product_id' => $product->id,
                'package_id' => $package->id,
                'product' => $product,
                'package' => $package,
                'quantity' => $quantity,
            ]]));

            return $preparedOrder->fresh(['items']);
        });
    }

    private function prepareCartOrder(
        $user,
        Collection $items,
        string $paymentMethod,
        ?Order $order,
        ?string $voucherCode,
        Carbon $expiresAt,
        ?string $coin = null
    ): Order {
        if ($items->isEmpty()) {
            throw new \Exception('Your cart is empty.');
        }

        return DB::transaction(function () use (
            $user,
            $items,
            $paymentMethod,
            $order,
            $voucherCode,
            $expiresAt,
            $coin
        ): Order {
            if ($order) {
                $this->ensurePayableCartOrder($order, $user, $paymentMethod);
            }

            $quote = $this->voucherService->quoteCart(
                $items,
                $user,
                $order?->voucher_id ? null : $voucherCode,
                $order?->voucher_id,
                $order?->id,
                true,
                $paymentMethod,
                $coin
            );
            $firstItem = $items->first();
            $quantity = max(1, (int) $items->sum(fn ($item) => max(1, (int) $item->quantity)));
            $attributes = [
                'product_id' => $firstItem->product_id,
                'package_id' => $firstItem->package_id,
                'price' => $this->voucherService->checkoutPrice($quote, $paymentMethod),
                'voucher_id' => $quote['voucher_id'],
                'expired_at' => $expiresAt,
                'quantity' => $quantity,
            ];

            if ($order) {
                $order->update($attributes);
                $preparedOrder = $order->fresh();
            } else {
                $preparedOrder = Order::create($attributes + [
                    'order_id' => 'ORDER-'.strtoupper(Str::random(10)),
                    'user_id' => $user->id,
                    'status' => 'pending',
                    'payment_method' => $paymentMethod,
                ]);
            }

            $this->syncOrderItems($preparedOrder, $items);

            return $preparedOrder->fresh(['items']);
        });
    }

    private function syncOrderItems(Order $order, Collection $items): void
    {
        $order->items()->delete();

        foreach ($items as $item) {
            $product = $item->product ?? Product::findOrFail($item->product_id);
            $package = $item->package ?? Package::findOrFail($item->package_id);
            $quantity = max(1, (int) $item->quantity);
            $unitIdr = max(0, (int) ($item->unit_price_idr ?? $package->price));
            $unitUsdt = max(0, (float) ($item->unit_price_usdt ?? $package->price_usdt));

            $order->items()->create([
                'product_id' => $product->id,
                'package_id' => $package->id,
                'product_name' => (string) ($item->product_name ?? $product->name),
                'package_name' => (string) ($item->package_name ?? $package->name),
                'quantity' => $quantity,
                'unit_price_idr' => $unitIdr,
                'unit_price_usdt' => number_format($unitUsdt, 6, '.', ''),
                'line_total_idr' => $unitIdr * $quantity,
                'line_total_usdt' => number_format($unitUsdt * $quantity, 6, '.', ''),
            ]);
        }
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
            'amount' => number_format($amount, self::CRYPTO_PAYMENT_PRECISION, '.', ''),
            'base_amount' => number_format($baseAmount, self::CRYPTO_PAYMENT_PRECISION, '.', ''),
            'quantity' => (int) $order->quantity,
            'unique_amount' => number_format(max(0, $amount - $baseAmount), self::CRYPTO_PAYMENT_PRECISION, '.', ''),
            'decimals' => (int) ($network['decimals'] ?? 6),
            'created_at' => $order->created_at?->toIso8601String() ?: now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    private function normalizeBinancePayPayment(
        Order $order,
        float $baseAmount,
        float $amount,
        Carbon $expiresAt,
        array $pay
    ): array {
        return [
            'type' => 'binance_pay_personal',
            'token' => strtoupper(trim((string) ($pay['token'] ?? 'USDT'))),
            'pay_id' => trim((string) ($pay['pay_id'] ?? '')),
            'qr_content' => trim((string) ($pay['qr_content'] ?? '')),
            'amount' => number_format($amount, self::CRYPTO_PAYMENT_PRECISION, '.', ''),
            'base_amount' => number_format($baseAmount, self::CRYPTO_PAYMENT_PRECISION, '.', ''),
            'quantity' => (int) $order->quantity,
            'unique_amount' => number_format(max(0, $amount - $baseAmount), self::CRYPTO_PAYMENT_PRECISION, '.', ''),
            'created_at' => $order->created_at?->toIso8601String() ?: now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'scanner_status' => 'pending',
        ];
    }

    private function normalizeGopayQrisPayment(
        Order $order,
        int $baseAmount,
        int $platformFee,
        int $uniqueAmount,
        int $totalAmount,
        string $qrPayload,
        Carbon $expiresAt
    ): array {
        return [
            'type' => 'gopay_qris_notification',
            'merchant_name' => trim((string) config('services.gopay_qris.merchant_name', 'Aksa Xiterz')),
            'merchant_reference' => trim((string) config('services.gopay_qris.merchant_reference')),
            'amount' => $totalAmount,
            'base_amount' => $baseAmount,
            'platform_fee' => $platformFee,
            'unique_amount' => $uniqueAmount,
            'total_payment' => $totalAmount,
            'payment_method' => 'qris',
            'requires_manual_amount' => true,
            'qr_payload' => $qrPayload,
            'payment_number' => $qrPayload,
            'quantity' => (int) $order->quantity,
            'created_at' => $order->created_at?->toIso8601String() ?: now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'scanner_status' => 'pending',
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

    private function ensurePayableCartOrder(Order $order, $user, string $method): void
    {
        if (
            (int) $order->user_id !== (int) $user->id ||
            $order->payment_method !== $method ||
            $order->status !== 'pending'
        ) {
            throw new \Exception('Invalid order');
        }
    }

    private function checkoutQuantity(int $quantity): int
    {
        if ($quantity < 1) {
            throw new \Exception('Select at least one license key.');
        }

        return $quantity;
    }

    private function availableStockCount(Product $product, Package $package, ?Order $order): int
    {
        return LicenseStock::query()
            ->where('product_id', $product->id)
            ->where('package_id', $package->id)
            ->where('is_sold', false)
            ->where(function ($query) use ($order): void {
                $query->where(fn ($available) => $available->available());

                if ($order) {
                    $query->orWhere('reserved_order_id', $order->id);
                }
            })
            ->count();
    }

    private function ensureGopayQrisConfigured(): void
    {
        $config = config('services.gopay_qris', []);
        $payload = trim((string) ($config['static_payload'] ?? ''));

        if (
            ! is_array($config) ||
            ! ($config['enabled'] ?? false) ||
            $payload === '' ||
            blank($config['webhook_token'] ?? null) ||
            blank($config['webhook_secret'] ?? null) ||
            empty($config['allowed_devices'] ?? []) ||
            ! app(QrisPayloadService::class)->validate($payload)
        ) {
            throw new \Exception('GoPay QRIS checkout is not configured');
        }

        $payloadMerchant = mb_strtolower(app(QrisPayloadService::class)->merchantName($payload));
        $configuredMerchant = mb_strtolower(trim((string) ($config['merchant_name'] ?? '')));

        if ($configuredMerchant === '' || ! hash_equals($configuredMerchant, $payloadMerchant)) {
            throw new \Exception('GoPay QRIS merchant identity does not match');
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

    private function ensureBinancePayConfigured(): void
    {
        $pay = config('services.binance.pay', []);

        if (
            ! is_array($pay) ||
            ! ($pay['enabled'] ?? false) ||
            blank($pay['pay_id'] ?? null) ||
            blank($pay['api_key'] ?? null) ||
            blank($pay['api_secret'] ?? null)
        ) {
            throw new \Exception('Binance Pay checkout is not configured');
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

    private function incrementDiagnosticRejection(array &$diagnostics, string $reason): void
    {
        $diagnostics['rejections'][$reason] = ((int) ($diagnostics['rejections'][$reason] ?? 0)) + 1;
    }

    private function addressSuffix(string $address): string
    {
        $address = trim($address);

        return $address === '' ? '' : substr($address, -8);
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
        $this->lastBinanceRequestDiagnostics = null;

        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.binance.com'), '/');
        $apiKey = (string) ($config['api_key'] ?? '');
        $apiSecret = (string) ($config['api_secret'] ?? '');

        if ($baseUrl === '' || $apiKey === '' || $apiSecret === '') {
            return null;
        }

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $signature = hash_hmac('sha256', $query, $apiSecret);
        $url = $baseUrl.$path.'?'.$query.'&signature='.$signature;

        try {
            $response = Http::withOptions($this->gatewayHttpOptions())
                ->withHeaders([
                    'X-MBX-APIKEY' => $apiKey,
                ])
                ->timeout(20)
                ->get($url);
        } catch (\Exception $error) {
            $this->lastBinanceRequestDiagnostics = [
                'status' => 'connection_failed',
                'returned_records' => 0,
            ];

            Log::warning('Binance deposit history verification connection failed', [
                'path' => $path,
                'error_type' => class_basename($error),
            ]);

            return null;
        }

        $payload = $response->json();

        if (! $response->successful() || ! is_array($payload)) {
            $this->lastBinanceRequestDiagnostics = [
                'status' => 'request_failed',
                'returned_records' => 0,
                'http_status' => $response->status(),
                'code' => is_array($payload) ? ($payload['code'] ?? null) : null,
                'message' => is_array($payload) ? ($payload['msg'] ?? null) : null,
            ];

            Log::warning('Binance deposit history verification request failed', [
                'path' => $path,
                'status' => $response->status(),
                'code' => is_array($payload) ? ($payload['code'] ?? null) : null,
                'message' => is_array($payload) ? ($payload['msg'] ?? null) : null,
            ]);

            return null;
        }

        $this->lastBinanceRequestDiagnostics = [
            'status' => 'request_succeeded',
            'returned_records' => count($payload),
        ];

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
