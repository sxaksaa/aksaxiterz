<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PDO;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is not available in this PHP environment.');
        }

        parent::setUp();
    }

    public function test_today_sales_trend_uses_hourly_buckets(): void
    {
        config(['admin.emails' => ['admin@example.com']]);
        $this->travelTo(Carbon::parse('2026-06-24 14:30:00'));

        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $buyer = User::factory()->create(['email' => 'buyer@example.com']);
        $category = Category::create([
            'name' => 'Digital Tools',
            'slug' => 'digital-tools',
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Aurora-VN',
            'slug' => 'aurora-vn',
            'status' => Product::STATUS_READY,
            'description' => 'Test product.',
        ]);
        $package = Package::create([
            'product_id' => $product->id,
            'name' => '1 Day',
            'price' => 10000,
            'price_usdt' => 1.10,
        ]);

        $order = Order::create([
            'order_id' => 'ORDER-HOURLY-DASH',
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'status' => 'paid',
            'payment_method' => 'pakasir',
            'price' => 10000,
            'quantity' => 1,
        ]);
        $order->forceFill([
            'paid_at' => Carbon::parse('2026-06-24 13:15:00'),
            'created_at' => Carbon::parse('2026-06-24 13:10:00'),
            'updated_at' => Carbon::parse('2026-06-24 13:15:00'),
        ])->save();

        $binanceOrder = Order::create([
            'order_id' => 'ORDER-BINANCE-DASH',
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'status' => 'paid',
            'payment_method' => 'binance_pay',
            'price' => 1.10,
            'quantity' => 1,
            'payment_payload' => [
                'base_amount' => '1.100000',
                'final_amount' => '1.100000',
            ],
        ]);
        $binanceOrder->forceFill([
            'paid_at' => Carbon::parse('2026-06-24 09:45:00'),
            'created_at' => Carbon::parse('2026-06-24 09:40:00'),
            'updated_at' => Carbon::parse('2026-06-24 09:45:00'),
        ])->save();

        $this->actingAs($admin)
            ->get(route('admin.dashboard', ['range' => '1']))
            ->assertOk()
            ->assertSee('Hourly')
            ->assertSee('Daily')
            ->assertSee('Weekly')
            ->assertSee('Monthly')
            ->assertSee('Lifetime')
            ->assertSee('24 Jun 2026 by paid hour (WIB)')
            ->assertSee('All payments')
            ->assertSee('QRIS')
            ->assertSee('Binance')
            ->assertSee('Crypto')
            ->assertSee('All order value line')
            ->assertSee('00:00')
            ->assertSee('09:00')
            ->assertSee('13:00')
            ->assertSee('23:00')
            ->assertDontSee('Recent Orders');

        $this->actingAs($admin)
            ->get(route('admin.dashboard', ['range' => '1', 'method' => 'binance_pay']))
            ->assertOk()
            ->assertSee('Binance Pay crypto revenue line')
            ->assertDontSee('Recent Orders');
    }

    public function test_weekly_and_monthly_ranges_use_matching_chart_buckets(): void
    {
        config(['admin.emails' => ['admin@example.com']]);
        $this->travelTo(Carbon::parse('2026-06-24 14:30:00'));

        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $buyer = User::factory()->create(['email' => 'buyer@example.com']);
        $category = Category::create([
            'name' => 'Digital Tools',
            'slug' => 'digital-tools',
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Aurora-VN',
            'slug' => 'aurora-vn',
            'status' => Product::STATUS_READY,
            'description' => 'Test product.',
        ]);
        $package = Package::create([
            'product_id' => $product->id,
            'name' => '7 Days',
            'price' => 30000,
            'price_usdt' => 3.00,
        ]);

        $order = Order::create([
            'order_id' => 'ORDER-WEEKLY-DASH',
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'status' => 'paid',
            'payment_method' => 'pakasir',
            'price' => 30000,
            'quantity' => 1,
        ]);
        $order->forceFill([
            'paid_at' => Carbon::parse('2026-06-10 10:00:00'),
            'created_at' => Carbon::parse('2026-06-10 09:55:00'),
            'updated_at' => Carbon::parse('2026-06-10 10:00:00'),
        ])->save();

        $this->actingAs($admin)
            ->get(route('admin.dashboard', ['range' => 'weekly']))
            ->assertOk()
            ->assertSee('04 May 2026 - 24 Jun 2026 by paid week (WIB)')
            ->assertSee('08 Jun - 14 Jun')
            ->assertDontSee('Recent Orders');

        $this->actingAs($admin)
            ->get(route('admin.dashboard', ['range' => 'monthly']))
            ->assertOk()
            ->assertSee('Jul 2025 - Jun 2026 by paid month (WIB)')
            ->assertSee('Jun 2026')
            ->assertDontSee('Recent Orders');
    }

    public function test_hidden_products_are_excluded_from_low_stock_notice(): void
    {
        config([
            'admin.emails' => ['admin@example.com'],
            'admin.low_stock_threshold' => 3,
        ]);

        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $category = Category::create([
            'name' => 'Low Stock Products',
            'slug' => 'low-stock-products',
        ]);

        $visibleProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'AAA Visible Product',
            'slug' => 'aaa-visible-product',
            'status' => Product::STATUS_READY,
            'is_visible' => true,
            'description' => 'Visible product.',
        ]);
        $hiddenProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'AAA Hidden Product',
            'slug' => 'aaa-hidden-product',
            'status' => Product::STATUS_READY,
            'is_visible' => false,
            'description' => 'Hidden product.',
        ]);

        Package::create([
            'product_id' => $visibleProduct->id,
            'name' => 'Visible Package',
            'price' => 10000,
            'price_usdt' => 1,
        ]);
        Package::create([
            'product_id' => $hiddenProduct->id,
            'name' => 'Hidden Package',
            'price' => 10000,
            'price_usdt' => 1,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('AAA Visible Product - Visible Package')
            ->assertDontSee('AAA Hidden Product - Hidden Package');
    }
}
