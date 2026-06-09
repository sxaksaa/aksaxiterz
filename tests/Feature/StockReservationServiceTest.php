<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderFulfillmentService;
use App\Services\StockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
