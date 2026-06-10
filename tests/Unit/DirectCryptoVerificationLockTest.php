<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Services\DirectCryptoOrderVerifier;
use App\Services\OrderFulfillmentService;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class DirectCryptoVerificationLockTest extends TestCase
{
    public function test_concurrent_crypto_check_does_not_start_a_second_scan(): void
    {
        $order = new Order([
            'order_id' => 'ORDER-LOCKED-CHECK',
            'status' => 'pending',
            'payment_method' => 'crypto',
            'payment_payload' => [
                'type' => 'direct_crypto',
            ],
        ]);
        $order->id = 999;

        $paymentService = Mockery::mock(PaymentService::class);
        $paymentService->shouldNotReceive('inspectDirectCryptoPayment');
        $lock = Cache::lock('payment-verify:crypto:999', 120);

        $this->assertTrue($lock->get());

        try {
            $result = (new DirectCryptoOrderVerifier(
                $paymentService,
                Mockery::mock(OrderFulfillmentService::class),
            ))->verify($order);
        } finally {
            $lock->release();
        }

        $this->assertSame('pending', $result['status']);
        $this->assertStringContainsString('already being checked', $result['message']);
    }
}
