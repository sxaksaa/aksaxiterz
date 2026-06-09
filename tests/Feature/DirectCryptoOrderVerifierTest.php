<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Services\DirectCryptoOrderVerifier;
use App\Services\OrderFulfillmentService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DirectCryptoOrderVerifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for this database-backed verifier test.');
        }

        parent::setUp();
    }

    public function test_verified_crypto_payment_is_marked_paid_when_license_stock_is_missing(): void
    {
        $order = $this->directCryptoOrder();
        $paymentService = Mockery::mock(PaymentService::class);
        $paymentService->shouldReceive('inspectDirectCryptoPayment')
            ->once()
            ->andReturn([
                'transfer' => [
                    'tx_hash' => '0xpaidwithoutstock',
                    'network' => 'usdtbsc',
                    'amount_units' => '1100123000000000000',
                    'amount' => '1.100123',
                    'to' => '0x1111111111111111111111111111111111111111',
                    'confirmed_at' => now(),
                ],
                'mismatches' => [],
            ]);

        $result = (new DirectCryptoOrderVerifier(
            $paymentService,
            app(OrderFulfillmentService::class),
        ))->verify($order);

        $order->refresh();

        $this->assertSame('paid', $result['status']);
        $this->assertTrue($result['delivery_pending']);
        $this->assertStringContainsString('manual delivery', $result['message']);
        $this->assertSame('paid', $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame('matched', $order->payment_payload['scanner_status'] ?? null);
        $this->assertSame('0xpaidwithoutstock', $order->payment_payload['tx_hash'] ?? null);
        $this->assertSame('0xpaidwithoutstock', $order->payment_reference);
        $this->assertDatabaseMissing('licenses', [
            'order_id' => $order->order_id,
        ]);
    }

    public function test_recently_cancelled_crypto_order_is_still_verified(): void
    {
        $order = $this->directCryptoOrder([
            'status' => 'cancelled',
        ]);
        $paymentService = Mockery::mock(PaymentService::class);
        $paymentService->shouldReceive('inspectDirectCryptoPayment')
            ->once()
            ->andReturn([
                'transfer' => [
                    'tx_hash' => '0xcancelled-late-transfer',
                    'network' => 'usdtbsc',
                    'amount_units' => '1100123000000000000',
                    'amount' => '1.100123',
                    'to' => '0x1111111111111111111111111111111111111111',
                    'confirmed_at' => now(),
                ],
                'mismatches' => [],
            ]);

        $result = (new DirectCryptoOrderVerifier(
            $paymentService,
            app(OrderFulfillmentService::class),
        ))->verify($order);

        $this->assertSame('paid', $result['status']);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('0xcancelled-late-transfer', $order->fresh()->payment_reference);
    }

    public function test_expired_crypto_order_is_cancelled_without_verification(): void
    {
        $order = $this->directCryptoOrder([
            'expired_at' => now()->subMinutes(16),
        ]);
        $paymentService = Mockery::mock(PaymentService::class);
        $paymentService->shouldNotReceive('inspectDirectCryptoPayment');

        $result = (new DirectCryptoOrderVerifier(
            $paymentService,
            app(OrderFulfillmentService::class),
        ))->verify($order);

        $this->assertSame('cancelled', $result['status']);
        $this->assertStringContainsString('expired', $result['message']);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->payment_match_key);
    }

    public function test_crypto_payment_within_grace_period_is_still_verified(): void
    {
        config(['services.crypto_direct.grace_minutes' => 15]);

        $order = $this->directCryptoOrder([
            'expired_at' => now()->subMinutes(5),
        ]);
        $paymentService = Mockery::mock(PaymentService::class);
        $paymentService->shouldReceive('inspectDirectCryptoPayment')
            ->once()
            ->andReturn([
                'transfer' => [
                    'tx_hash' => '0xwithingrace',
                    'network' => 'usdtbsc',
                    'amount_units' => '1100123000000000000',
                    'amount' => '1.100123',
                    'to' => '0x1111111111111111111111111111111111111111',
                    'confirmed_at' => now(),
                ],
                'mismatches' => [],
            ]);

        $result = (new DirectCryptoOrderVerifier(
            $paymentService,
            app(OrderFulfillmentService::class),
        ))->verify($order);

        $this->assertSame('paid', $result['status']);
        $this->assertSame('0xwithingrace', $order->fresh()->payment_reference);
    }

    public function test_crypto_transfer_reference_cannot_pay_two_orders(): void
    {
        $usedOrder = $this->directCryptoOrder([
            'status' => 'paid',
            'payment_reference' => '0xalreadyused',
        ]);
        $order = Order::create([
            'order_id' => 'ORDER-SECOND',
            'product_id' => $usedOrder->product_id,
            'user_id' => $usedOrder->user_id,
            'status' => 'pending',
            'payment_method' => 'crypto',
            'price' => 1.100123,
            'package_id' => $usedOrder->package_id,
            'payment_payload' => array_merge($usedOrder->payment_payload, [
                'tx_hash' => null,
                'scanner_status' => 'pending',
            ]),
            'payment_match_key' => hash('sha256', 'second-order-match-key'),
            'expired_at' => now()->addHour(),
        ]);
        $paymentService = Mockery::mock(PaymentService::class);
        $paymentService->shouldReceive('inspectDirectCryptoPayment')
            ->once()
            ->andReturn([
                'transfer' => [
                    'tx_hash' => '0xalreadyused',
                    'network' => 'usdtbsc',
                    'amount_units' => '1100123000000000000',
                    'amount' => '1.100123',
                    'to' => '0x1111111111111111111111111111111111111111',
                    'confirmed_at' => now(),
                ],
                'mismatches' => [],
            ]);

        $result = (new DirectCryptoOrderVerifier(
            $paymentService,
            app(OrderFulfillmentService::class),
        ))->verify($order);

        $this->assertSame('pending', $result['status']);
        $this->assertStringContainsString('cannot be matched automatically', $result['message']);
        $this->assertNull($order->fresh()->payment_reference);
    }

    private function directCryptoOrder(array $overrides = []): Order
    {
        $user = User::factory()->create();
        $category = Category::create([
            'name' => 'Test',
            'slug' => 'test',
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'slug' => 'test-product',
            'status' => Product::STATUS_READY,
            'description' => 'Test product description.',
        ]);
        $package = Package::create([
            'product_id' => $product->id,
            'name' => '1 Day',
            'price' => 10000,
            'price_usdt' => 1.1,
        ]);

        return Order::create(array_merge([
            'order_id' => 'ORDER-NOSTOCK',
            'product_id' => $product->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'payment_method' => 'crypto',
            'price' => 1.100123,
            'package_id' => $package->id,
            'payment_payload' => [
                'type' => 'direct_crypto',
                'token' => 'USDT',
                'network' => 'usdtbsc',
                'network_label' => 'BSC BNB Smart Chain (BEP20)',
                'network_short_label' => 'BEP20',
                'address' => '0x1111111111111111111111111111111111111111',
                'contract' => '0x55d398326f99059fF775485246999027B3197955',
                'amount' => '1.100123',
                'base_amount' => '1.100000',
                'unique_amount' => '0.000123',
                'decimals' => 18,
                'expires_at' => now()->addHour()->toIso8601String(),
            ],
            'payment_match_key' => hash('sha256', 'default-test-match-key'),
            'expired_at' => now()->addHour(),
        ], $overrides));
    }
}
