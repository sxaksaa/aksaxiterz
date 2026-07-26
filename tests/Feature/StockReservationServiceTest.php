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
use PDO;
use Tests\TestCase;

class StockReservationServiceTest extends TestCase
{
    use RefreshDatabase;

    private const STATIC_QRIS = '00020101021126610014COM.GO-JEK.WWW01189360091438659284520210G8659284520303UMI51440014ID.CO.QRIS.WWW0215ID10243297931020303UMI5204729953033605802ID5911Aksa Xiterz6006MALANG61056515362070703A0163045DEF';

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
        $this->expectExceptionMessage('does not have enough license stock');

        $service->reserve($secondOrder);
    }

    public function test_expired_pending_qris_stock_stays_reserved_until_order_is_cancelled(): void
    {
        [$qrisOrder, $nextOrder] = $this->ordersSharingOneStock();
        $qrisOrder->update(['expired_at' => now()->subMinute()]);
        $service = app(StockReservationService::class);

        $reservation = $service->reserve($qrisOrder);

        $this->assertSame(0, $service->releaseExpiredReservations());
        $this->assertTrue($reservation->fresh()->isReserved());
        $this->assertSame(0, LicenseStock::available()->count());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('does not have enough license stock');

        $service->reserve($nextOrder);
    }

    public function test_expired_pending_crypto_stock_can_be_reused_during_verification_grace(): void
    {
        [$cryptoOrder, $nextOrder] = $this->ordersSharingOneStock();
        $cryptoOrder->update([
            'payment_method' => 'crypto',
            'expired_at' => now()->subMinute(),
        ]);
        $service = app(StockReservationService::class);

        $reservation = $service->reserve($cryptoOrder);

        $this->assertFalse($reservation->fresh()->isReserved());
        $this->assertSame(1, $service->releaseExpiredReservations());

        $replacement = $service->reserve($nextOrder);

        $this->assertSame($nextOrder->id, $replacement->fresh()->reserved_order_id);
    }

    public function test_cancelled_qris_stock_can_be_reused_before_original_reservation_deadline(): void
    {
        [$qrisOrder, $nextOrder] = $this->ordersSharingOneStock();
        $service = app(StockReservationService::class);
        $reservation = $service->reserve($qrisOrder);

        $qrisOrder->update(['status' => 'cancelled']);

        $replacement = $service->reserve($nextOrder);

        $this->assertSame($reservation->id, $replacement->id);
        $this->assertSame($nextOrder->id, $replacement->fresh()->reserved_order_id);
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

    public function test_quantity_reserves_and_fulfills_every_requested_license(): void
    {
        [$order] = $this->ordersSharingOneStock();
        $order->update(['quantity' => 7]);

        foreach (range(2, 7) as $index) {
            LicenseStock::create([
                'product_id' => $order->product_id,
                'package_id' => $order->package_id,
                'license_key' => "RESERVED-KEY-{$index}",
                'is_sold' => false,
            ]);
        }

        app(StockReservationService::class)->reserve($order);

        $this->assertSame(7, LicenseStock::where('reserved_order_id', $order->id)->count());

        app(OrderFulfillmentService::class)->fulfill($order);

        $this->assertSame(7, $order->licenses()->count());
        $this->assertSame(7, LicenseStock::where('package_id', $order->package_id)->where('is_sold', true)->count());
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
        app(StockReservationService::class)->reserve($order);

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

    public function test_reopened_gopay_qris_checkout_uses_current_package_price(): void
    {
        config([
            'services.gopay_qris.enabled' => true,
            'services.gopay_qris.static_payload' => self::STATIC_QRIS,
            'services.gopay_qris.merchant_name' => 'Aksa Xiterz',
            'services.gopay_qris.merchant_reference' => 'ID102432979310',
            'services.gopay_qris.expires_minutes' => 5,
            'services.gopay_qris.recovery_hours' => 72,
            'services.gopay_qris.unique_max' => 999,
            'services.gopay_qris.webhook_token' => 'reservation-checkout-token',
            'services.gopay_qris.webhook_secret' => 'reservation-checkout-secret',
            'services.gopay_qris.allowed_devices' => ['aksa-gopay-primary'],
            'services.payments.reservation_grace_minutes' => 0,
        ]);

        [$order] = $this->ordersSharingOneStock();
        $order->package->update(['price' => 20000]);
        $startedAt = now();

        $result = app(PaymentService::class)->createGopayQrisPayment(
            $order->user,
            $order->product_id,
            $order->package_id,
            $order
        );

        $payment = $result['gopay_qris_payment'];
        $this->assertSame(20000, (int) $payment['base_amount']);
        $this->assertSame((int) ceil(20000 / 0.993) - 20000, (int) $payment['platform_fee']);
        $this->assertGreaterThanOrEqual(1, (int) $payment['unique_amount']);
        $this->assertLessThanOrEqual(999, (int) $payment['unique_amount']);
        $this->assertSame((int) $result['order']->price, (int) $payment['total_payment']);
        $this->assertSame(self::STATIC_QRIS, $payment['qr_payload']);
        $this->assertNull($result['payment_url']);
        $this->assertTrue($result['order']->expired_at->between(
            $startedAt->copy()->addMinutes(4)->addSeconds(55),
            $startedAt->copy()->addMinutes(5)->addSeconds(5)
        ));
        $this->assertTrue(
            LicenseStock::where('reserved_order_id', $result['order']->id)
                ->firstOrFail()
                ->reserved_until
                ->equalTo($result['order']->expired_at)
        );
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
            'payment_method' => 'gopay_qris',
            'price' => 10000,
            'expired_at' => now()->addHour(),
        ];

        return [
            Order::create(array_merge($attributes, ['order_id' => 'ORDER-RESERVE-FIRST'])),
            Order::create(array_merge($attributes, ['order_id' => 'ORDER-RESERVE-SECOND'])),
        ];
    }
}
