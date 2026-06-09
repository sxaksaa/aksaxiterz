<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Services\PakasirOrderVerifier;
use Tests\TestCase;

class PakasirOrderVerifierTest extends TestCase
{
    public function test_valid_payload_requires_matching_order_amount_and_project(): void
    {
        config(['services.pakasir.slug' => 'aksaxiterz']);

        $order = new Order([
            'order_id' => 'ORDER-PAKASIR-VERIFY',
            'payment_method' => 'pakasir',
            'price' => 30000,
        ]);
        $verifier = app(PakasirOrderVerifier::class);

        $this->assertTrue($verifier->validPayload($order, [
            'transaction' => [
                'order_id' => 'ORDER-PAKASIR-VERIFY',
                'amount' => 30000,
                'project' => 'aksaxiterz',
                'status' => 'completed',
            ],
        ]));

        $this->assertFalse($verifier->validPayload($order, [
            'order_id' => 'ORDER-PAKASIR-VERIFY',
            'amount' => 30001,
            'project' => 'aksaxiterz',
        ]));
    }
}
