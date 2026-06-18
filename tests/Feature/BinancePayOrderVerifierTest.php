<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Services\BinancePayOrderVerifier;
use App\Services\OrderFulfillmentService;
use App\Services\PaymentService;
use App\Services\StockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PDO;
use Tests\TestCase;

class BinancePayOrderVerifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for Binance Pay verifier tests.');
        }

        parent::setUp();
        Cache::forget('payment-scan:binance-pay:cooldown');
    }

    public function test_exact_incoming_personal_transfer_is_fulfilled_automatically(): void
    {
        $order = $this->binancePayOrder();
        LicenseStock::create([
            'product_id' => $order->product_id,
            'package_id' => $order->package_id,
            'license_key' => 'BINANCE-PAY-LICENSE',
            'is_sold' => false,
        ]);
        app(StockReservationService::class)->reserve($order);

        $paymentService = Mockery::mock(PaymentService::class);
        $paymentService->shouldReceive('getBinancePayTransactions')
            ->once()
            ->andReturn([
                'transactions' => [[
                    'orderType' => 'C2C',
                    'transactionId' => 'M_P_71505104267788288',
                    'transactionTime' => now()->getTimestampMs(),
                    'amount' => '1.10012300',
                    'currency' => 'USDT',
                    'payerInfo' => [
                        'name' => 'Customer',
                        'type' => 'USER',
                    ],
                ]],
                'diagnostics' => [
                    'status' => 'request_succeeded',
                    'returned_records' => 1,
                ],
            ]);

        $result = (new BinancePayOrderVerifier(
            $paymentService,
            app(OrderFulfillmentService::class),
            app(StockReservationService::class),
        ))->verify($order);

        $order->refresh();

        $this->assertSame('paid', $result['status']);
        $this->assertSame('m_p_71505104267788288', $order->payment_reference);
        $this->assertSame('matched', $order->payment_payload['scanner_status'] ?? null);
        $this->assertSame('Customer', $order->payment_payload['payer_name'] ?? null);
        $this->assertDatabaseHas('licenses', [
            'order_id' => $order->order_id,
            'license_key' => 'BINANCE-PAY-LICENSE',
        ]);
    }

    public function test_wrong_amount_or_outgoing_transfer_does_not_pay_order(): void
    {
        $order = $this->binancePayOrder();
        $paymentService = Mockery::mock(PaymentService::class);
        $paymentService->shouldReceive('getBinancePayTransactions')
            ->once()
            ->andReturn([
                'transactions' => [
                    [
                        'orderType' => 'C2C',
                        'transactionId' => 'WRONG-AMOUNT',
                        'transactionTime' => now()->getTimestampMs(),
                        'amount' => '1.100124',
                        'currency' => 'USDT',
                    ],
                    [
                        'orderType' => 'C2C',
                        'transactionId' => 'OUTGOING',
                        'transactionTime' => now()->getTimestampMs(),
                        'amount' => '-1.100123',
                        'currency' => 'USDT',
                    ],
                ],
                'diagnostics' => [
                    'status' => 'request_succeeded',
                    'returned_records' => 2,
                ],
            ]);

        $result = (new BinancePayOrderVerifier(
            $paymentService,
            app(OrderFulfillmentService::class),
            app(StockReservationService::class),
        ))->verify($order);

        $this->assertSame('pending', $result['status']);
        $this->assertNull($order->fresh()->payment_reference);
        $this->assertSame('pending', $order->fresh()->payment_payload['scanner_status'] ?? null);
    }

    private function binancePayOrder(): Order
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
            'price' => 20000,
            'price_usdt' => 1.1,
        ]);

        return Order::create([
            'order_id' => 'ORDER-BINANCE-PAY',
            'product_id' => $product->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'payment_method' => 'binance_pay',
            'price' => 1.100123,
            'package_id' => $package->id,
            'payment_payload' => [
                'type' => 'binance_pay_personal',
                'token' => 'USDT',
                'pay_id' => '123456789',
                'amount' => '1.100123',
                'base_amount' => '1.100000',
                'unique_amount' => '0.000123',
                'expires_at' => now()->addMinutes(10)->toIso8601String(),
                'scanner_status' => 'pending',
            ],
            'payment_match_key' => hash('sha256', 'binance-pay-test-match'),
            'expired_at' => now()->addMinutes(10),
        ]);
    }
}
