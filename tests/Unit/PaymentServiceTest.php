<?php

namespace Tests\Unit;

use App\Services\PaymentService;
use ReflectionMethod;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    public function test_crypto_buyer_fee_uses_smaller_configured_defaults(): void
    {
        config([
            'payment.crypto_buyer_fee_rate' => 0.02,
            'payment.crypto_buyer_fee_minimum' => 0.10,
        ]);

        $service = new PaymentService;
        $method = new ReflectionMethod($service, 'cryptoCustomerTotal');
        $method->setAccessible(true);

        $this->assertEqualsWithDelta(10.20, $method->invoke($service, 10.00), 0.0001);
        $this->assertEqualsWithDelta(0.60, $method->invoke($service, 0.50), 0.0001);
    }

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
}
