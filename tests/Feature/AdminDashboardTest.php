<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\GopayNotificationEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public function test_report_reads_matching_paid_orders_without_hydrating_models_and_keeps_legacy_date_fallback(): void
    {
        config(['admin.emails' => ['admin@example.com']]);
        $this->travelTo(Carbon::parse('2026-06-24 14:30:00'));
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $category = Category::create(['name' => 'Query Test', 'slug' => 'query-test']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Query Test', 'slug' => 'query-test', 'description' => 'Fixture']);
        foreach ([
            ['RECENT-PAID', 'pakasir', '2026-06-01', '2026-06-24', 10000],
            ['RECENT-LEGACY', 'pakasir', '2026-06-18', null, 20000],
            ['BEFORE-BOUNDARY', 'pakasir', '2026-06-17 23:59:59', null, 40000],
            ['OLD-PAID', 'pakasir', '2026-06-24', '2026-06-01', 80000],
            ['OTHER-METHOD', 'crypto', '2026-06-24', '2026-06-24', 10],
        ] as [$id, $method, $created, $paid, $price]) {
            $order = Order::create([
                'order_id' => 'ORDER-'.$id, 'user_id' => $admin->id, 'product_id' => $product->id,
                'status' => 'paid', 'payment_method' => $method, 'price' => $price,
            ]);
            $order->forceFill(['created_at' => Carbon::parse($created), 'paid_at' => $paid ? Carbon::parse($paid) : null])->save();
        }

        $retrieved = [];
        $capture = true;
        Order::retrieved(function (Order $order) use (&$retrieved, &$capture): void {
            if ($capture && $order->status === 'paid') {
                $retrieved[] = $order->order_id;
            }
        });
        try {
            $this->actingAs($admin)->get(route('admin.dashboard', ['range' => 'daily', 'method' => 'pakasir']))
                ->assertOk()->assertViewHas('selectedStats', fn ($stats) => $stats['orders'] === 2 && $stats['idr_revenue'] === 30000);
        } finally {
            $capture = false;
        }
        sort($retrieved);
        $this->assertSame([], $retrieved);

        $this->actingAs($admin)->get(route('admin.dashboard', ['range' => 'lifetime', 'method' => 'pakasir']))
            ->assertOk()->assertViewHas('selectedStats', fn ($stats) => $stats['orders'] === 4 && $stats['idr_revenue'] === 150000)
            ->assertSee('Since 01 Jun 2026');
    }

    public function test_dashboard_filter_fragment_keeps_metrics_and_access_controls_without_rebuilding_the_shell(): void
    {
        config(['admin.emails' => ['admin@example.com']]);
        $headers = ['X-Requested-With' => 'XMLHttpRequest', 'X-Aksa-Dashboard-Fragment' => '1'];
        $url = route('admin.dashboard', ['range' => 'lifetime']);
        $this->withHeaders($headers)->get($url)->assertRedirect();
        $this->actingAs(User::factory()->create())->withHeaders($headers)->get($url)->assertNotFound();
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $full = $this->actingAs($admin)->get($url, ['X-Requested-With' => '', 'X-Aksa-Dashboard-Fragment' => ''])
            ->assertOk()->assertSee('id="navbar"', false);
        $fragment = $this->actingAs($admin)->withHeaders($headers)->get($url)
            ->assertOk()->assertSee('id="aksaPageContent"', false)
            ->assertSee('Dashboard')->assertDontSee('id="navbar"', false)->assertDontSee('<footer', false);
        $this->assertStringContainsString('no-store', $fragment->headers->get('Cache-Control'));
        $this->assertSame($full->viewData('selectedStats'), $fragment->viewData('selectedStats'));
        $this->assertLessThan(strlen($full->getContent()), strlen($fragment->getContent()));
    }

    public function test_monthly_and_lifetime_keep_cart_snapshots_legacy_prices_and_crypto_fallback_amounts(): void
    {
        config(['admin.emails' => ['mixed-report@example.com']]);
        $this->travelTo(Carbon::parse('2026-06-24 14:30:00'));
        $admin = User::factory()->create(['email' => 'mixed-report@example.com']);
        $category = Category::create(['name' => 'Mixed Report', 'slug' => 'mixed-report']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Current Product', 'slug' => 'mixed-report', 'description' => 'Fixture']);
        $package = Package::create(['product_id' => $product->id, 'name' => '30 Days', 'price' => 10000]);
        $cart = Order::create([
            'order_id' => 'ORDER-REPORT-CART', 'user_id' => $admin->id, 'product_id' => $product->id,
            'package_id' => $package->id, 'status' => 'paid', 'payment_method' => 'crypto', 'price' => 2.51,
            'quantity' => 3, 'payment_payload' => ['base_amount' => 'invalid', 'final_amount' => '2.500000'],
            'paid_at' => Carbon::parse('2026-06-01 00:00:00'),
        ]);
        $otherPackage = Package::create(['product_id' => $product->id, 'name' => '7 Days', 'price' => 9000]);
        foreach ([['Original Product', 2, 20000], ['', 1, 9000]] as $index => [$name, $quantity, $total]) {
            OrderItem::create([
                'order_id' => $cart->id, 'product_id' => $product->id, 'package_id' => $index === 0 ? $package->id : $otherPackage->id,
                'product_name' => $name, 'package_name' => '30 Days', 'quantity' => $quantity,
                'unit_price_idr' => 10000, 'line_total_idr' => $total,
                'unit_price_usdt' => 1, 'line_total_usdt' => $quantity,
            ]);
        }
        $legacy = Order::create([
            'order_id' => 'ORDER-REPORT-LEGACY', 'user_id' => $admin->id, 'product_id' => $product->id,
            'package_id' => $package->id, 'status' => 'paid', 'payment_method' => 'pakasir', 'price' => 15100, 'quantity' => 1,
        ]);
        $legacy->forceFill(['created_at' => Carbon::parse('2026-05-31 23:59:59'), 'paid_at' => null])->save();
        $this->actingAs($admin);
        foreach (['monthly', 'lifetime'] as $range) {
            $response = $this->get(route('admin.dashboard', ['range' => $range]))->assertOk();
            $this->assertSame(['orders' => 2, 'licenses' => 0, 'idr_revenue' => 15100, 'crypto_revenue' => 2.5], $response->viewData('selectedStats'));
            $this->assertEquals(39000, $response->viewData('salesTrend')['points']->sum('line_revenue'));
            $this->assertEquals(2, $response->viewData('salesTrend')['points']->sum('orders'));
            $top = $response->viewData('topProducts')->keyBy('product');
            $this->assertSame(20000, $top['Original Product']['idr_total']);
            $this->assertSame(19000, $top['Current Product']['idr_total']);
            $this->assertSame(2, $top['Current Product']['quantity']);
            $this->assertSame(2, $response->viewData('paymentSplit')->sum('orders'));
        }
        $this->get(route('admin.dashboard', ['range' => 'monthly', 'method' => 'crypto']))
            ->assertOk()->assertViewHas('selectedStats', fn ($stats) => $stats['orders'] === 1 && $stats['crypto_revenue'] === 2.5)
            ->assertViewHas('salesTrend', fn ($trend) => $trend['points']->sum('line_revenue') === 2.5);
    }

    public function test_dashboard_fragment_performance_profile(): void
    {
        config(['admin.emails' => ['performance@example.com'], 'services.payments.traffic_cleanup_cache_store' => 'array']);
        $admin = User::factory()->create(['email' => 'performance@example.com']);
        $category = Category::create(['name' => 'Performance', 'slug' => 'performance']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Performance', 'slug' => 'performance', 'description' => 'Fixture']);
        $package = Package::create(['product_id' => $product->id, 'name' => '30 Days', 'price' => 20000]);
        $now = now();
        $rows = [];
        for ($index = 0; $index < 500; $index++) {
            $rows[] = [
                'order_id' => 'ORDER-PERF-'.$index, 'user_id' => $admin->id,
                'product_id' => $product->id, 'package_id' => $package->id,
                'status' => $index < 50 ? 'pending' : 'paid', 'payment_method' => 'pakasir',
                'price' => 20000, 'quantity' => 1, 'created_at' => $now, 'updated_at' => $now,
                'paid_at' => $index < 50 ? null : $now, 'expired_at' => $now->copy()->subHour(),
            ];
        }
        Order::insert($rows);
        Cache::store('array')->put('orders:traffic-expiry-cleanup', true, 300);
        $connection = DB::connection();
        $connection->enableQueryLog();
        try {
            $response = $this->actingAs($admin)->withHeaders([
                'X-Requested-With' => 'XMLHttpRequest', 'X-Aksa-Dashboard-Fragment' => '1',
            ])->get(route('admin.dashboard', ['range' => 'lifetime']))->assertOk();
            $queryCount = count($connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
        $response->assertHeader('Server-Timing');
        $this->assertSame(450, $response->viewData('selectedStats')['orders']);
        $this->assertSame(50, Order::where('status', 'pending')->count());
        if ($path = getenv('AKSA_PERFORMANCE_REPORT')) {
            file_put_contents($path, json_encode(['page' => 'dashboard-fragment', 'orders' => 500,
                'queries' => $queryCount, 'timing' => $response->headers->get('Server-Timing')]).PHP_EOL, FILE_APPEND);
        }
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
            'price' => 1.11,
            'quantity' => 1,
            'payment_payload' => [
                'base_amount' => '1.100000',
                'final_amount' => '1.110000',
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
            ->assertViewHas('selectedStats', fn ($stats) => $stats['orders'] === 2 && $stats['idr_revenue'] === 10000 && $stats['crypto_revenue'] === 1.1)
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
            ->assertSee('rx="4" fill="var(--aksa-purple-strong)"', false)
            ->assertDontSee('#38BDF8')
            ->assertSee('00:00')
            ->assertSee('09:00')
            ->assertSee('13:00')
            ->assertSee('23:00')
            ->assertDontSee('Recent Orders');

        $this->actingAs($admin)
            ->get(route('admin.dashboard', ['range' => '1', 'method' => 'binance_pay']))
            ->assertOk()
            ->assertViewHas('selectedStats', fn ($stats) => $stats['orders'] === 1 && $stats['idr_revenue'] === 0 && $stats['crypto_revenue'] === 1.1)
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

    public function test_failed_gopay_delivery_is_counted_as_an_attention_event(): void
    {
        config(['admin.emails' => ['admin@example.com']]);

        $admin = User::factory()->create(['email' => 'admin@example.com']);

        GopayNotificationEvent::create([
            'event_id' => str_repeat('a', 64),
            'device_id' => 'aksa-gopay-primary',
            'package_name' => 'com.gojek.gopaymerchant',
            'title' => 'Pembayaran QRIS statis diterima',
            'notification_text' => 'Rp1.000 di Aksa Xiterz',
            'amount_idr' => 1000,
            'notification_posted_at_ms' => now()->getTimestampMs(),
            'status' => 'matched_delivery_failed',
            'received_at' => now(),
            'last_received_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('qrisEventStats', fn (array $stats): bool => $stats['attention'] === 1);
    }
}
