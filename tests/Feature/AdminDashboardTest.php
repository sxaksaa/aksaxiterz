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
            ->assertSee('Created: 24 Jun 2026, 13:10 WIB')
            ->assertSee('Paid: 24 Jun 2026, 13:15 WIB')
            ->assertSee('ORDER-HOURLY-DASH')
            ->assertSee('ORDER-BINANCE-DASH');

        $this->actingAs($admin)
            ->get(route('admin.dashboard', ['range' => '1', 'method' => 'binance_pay']))
            ->assertOk()
            ->assertSee('Binance Pay crypto revenue line')
            ->assertSee('ORDER-BINANCE-DASH')
            ->assertDontSee('ORDER-HOURLY-DASH');
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
            ->assertSee('ORDER-WEEKLY-DASH');

        $this->actingAs($admin)
            ->get(route('admin.dashboard', ['range' => 'monthly']))
            ->assertOk()
            ->assertSee('Jul 2025 - Jun 2026 by paid month (WIB)')
            ->assertSee('Jun 2026')
            ->assertSee('ORDER-WEEKLY-DASH');
    }
}
