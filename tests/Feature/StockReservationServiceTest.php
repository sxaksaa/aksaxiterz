<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderFulfillmentService;
use App\Services\PaymentService;
use App\Services\StockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

class StockReservationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for stock reservation tests.');
        }

        parent::setUp();
    }

    public function test_one_stock_cannot_be_reserved_by_two_orders(): void
    {
        [$firstOrder, $secondOrder] = $this->ordersSharingOneStock();
        $service = app(StockReservationService::class);

        $service->reserve($firstOrder);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Automatic delivery is unavailable');

        $service->reserve($secondOrder);
    }

    public function test_fulfillment_consumes_the_order_reserved_stock(): void
    {
        [$order] = $this->ordersSharingOneStock();
        $reservation = app(StockReservationService::class)->reserve($order);

        $license = app(OrderFulfillmentService::class)->fulfill($order);

        $this->assertSame($reservation->license_key, $license->license_key);
        $this->assertTrue($reservation->fresh()->is_sold);
        $this->assertNull($reservation->fresh()->reserved_order_id);
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_late_payment_cancels_replacement_and_consumes_its_reserved_stock(): void
    {
        [$oldOrder, $replacement] = $this->ordersSharingOneStock();
        $oldOrder->update([
            'status' => 'cancelled',
            'replaced_by' => $replacement->id,
        ]);
        $reservation = app(StockReservationService::class)->reserve($replacement);

        $license = app(OrderFulfillmentService::class)->fulfill($oldOrder);

        $this->assertSame($reservation->license_key, $license->license_key);
        $this->assertSame('paid', $oldOrder->fresh()->status);
        $this->assertSame('cancelled', $replacement->fresh()->status);
        $this->assertTrue($reservation->fresh()->is_sold);
        $this->assertNull($reservation->fresh()->reserved_order_id);
    }

    public function test_late_payment_releases_stale_reservation_from_cancelled_replacement(): void
    {
        [$oldOrder, $replacement] = $this->ordersSharingOneStock();
        $oldOrder->update([
            'status' => 'cancelled',
            'replaced_by' => $replacement->id,
        ]);
        $reservation = app(StockReservationService::class)->reserve($replacement);
        $replacement->update(['status' => 'cancelled']);

        $license = app(OrderFulfillmentService::class)->fulfill($oldOrder);

        $this->assertSame($reservation->license_key, $license->license_key);
        $this->assertSame('paid', $oldOrder->fresh()->status);
        $this->assertTrue($reservation->fresh()->is_sold);
    }

    public function test_late_payment_releases_same_package_checkout_reservation_without_replaced_by(): void
    {
        [$oldOrder, $newCheckout] = $this->ordersSharingOneStock();
        $oldOrder->update(['status' => 'cancelled']);
        $reservation = app(StockReservationService::class)->reserve($newCheckout);

        $license = app(OrderFulfillmentService::class)->fulfill($oldOrder);

        $this->assertSame($reservation->license_key, $license->license_key);
        $this->assertSame('paid', $oldOrder->fresh()->status);
        $this->assertSame('cancelled', $newCheckout->fresh()->status);
        $this->assertTrue($reservation->fresh()->is_sold);
    }

    public function test_pay_again_crypto_reservation_is_refreshed_to_new_invoice_expiry(): void
    {
        config([
            'services.crypto_direct.expires_minutes' => 60,
            'services.payments.reservation_grace_minutes' => 20,
            'services.crypto_direct.networks.usdtbsc.address' => '0x1111111111111111111111111111111111111111',
            'services.crypto_direct.networks.usdtbsc.contract' => '0x55d398326f99059fF775485246999027B3197955',
            'services.crypto_direct.networks.usdtbsc.rpc_url' => 'https://bsc-rpc.test',
        ]);

        [$order] = $this->ordersSharingOneStock();
        $order->update([
            'payment_method' => 'crypto',
            'price' => 1,
            'expired_at' => now()->addMinutes(10),
        ]);

        $result = app(PaymentService::class)->createCryptoPayment(
            $order->user,
            $order->product_id,
            $order->package_id,
            'usdtbsc',
            $order
        );

        $freshOrder = $result['order'];
        $reservation = LicenseStock::where('reserved_order_id', $freshOrder->id)->firstOrFail();

        $this->assertTrue(
            $reservation->reserved_until->equalTo(
                $freshOrder->expired_at->copy()->addMinutes(20)
            )
        );
    }

    public function test_pay_again_pakasir_uses_current_package_price(): void
    {
        config([
            'services.pakasir.slug' => 'aksaxiterz',
            'services.pakasir.api_key' => 'test-key',
            'services.pakasir.url' => 'https://app.pakasir.test',
            'services.pakasir.return_url' => 'https://aksaxiterz.test/orders',
        ]);

        Http::fake([
            'https://app.pakasir.test/api/transactioncreate/qris' => Http::response([
                'payment' => [
                    'project' => 'aksaxiterz',
                    'order_id' => 'ORDER-RESERVE-FIRST',
                    'amount' => 20000,
                    'fee' => 0,
                    'total_payment' => 20000,
                    'payment_method' => 'qris',
                    'payment_number' => '000201010212',
                    'expired_at' => now()->addHour()->toIso8601String(),
                ],
            ]),
        ]);

        [$order] = $this->ordersSharingOneStock();
        $order->package->update(['price' => 20000]);

        $result = app(PaymentService::class)->createPakasirPayment(
            $order->user,
            $order->product_id,
            $order->package_id,
            $order
        );

        $this->assertSame('20000.000000', $result['order']->price);
        $this->assertStringContainsString('/pay/aksaxiterz/20000?', $result['payment_url']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://app.pakasir.test/api/transactioncreate/qris'
            && $request['amount'] === 20000);
    }

    private function ordersSharingOneStock(): array
    {
        $user = User::factory()->create();
        $category = Category::create(['name' => 'Test', 'slug' => 'reservation-test']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Reservation Product',
            'slug' => 'reservation-product',
            'description' => 'Reservation test product.',
        ]);
        $package = Package::create([
            'product_id' => $product->id,
            'name' => '1 Day',
            'price' => 10000,
            'price_usdt' => 1,
        ]);

        LicenseStock::create([
            'product_id' => $product->id,
            'package_id' => $package->id,
            'license_key' => 'RESERVED-ONLY-KEY',
            'is_sold' => false,
        ]);

        $attributes = [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'status' => 'pending',
            'payment_method' => 'pakasir',
            'price' => 10000,
            'expired_at' => now()->addHour(),
        ];

        return [
            Order::create(array_merge($attributes, ['order_id' => 'ORDER-RESERVE-FIRST'])),
            Order::create(array_merge($attributes, ['order_id' => 'ORDER-RESERVE-SECOND'])),
        ];
    }
}
