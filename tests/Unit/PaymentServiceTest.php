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
            'services.crypto_direct.networks.usdtbsc.api_url' => 'https://api.etherscan.io/v2/api',
            'services.crypto_direct.networks.usdtbsc.api_key' => 'test-key',
            'services.crypto_direct.networks.usdtbsc.chain_id' => 56,
        ]);

        Http::fake([
            'https://api.etherscan.io/v2/api*' => Http::response([
                'status' => '1',
                'message' => 'OK',
                'result' => [
                    [
                        'hash' => '0xabc',
                        'timeStamp' => (string) now()->timestamp,
                        'contractAddress' => '0x55d398326f99059ff775485246999027b3197955',
                        'to' => '0x1111111111111111111111111111111111111111',
                        'value' => '1100123000000000000',
                    ],
                ],
            ], 200),
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
}
