<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\License;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

class AdminUserOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for admin user overview tests.');
        }

        parent::setUp();
    }

    public function test_admin_can_review_user_orders_paid_count_spend_and_licenses(): void
    {
        config(['admin.emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $customer = User::factory()->create([
            'name' => 'Aksa Buyer',
            'email' => 'buyer@example.com',
        ]);
        $category = Category::create([
            'name' => 'Digital Tools',
            'slug' => 'digital-tools',
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Aurora VN',
            'slug' => 'aurora-vn',
            'description' => 'Test product.',
        ]);
        $package = Package::create([
            'product_id' => $product->id,
            'name' => '30 Days',
            'price' => 100000,
            'price_usdt' => 5,
        ]);
        $paidQrisOrder = $this->orderWithItem($customer, $product, $package, [
            'order_id' => 'ORDER-USER-QRIS',
            'status' => 'paid',
            'payment_method' => 'pakasir',
            'price' => 90000,
            'paid_at' => now(),
        ]);
        $paidCryptoOrder = $this->orderWithItem($customer, $product, $package, [
            'order_id' => 'ORDER-USER-USDC',
            'status' => 'paid',
            'payment_method' => 'crypto',
            'price' => 4.501234,
            'payment_payload' => [
                'type' => 'direct_crypto',
                'token' => 'USDC',
                'base_amount' => '4.500000',
                'amount' => '4.501234',
            ],
            'paid_at' => now(),
        ]);
        $this->orderWithItem($customer, $product, $package, [
            'order_id' => 'ORDER-USER-PENDING',
            'status' => 'pending',
            'payment_method' => 'pakasir',
            'price' => 100000,
            'created_at' => now()->addMinute(),
        ]);
        License::create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'license_key' => 'USER-LICENSE-KEY-QRIS',
            'duration' => '30 Days',
            'order_id' => $paidQrisOrder->order_id,
            'order_item_id' => $paidQrisOrder->items()->first()->id,
        ]);
        License::create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'license_key' => 'USER-LICENSE-KEY-USDC',
            'duration' => '30 Days',
            'order_id' => $paidCryptoOrder->order_id,
            'order_item_id' => $paidCryptoOrder->items()->first()->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('buyer@example.com')
            ->assertSee('3 total')
            ->assertSee('2 paid')
            ->assertSee('ORDER-USER-PENDING')
            ->assertSee(route('admin.users.show', $customer), false);

        $this->actingAs($admin)
            ->get(route('admin.users.show', $customer))
            ->assertOk()
            ->assertSee('Aksa Buyer')
            ->assertSee('buyer@example.com')
            ->assertSee('ORDER-USER-QRIS')
            ->assertSee('ORDER-USER-USDC')
            ->assertSee('Rp 90.000')
            ->assertSee('$ 4.5')
            ->assertSee('USER-LICENSE-KEY-QRIS')
            ->assertSee('USER-LICENSE-KEY-USDC')
            ->assertSee('Aurora VN');
    }

    private function orderWithItem(User $user, Product $product, Package $package, array $overrides): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
            'expired_at' => now()->addMinutes(10),
        ], $overrides));

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'product_name' => $product->name,
            'package_name' => $package->name,
            'quantity' => 1,
            'unit_price_idr' => $package->price,
            'unit_price_usdt' => $package->price_usdt,
            'line_total_idr' => $package->price,
            'line_total_usdt' => $package->price_usdt,
        ]);

        return $order;
    }
}
