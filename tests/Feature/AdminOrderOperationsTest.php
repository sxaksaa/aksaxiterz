<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\License;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

class AdminOrderOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is not available in this PHP environment.');
        }

        parent::setUp();
    }

    public function test_admin_can_mark_order_paid_and_deliver_license(): void
    {
        [$admin, $order] = $this->makePendingOrder();

        $response = $this->actingAs($admin)
            ->post(route('admin.orders.mark-paid', $order));

        $response->assertRedirect(route('admin.orders.show', $order));

        $order->refresh();

        $this->assertSame('paid', $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertDatabaseHas('licenses', [
            'order_id' => $order->order_id,
            'license_key' => 'TEST-LICENSE-KEY',
        ]);
        $this->assertDatabaseHas('license_stocks', [
            'license_key' => 'TEST-LICENSE-KEY',
            'is_sold' => true,
        ]);
    }

    public function test_admin_can_deliver_multiple_licenses_for_one_order(): void
    {
        [$admin, $order] = $this->makePendingOrder(['quantity' => 2]);
        LicenseStock::create([
            'product_id' => $order->product_id,
            'package_id' => $order->package_id,
            'license_key' => 'TEST-LICENSE-KEY-SECOND',
            'is_sold' => false,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.orders.mark-paid', $order))
            ->assertRedirect(route('admin.orders.show', $order));

        $this->assertSame(2, $order->licenses()->count());

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('2 / 2')
            ->assertSee('TEST-LICENSE-KEY')
            ->assertSee('TEST-LICENSE-KEY-SECOND')
            ->assertDontSee('Resync License');
    }

    public function test_admin_order_detail_shows_operations_context(): void
    {
        [$admin, $order] = $this->makePendingOrder();

        $response = $this->actingAs($admin)
            ->get(route('admin.orders.show', $order));

        $response->assertOk();
        $response->assertSee($order->order_id);
        $response->assertSee('Mark Paid');
        $response->assertDontSee('Resync License');
    }

    public function test_paid_order_without_license_shows_resync_license_only(): void
    {
        [$admin, $order] = $this->makePendingOrder([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.orders.show', $order));

        $response->assertOk();
        $response->assertSee('Resync License');
        $response->assertDontSee('Mark Paid');
    }

    public function test_admin_can_filter_paid_orders_with_incomplete_delivery(): void
    {
        [$admin, $incomplete] = $this->makePendingOrder();
        $incomplete->update(['status' => 'paid']);
        $complete = Order::create([
            'order_id' => 'ORDER-ADMIN-COMPLETE',
            'user_id' => $incomplete->user_id,
            'product_id' => $incomplete->product_id,
            'package_id' => $incomplete->package_id,
            'status' => 'pending',
            'payment_method' => 'pakasir',
            'price' => 10000,
            'expired_at' => now()->addMinutes(10),
        ]);
        LicenseStock::create([
            'product_id' => $complete->product_id,
            'package_id' => $complete->package_id,
            'license_key' => 'TEST-LICENSE-KEY-COMPLETE',
            'is_sold' => false,
        ]);
        app(OrderFulfillmentService::class)->fulfill($complete);

        $this->actingAs($admin)
            ->get(route('admin.orders.index', ['delivery' => 'incomplete']))
            ->assertOk()
            ->assertSee($incomplete->order_id)
            ->assertDontSee($complete->order_id)
            ->assertSee('Delivery issues');
    }

    public function test_paid_order_with_license_hides_manual_delivery_actions(): void
    {
        [$admin, $order] = $this->makePendingOrder([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        License::create([
            'user_id' => $order->user_id,
            'product_id' => $order->product_id,
            'license_key' => 'DELIVERED-LICENSE-KEY',
            'duration' => $order->package->name,
            'order_id' => $order->order_id,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.orders.show', $order));

        $response->assertOk();
        $response->assertDontSee('Mark Paid');
        $response->assertDontSee('Resync License');
    }

    public function test_fulfillment_delivers_oldest_available_license_stock_first(): void
    {
        [$admin, $order] = $this->makePendingOrder();

        $olderStock = LicenseStock::create([
            'product_id' => $order->product_id,
            'package_id' => $order->package_id,
            'license_key' => 'OLDER-LICENSE-KEY',
            'is_sold' => false,
        ]);
        $olderStock->forceFill([
            'created_at' => now()->subMonths(2),
            'updated_at' => now()->subMonths(2),
        ])->saveQuietly();

        $response = $this->actingAs($admin)
            ->post(route('admin.orders.mark-paid', $order));

        $response->assertRedirect(route('admin.orders.show', $order));

        $this->assertDatabaseHas('licenses', [
            'order_id' => $order->order_id,
            'license_key' => 'OLDER-LICENSE-KEY',
        ]);
        $this->assertDatabaseHas('license_stocks', [
            'license_key' => 'OLDER-LICENSE-KEY',
            'is_sold' => true,
        ]);
        $this->assertDatabaseHas('license_stocks', [
            'license_key' => 'TEST-LICENSE-KEY',
            'is_sold' => false,
        ]);
    }

    private function makePendingOrder(array $orderOverrides = []): array
    {
        config(['admin.emails' => ['admin@example.com']]);

        $admin = User::factory()->create([
            'email' => 'admin@example.com',
        ]);

        $user = User::factory()->create([
            'email' => 'buyer@example.com',
        ]);

        $category = Category::create([
            'name' => 'Digital Tools',
            'slug' => 'digital-tools',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'description' => 'Test product.',
        ]);

        $package = Package::create([
            'product_id' => $product->id,
            'name' => '1 Day',
            'price' => 10000,
            'price_usdt' => 1.10,
        ]);

        LicenseStock::create([
            'product_id' => $product->id,
            'package_id' => $package->id,
            'license_key' => 'TEST-LICENSE-KEY',
            'is_sold' => false,
        ]);

        $order = Order::create(array_merge([
            'order_id' => 'ORDER-ADMINTEST',
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'status' => 'pending',
            'payment_method' => 'pakasir',
            'price' => 10000,
            'expired_at' => now()->addMinutes(10),
        ], $orderOverrides));

        $this->assertSame(0, License::count());

        return [$admin, $order];
    }
}
