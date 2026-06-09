<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    public function test_pakasir_payment_url_uses_order_amount_redirect_and_qris_flag(): void
    {
        config([
            'services.pakasir.slug' => 'aksaxiterz',
            'services.pakasir.url' => 'https://app.pakasir.com',
            'services.pakasir.return_url' => 'https://aksaxiterz.test/orders',
            'services.pakasir.qris_only' => true,
        ]);

        $service = new PaymentService;
        $method = new ReflectionMethod($service, 'pakasirPaymentUrl');
        $method->setAccessible(true);

        $url = $method->invoke($service, 'ORDER-ABC123', 22000);
        $parts = parse_url($url);

        parse_str($parts['query'] ?? '', $query);

        $this->assertSame('https', $parts['scheme']);
        $this->assertSame('app.pakasir.com', $parts['host']);
        $this->assertSame('/pay/aksaxiterz/22000', $parts['path']);
        $this->assertSame('ORDER-ABC123', $query['order_id'] ?? null);
        $this->assertSame('https://aksaxiterz.test/orders', $query['redirect'] ?? null);
        $this->assertSame('1', (string) ($query['qris_only'] ?? null));
    }

    public function test_pakasir_expiry_is_converted_from_utc_to_application_timezone(): void
    {
        config(['app.timezone' => 'Asia/Jakarta']);

        $method = new ReflectionMethod(PaymentService::class, 'pakasirExpiredAt');
        $expiresAt = $method->invoke(new PaymentService, '2026-06-09T18:34:00.123456789Z');

        $this->assertSame('Asia/Jakarta', $expiresAt->timezoneName);
        $this->assertSame('2026-06-10T01:34:00+07:00', $expiresAt->toIso8601String());
        $this->assertSame('2026-06-10 01:34:00', (new Order)->fromDateTime($expiresAt));
    }

    public function test_direct_crypto_amount_adds_small_unique_suffix(): void
    {
        config([
            'services.crypto_direct.unique_max' => 9999,
        ]);

        $service = new PaymentService;
        $method = new ReflectionMethod($service, 'directCryptoAmount');
        $method->setAccessible(true);

        $amount = $method->invoke($service, 10.00, 'ORDER-ABC123', 'usdttrc20');

        $this->assertGreaterThan(10.00, $amount);
        $this->assertLessThan(10.01, $amount);
        $this->assertEquals(round($amount, 6), $amount);
    }

    public function test_direct_crypto_amount_attempts_produce_distinct_suffixes(): void
    {
        config([
            'services.crypto_direct.unique_max' => 9999,
        ]);

        $service = new PaymentService;
        $method = new ReflectionMethod($service, 'directCryptoAmount');

        $first = $method->invoke($service, 10.00, 'ORDER-COLLISION', 'usdcbsc', 0);
        $second = $method->invoke($service, 10.00, 'ORDER-COLLISION', 'usdcbsc', 1);

        $this->assertNotSame($first, $second);
    }

    public function test_direct_bep20_scan_window_expands_with_order_age(): void
    {
        $order = new Order;
        $order->created_at = now()->subHours(20);

        $method = new ReflectionMethod(PaymentService::class, 'directBep20ScanBlocks');
        $scanBlocks = $method->invoke(new PaymentService, $order, [
            'rpc_scan_blocks' => 40000,
            'rpc_block_seconds' => 0.4,
        ]);

        $this->assertGreaterThanOrEqual(180000, $scanBlocks);
        $this->assertLessThanOrEqual(300000, $scanBlocks);
    }

    public function test_direct_bep20_scanner_matches_exact_usdt_transfer(): void
    {
        config([
            'services.crypto_direct.networks.usdtbsc.rpc_url' => 'https://bsc-rpc.test',
            'services.crypto_direct.networks.usdtbsc.rpc_scan_blocks' => 20,
            'services.crypto_direct.networks.usdtbsc.rpc_chunk_blocks' => 100,
        ]);

        Http::fake([
            'https://bsc-rpc.test' => $this->fakeBscRpcTransfer('0xabc', '1100123000000000000'),
        ]);

        $order = new Order([
            'order_id' => 'ORDER-CHAIN',
            'payment_method' => 'crypto',
            'payment_payload' => [
                'type' => 'direct_crypto',
                'network' => 'usdtbsc',
                'address' => '0x1111111111111111111111111111111111111111',
                'contract' => '0x55d398326f99059fF775485246999027B3197955',
                'amount' => '1.100123',
                'decimals' => 18,
            ],
        ]);
        $order->created_at = now()->subMinute();

        $transfer = (new PaymentService)->findDirectCryptoTransfer($order);

        $this->assertSame('0xabc', $transfer['tx_hash'] ?? null);
        $this->assertSame('usdtbsc', $transfer['network'] ?? null);
    }

    public function test_direct_bep20_scanner_matches_exact_usdc_transfer(): void
    {
        config([
            'services.crypto_direct.networks.usdcbsc.rpc_url' => 'https://bsc-rpc.test',
            'services.crypto_direct.networks.usdcbsc.rpc_scan_blocks' => 20,
            'services.crypto_direct.networks.usdcbsc.rpc_chunk_blocks' => 100,
        ]);

        Http::fake([
            'https://bsc-rpc.test' => $this->fakeBscRpcTransfer('0xusdc', '2100123000000000000'),
        ]);

        $order = new Order([
            'order_id' => 'ORDER-USDC-CHAIN',
            'payment_method' => 'crypto',
            'payment_payload' => [
                'type' => 'direct_crypto',
                'token' => 'USDC',
                'network' => 'usdcbsc',
                'address' => '0x1111111111111111111111111111111111111111',
                'contract' => '0x8ac76a51cc950d9822d68b83fe1ad97b32cd580d',
                'amount' => '2.100123',
                'decimals' => 18,
            ],
        ]);
        $order->created_at = now()->subMinute();

        $transfer = (new PaymentService)->findDirectCryptoTransfer($order);

        $this->assertSame('0xusdc', $transfer['tx_hash'] ?? null);
        $this->assertSame('usdcbsc', $transfer['network'] ?? null);
        $this->assertSame('2.100123', $transfer['amount'] ?? null);
    }

    public function test_direct_bep20_scanner_reports_amount_mismatch(): void
    {
        config([
            'services.crypto_direct.networks.usdtbsc.rpc_url' => 'https://bsc-rpc.test',
            'services.crypto_direct.networks.usdtbsc.rpc_scan_blocks' => 20,
            'services.crypto_direct.networks.usdtbsc.rpc_chunk_blocks' => 100,
        ]);

        Http::fake([
            'https://bsc-rpc.test' => $this->fakeBscRpcTransfer('0xunderpaid', '1000000000000000000'),
        ]);

        $order = new Order([
            'order_id' => 'ORDER-CHAIN',
            'payment_method' => 'crypto',
            'payment_payload' => [
                'type' => 'direct_crypto',
                'network' => 'usdtbsc',
                'address' => '0x1111111111111111111111111111111111111111',
                'contract' => '0x55d398326f99059fF775485246999027B3197955',
                'amount' => '1.100123',
                'decimals' => 18,
            ],
        ]);
        $order->created_at = now()->subMinute();

        $inspection = (new PaymentService)->inspectDirectCryptoPayment($order);

        $this->assertNull($inspection['transfer']);
        $this->assertSame('0xunderpaid', $inspection['mismatches'][0]['tx_hash'] ?? null);
        $this->assertSame('1.100123', $inspection['mismatches'][0]['expected_amount'] ?? null);
        $this->assertSame('1', $inspection['mismatches'][0]['received_amount'] ?? null);
    }

    public function test_direct_bep20_scanner_uses_rpc_logs_without_etherscan(): void
    {
        config([
            'services.crypto_direct.networks.usdtbsc.rpc_url' => 'https://bsc-rpc.test',
            'services.crypto_direct.networks.usdtbsc.rpc_scan_blocks' => 20,
            'services.crypto_direct.networks.usdtbsc.rpc_chunk_blocks' => 100,
        ]);

        Http::fake([
            'https://bsc-rpc.test' => $this->fakeBscRpcTransfer('0xrpcmatch', '1100123000000000000'),
        ]);

        $order = new Order([
            'order_id' => 'ORDER-RPC',
            'payment_method' => 'crypto',
            'payment_payload' => [
                'type' => 'direct_crypto',
                'network' => 'usdtbsc',
                'address' => '0x1111111111111111111111111111111111111111',
                'contract' => '0x55d398326f99059fF775485246999027B3197955',
                'amount' => '1.100123',
                'decimals' => 18,
            ],
        ]);
        $order->created_at = now()->subMinute();

        $transfer = (new PaymentService)->findDirectCryptoTransfer($order);

        $this->assertSame('0xrpcmatch', $transfer['tx_hash'] ?? null);
        $this->assertSame('usdtbsc', $transfer['network'] ?? null);
        $this->assertSame('1.100123', $transfer['amount'] ?? null);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'etherscan'));
    }

    public function test_direct_bep20_scanner_waits_for_configured_confirmations(): void
    {
        config([
            'services.crypto_direct.networks.usdtbsc.rpc_url' => 'https://bsc-rpc.test',
            'services.crypto_direct.networks.usdtbsc.rpc_scan_blocks' => 20,
            'services.crypto_direct.networks.usdtbsc.rpc_chunk_blocks' => 100,
            'services.crypto_direct.networks.usdtbsc.rpc_confirmations' => 5,
        ]);

        Http::fake([
            'https://bsc-rpc.test' => $this->fakeBscRpcTransfer('0xunconfirmed', '1100123000000000000', '0x64'),
        ]);

        $order = new Order([
            'order_id' => 'ORDER-UNCONFIRMED',
            'payment_method' => 'crypto',
            'payment_payload' => [
                'type' => 'direct_crypto',
                'network' => 'usdtbsc',
                'address' => '0x1111111111111111111111111111111111111111',
                'contract' => '0x55d398326f99059fF775485246999027B3197955',
                'amount' => '1.100123',
                'decimals' => 18,
            ],
        ]);
        $order->created_at = now()->subMinute();

        $transfer = (new PaymentService)->findDirectCryptoTransfer($order);

        $this->assertNull($transfer);
    }

    public function test_direct_bep20_scanner_ignores_removed_reorg_logs(): void
    {
        config([
            'services.crypto_direct.networks.usdtbsc.rpc_url' => 'https://bsc-rpc.test',
            'services.crypto_direct.networks.usdtbsc.rpc_scan_blocks' => 20,
            'services.crypto_direct.networks.usdtbsc.rpc_chunk_blocks' => 100,
        ]);

        Http::fake([
            'https://bsc-rpc.test' => $this->fakeBscRpcTransfer('0xremoved', '1100123000000000000', '0x5f', true),
        ]);

        $order = new Order([
            'order_id' => 'ORDER-REMOVED',
            'payment_method' => 'crypto',
            'payment_payload' => [
                'type' => 'direct_crypto',
                'network' => 'usdtbsc',
                'address' => '0x1111111111111111111111111111111111111111',
                'contract' => '0x55d398326f99059fF775485246999027B3197955',
                'amount' => '1.100123',
                'decimals' => 18,
            ],
        ]);
        $order->created_at = now()->subMinute();

        $transfer = (new PaymentService)->findDirectCryptoTransfer($order);

        $this->assertNull($transfer);
    }

    public function test_direct_bep20_scanner_uses_saved_block_cursor_with_overlap(): void
    {
        config([
            'services.crypto_direct.networks.usdtbsc.rpc_url' => 'https://bsc-rpc.test',
            'services.crypto_direct.networks.usdtbsc.rpc_scan_blocks' => 40000,
            'services.crypto_direct.networks.usdtbsc.rpc_chunk_blocks' => 100,
            'services.crypto_direct.networks.usdtbsc.rpc_confirmations' => 5,
            'services.crypto_direct.networks.usdtbsc.rpc_overlap_blocks' => 10,
        ]);

        $logFilters = [];

        Http::fake([
            'https://bsc-rpc.test' => function ($request) use (&$logFilters) {
                $payload = $request->data();

                if (($payload['method'] ?? '') === 'eth_blockNumber') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x3e8']);
                }

                if (($payload['method'] ?? '') === 'eth_getLogs') {
                    $logFilters[] = $payload['params'][0] ?? [];

                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);
                }

                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['message' => 'unexpected']]);
            },
        ]);

        $order = new Order([
            'order_id' => 'ORDER-CURSOR',
            'payment_method' => 'crypto',
            'payment_payload' => [
                'type' => 'direct_crypto',
                'network' => 'usdtbsc',
                'address' => '0x1111111111111111111111111111111111111111',
                'contract' => '0x55d398326f99059fF775485246999027B3197955',
                'amount' => '1.100123',
                'decimals' => 18,
                'last_scanned_block' => 990,
            ],
        ]);
        $order->created_at = now()->subHours(20);

        $inspection = (new PaymentService)->inspectDirectCryptoPayment($order);

        $this->assertNull($inspection['transfer']);
        $this->assertSame(995, $inspection['last_scanned_block']);
        $this->assertCount(1, $logFilters);
        $this->assertSame('0x3d4', $logFilters[0]['fromBlock'] ?? null);
        $this->assertSame('0x3e3', $logFilters[0]['toBlock'] ?? null);
    }

    public function test_direct_crypto_scanner_matches_binance_deposit_history_fallback(): void
    {
        config([
            'services.crypto_direct.networks.usdttrc20.api_url' => 'https://trongrid.test',
            'services.crypto_direct.networks.usdttrc20.binance_network' => 'TRX',
            'services.binance.deposit_fallback.enabled' => true,
            'services.binance.deposit_fallback.api_key' => 'test-key',
            'services.binance.deposit_fallback.api_secret' => 'test-secret',
            'services.binance.deposit_fallback.base_url' => 'https://binance.test',
            'services.binance.deposit_fallback.recv_window' => 5000,
        ]);

        Http::fake([
            'https://trongrid.test/*' => Http::response(['data' => []], 200),
            'https://binance.test/*' => Http::response([[
                'id' => '769800519366885376',
                'amount' => '6.00858100',
                'coin' => 'USDT',
                'network' => 'TRX',
                'status' => 1,
                'address' => 'TJ5hvdAa5MVFebXXGhL3R81F6SpAMUi7z5',
                'txId' => 'Off-chain Transfer 372959316369',
                'insertTime' => now()->subMinute()->timestamp * 1000,
                'completeTime' => now()->subMinute()->timestamp * 1000,
                'transferType' => 0,
            ]], 200),
        ]);

        $order = new Order([
            'order_id' => 'ORDER-BINANCE',
            'payment_method' => 'crypto',
            'payment_payload' => [
                'type' => 'direct_crypto',
                'network' => 'usdttrc20',
                'address' => 'TJ5hvdAa5MVFebXXGhL3R81F6SpAMUi7z5',
                'contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                'amount' => '6.008581',
                'decimals' => 6,
            ],
        ]);
        $order->created_at = now()->subMinutes(5);

        $transfer = (new PaymentService)->findDirectCryptoTransfer($order);

        $this->assertSame('Off-chain Transfer 372959316369', $transfer['tx_hash'] ?? null);
        $this->assertSame('usdttrc20', $transfer['network'] ?? null);
        $this->assertSame('6.008581', $transfer['amount'] ?? null);
        $this->assertSame('binance_deposit_history', $transfer['source'] ?? null);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://binance.test/sapi/v1/capital/deposit/hisrec?') &&
                str_contains($request->url(), 'signature=') &&
                ($request->header('X-MBX-APIKEY')[0] ?? null) === 'test-key';
        });
    }

    public function test_direct_crypto_scanner_uses_binance_fallback_when_chain_scanner_fails(): void
    {
        config([
            'services.crypto_direct.networks.usdttrc20.api_url' => 'https://trongrid.test',
            'services.crypto_direct.networks.usdttrc20.binance_network' => 'TRC20',
            'services.binance.deposit_fallback.enabled' => true,
            'services.binance.deposit_fallback.api_key' => 'test-key',
            'services.binance.deposit_fallback.api_secret' => 'test-secret',
            'services.binance.deposit_fallback.base_url' => 'https://binance.test',
            'services.binance.deposit_fallback.recv_window' => 5000,
        ]);

        Http::fake([
            'https://trongrid.test/*' => Http::response(['error' => 'temporarily unavailable'], 503),
            'https://binance.test/*' => Http::response([[
                'id' => '769800519366885376',
                'amount' => '6.00858100',
                'coin' => 'USDT',
                'network' => 'TRX',
                'status' => 1,
                'address' => 'TJ5hvdAa5MVFebXXGhL3R81F6SpAMUi7z5',
                'txId' => 'Off-chain Transfer 372959316369',
                'insertTime' => now()->subMinute()->timestamp * 1000,
                'completeTime' => now()->subMinute()->timestamp * 1000,
            ]], 200),
        ]);

        $order = new Order([
            'order_id' => 'ORDER-BINANCE-CHAIN-FAIL',
            'payment_method' => 'crypto',
            'payment_payload' => [
                'type' => 'direct_crypto',
                'network' => 'usdttrc20',
                'address' => 'TJ5hvdAa5MVFebXXGhL3R81F6SpAMUi7z5',
                'contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                'amount' => '6.008581',
                'decimals' => 6,
            ],
        ]);
        $order->created_at = now()->subMinutes(5);

        $transfer = (new PaymentService)->findDirectCryptoTransfer($order);

        $this->assertSame('Off-chain Transfer 372959316369', $transfer['tx_hash'] ?? null);
        $this->assertSame('binance_deposit_history', $transfer['source'] ?? null);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://trongrid.test/'));
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://binance.test/sapi/v1/capital/deposit/hisrec?'));
    }

    public function test_direct_crypto_binance_fallback_uses_usdc_coin_for_usdc_bsc(): void
    {
        config([
            'services.crypto_direct.networks.usdcbsc.binance_network' => 'BSC',
            'services.binance.deposit_fallback.enabled' => true,
            'services.binance.deposit_fallback.primary' => true,
            'services.binance.deposit_fallback.api_key' => 'test-key',
            'services.binance.deposit_fallback.api_secret' => 'test-secret',
            'services.binance.deposit_fallback.base_url' => 'https://binance.test',
            'services.binance.deposit_fallback.recv_window' => 5000,
        ]);

        Http::fake([
            'https://binance.test/*' => Http::response([[
                'id' => 'usdc-deposit-1',
                'amount' => '2.10012300',
                'coin' => 'USDC',
                'network' => 'BSC',
                'status' => 1,
                'address' => '0x1111111111111111111111111111111111111111',
                'txId' => '0xbinanceusdc',
                'insertTime' => now()->subMinute()->timestamp * 1000,
                'completeTime' => now()->subMinute()->timestamp * 1000,
            ]], 200),
        ]);

        $order = new Order([
            'order_id' => 'ORDER-USDC-BINANCE',
            'payment_method' => 'crypto',
            'payment_payload' => [
                'type' => 'direct_crypto',
                'token' => 'USDC',
                'network' => 'usdcbsc',
                'address' => '0x1111111111111111111111111111111111111111',
                'contract' => '0x8ac76a51cc950d9822d68b83fe1ad97b32cd580d',
                'amount' => '2.100123',
                'decimals' => 18,
            ],
        ]);
        $order->created_at = now()->subMinutes(5);

        $transfer = (new PaymentService)->findDirectCryptoTransfer($order);

        $this->assertSame('0xbinanceusdc', $transfer['tx_hash'] ?? null);
        $this->assertSame('usdcbsc', $transfer['network'] ?? null);
        $this->assertSame('2.100123', $transfer['amount'] ?? null);
        $this->assertSame('binance_deposit_history', $transfer['source'] ?? null);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://binance.test/sapi/v1/capital/deposit/hisrec?') &&
                str_contains($request->url(), 'coin=USDC') &&
                str_contains($request->url(), 'signature=');
        });
    }

    public function test_direct_crypto_scanner_can_prioritize_binance_deposit_history(): void
    {
        config([
            'services.crypto_direct.networks.usdttrc20.api_url' => 'https://trongrid.test',
            'services.crypto_direct.networks.usdttrc20.binance_network' => 'TRX',
            'services.binance.deposit_fallback.enabled' => true,
            'services.binance.deposit_fallback.primary' => true,
            'services.binance.deposit_fallback.api_key' => 'test-key',
            'services.binance.deposit_fallback.api_secret' => 'test-secret',
            'services.binance.deposit_fallback.base_url' => 'https://binance.test',
            'services.binance.deposit_fallback.recv_window' => 5000,
        ]);

        Http::fake([
            'https://binance.test/*' => Http::response([[
                'id' => '769800519366885376',
                'amount' => '6.00858100',
                'coin' => 'USDT',
                'network' => 'TRX',
                'status' => 1,
                'address' => 'TJ5hvdAa5MVFebXXGhL3R81F6SpAMUi7z5',
                'txId' => 'Off-chain Transfer 372959316369',
                'insertTime' => now()->subMinute()->timestamp * 1000,
                'completeTime' => now()->subMinute()->timestamp * 1000,
            ]], 200),
            'https://trongrid.test/*' => Http::response(['data' => [[
                'transaction_id' => 'onchain-should-not-be-needed',
                'to' => 'TJ5hvdAa5MVFebXXGhL3R81F6SpAMUi7z5',
                'value' => '6008581',
                'block_timestamp' => now()->timestamp * 1000,
            ]]], 200),
        ]);

        $order = new Order([
            'order_id' => 'ORDER-BINANCE-FIRST',
            'payment_method' => 'crypto',
            'payment_payload' => [
                'type' => 'direct_crypto',
                'network' => 'usdttrc20',
                'address' => 'TJ5hvdAa5MVFebXXGhL3R81F6SpAMUi7z5',
                'contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                'amount' => '6.008581',
                'decimals' => 6,
            ],
        ]);
        $order->created_at = now()->subMinutes(5);

        $transfer = (new PaymentService)->findDirectCryptoTransfer($order);

        $this->assertSame('Off-chain Transfer 372959316369', $transfer['tx_hash'] ?? null);
        $this->assertSame('binance_deposit_history', $transfer['source'] ?? null);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://binance.test/sapi/v1/capital/deposit/hisrec?'));
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://trongrid.test/'));
    }

    private function fakeBscRpcTransfer(
        string $hash,
        string $units,
        string $blockNumber = '0x5f',
        bool $removed = false
    ): callable
    {
        return function ($request) use ($hash, $units, $blockNumber, $removed) {
            $payload = $request->data();
            $method = $payload['method'] ?? '';

            if ($method === 'eth_blockNumber') {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => '0x64',
                ], 200);
            }

            if ($method === 'eth_getLogs') {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [[
                        'blockNumber' => $blockNumber,
                        'transactionHash' => $hash,
                        'data' => '0x'.str_pad(dechex((int) $units), 64, '0', STR_PAD_LEFT),
                        'removed' => $removed,
                    ]],
                ], 200);
            }

            if ($method === 'eth_getBlockByNumber') {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'timestamp' => '0x'.dechex(now()->timestamp),
                    ],
                ], 200);
            }

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => ['message' => 'unexpected method'],
            ], 500);
        };
    }
}
