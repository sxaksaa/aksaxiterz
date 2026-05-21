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

    private function fakeBscRpcTransfer(string $hash, string $units): callable
    {
        return function ($request) use ($hash, $units) {
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
                        'blockNumber' => '0x60',
                        'transactionHash' => $hash,
                        'data' => '0x'.str_pad(dechex((int) $units), 64, '0', STR_PAD_LEFT),
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
