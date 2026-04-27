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

    public function test_midtrans_customer_fee_percentage_is_configurable(): void
    {
        config(['payment.midtrans_customer_fee_percentage' => 50]);

        $service = new PaymentService;
        $method = new ReflectionMethod($service, 'midtransCustomerFeeConfig');
        $method->setAccessible(true);

        $config = $method->invoke($service);

        $this->assertNotNull($config);
        $this->assertSame(50, $config['payment_fee_configs'][0]['customer_percentage']);
        $this->assertNotContains('qris', array_column($config['payment_fee_configs'], 'payment_type'));
        $this->assertNotContains('other_qris', array_column($config['payment_fee_configs'], 'payment_type'));
        $this->assertNotContains('other_va', array_column($config['payment_fee_configs'], 'payment_type'));

        config(['payment.midtrans_customer_fee_percentage' => 0]);

        $this->assertNull($method->invoke($service));
    }
}
