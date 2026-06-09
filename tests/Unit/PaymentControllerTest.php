<?php

namespace Tests\Unit;

use App\Http\Controllers\PaymentController;
use App\Models\Order;
use ReflectionMethod;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
{
    public function test_pay_again_preserves_configured_usdc_network(): void
    {
        $order = new Order([
            'payment_method' => 'crypto',
            'payment_payload' => [
                'type' => 'direct_crypto',
                'token' => 'USDC',
                'network' => 'usdcbsc',
            ],
        ]);

        $this->assertSame('usdcbsc', $this->retryCryptoNetwork($order));
    }

    public function test_pay_again_falls_back_for_unknown_crypto_network(): void
    {
        $order = new Order([
            'payment_method' => 'crypto',
            'payment_payload' => [
                'type' => 'direct_crypto',
                'network' => 'unknown',
            ],
        ]);

        $this->assertSame('usdttrc20', $this->retryCryptoNetwork($order));
    }

    private function retryCryptoNetwork(Order $order): string
    {
        $method = new ReflectionMethod(PaymentController::class, 'retryCryptoNetwork');

        return $method->invoke(app(PaymentController::class), $order);
    }
}
