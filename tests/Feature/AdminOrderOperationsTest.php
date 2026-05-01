<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\License;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
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

    public function test_admin_order_detail_shows_operations_context(): void
    {
        [$admin, $order] = $this->makePendingOrder();

        $response = $this->actingAs($admin)
            ->get(route('admin.orders.show', $order));

        $response->assertOk();
        $response->assertSee($order->order_id);
        $response->assertSee('Mark Paid');
        $response->assertSee('Resync License');
    }

    private function makePendingOrder(): array
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

        $order = Order::create([
            'order_id' => 'ORDER-ADMINTEST',
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'status' => 'pending',
            'payment_method' => 'pakasir',
            'price' => 10000,
            'expired_at' => now()->addMinutes(10),
        ]);

        $this->assertSame(0, License::count());

        return [$admin, $order];
    }
}
