<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Services\PendingOrderExpirationService;
use App\Services\StockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

class PendingOrderExpirationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for pending order expiration tests.');
        }

        parent::setUp();
    }

    public function test_expired_qris_and_past_grace_crypto_are_cancelled_and_release_stock(): void
    {
        config(['services.crypto_direct.grace_minutes' => 15]);

        [$qrisOrder, $cryptoOrder, $cryptoInGrace, $legacyOrder] = $this->orders();

        foreach ([$qrisOrder, $cryptoOrder, $cryptoInGrace, $legacyOrder] as $order) {
            app(StockReservationService::class)->reserve($order);
        }

        $summary = app(PendingOrderExpirationService::class)->expire();

        $this->assertSame(3, $summary['cancelled']);
        $this->assertSame('cancelled', $qrisOrder->fresh()->status);
        $this->assertSame('cancelled', $cryptoOrder->fresh()->status);
        $this->assertSame('cancelled', $legacyOrder->fresh()->status);
        $this->assertSame('pending', $cryptoInGrace->fresh()->status);
        $this->assertSame(0, LicenseStock::whereIn('reserved_order_id', [$qrisOrder->id, $cryptoOrder->id, $legacyOrder->id])->count());
        $this->assertSame(1, LicenseStock::where('reserved_order_id', $cryptoInGrace->id)->count());
    }

    public function test_expired_qris_is_not_released_when_provider_cancellation_fails(): void
    {
        config([
            'services.pakasir.slug' => 'aksaxiterz',
            'services.pakasir.api_key' => 'test-key',
            'services.pakasir.url' => 'https://app.pakasir.test',
        ]);
        Http::fake([
            'https://app.pakasir.test/api/transactioncancel' => Http::response([], 503),
        ]);

        [$qrisOrder] = $this->orders();
        $qrisOrder->update([
            'payment_payload' => [
                'payment_number' => '000201010212',
            ],
        ]);
        app(StockReservationService::class)->reserve($qrisOrder);

        app(PendingOrderExpirationService::class)->expire();

        $this->assertSame('pending', $qrisOrder->fresh()->status);
        $this->assertSame(1, LicenseStock::where('reserved_order_id', $qrisOrder->id)->reserved()->count());
    }

    private function orders(): array
    {
        $user = User::factory()->create();
        $category = Category::create(['name' => 'Expiry Test', 'slug' => 'expiry-test']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Expiry Product',
            'slug' => 'expiry-product',
            'description' => 'Expiry test product.',
        ]);
        $package = Package::create([
            'product_id' => $product->id,
            'name' => '1 Day',
            'price' => 10000,
            'price_usdt' => 1,
        ]);

        $attributes = [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'status' => 'pending',
            'price' => 10000,
        ];

        $legacyOrder = Order::create($attributes + [
            'order_id' => 'ORDER-LEGACY-NO-EXPIRY',
            'payment_method' => 'pakasir',
        ]);
        $legacyOrder->forceFill([
            'created_at' => now()->subHours(25),
            'updated_at' => now()->subHours(25),
        ])->saveQuietly();

        $orders = [
            Order::create($attributes + [
                'order_id' => 'ORDER-EXPIRED-QRIS',
                'payment_method' => 'pakasir',
                'expired_at' => now()->subMinute(),
            ]),
            Order::create($attributes + [
                'order_id' => 'ORDER-EXPIRED-CRYPTO',
                'payment_method' => 'crypto',
                'expired_at' => now()->subMinutes(16),
            ]),
            Order::create($attributes + [
                'order_id' => 'ORDER-CRYPTO-GRACE',
                'payment_method' => 'crypto',
                'expired_at' => now()->subMinutes(10),
            ]),
            $legacyOrder,
        ];

        foreach ($orders as $index => $order) {
            LicenseStock::create([
                'product_id' => $product->id,
                'package_id' => $package->id,
                'license_key' => "EXPIRY-SERVICE-{$index}",
                'is_sold' => false,
            ]);
        }

        return $orders;
    }
}
