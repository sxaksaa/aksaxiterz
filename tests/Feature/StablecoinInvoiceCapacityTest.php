<?php

namespace Tests\Feature;

use App\Exceptions\UnpaidInvoiceLimitException;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Services\BinancePayOrderVerifier;
use App\Services\CartService;
use App\Services\DirectCheckoutReview;
use App\Services\PaymentService;
use App\Services\StockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StablecoinInvoiceCapacityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'services.crypto_direct.max_unpaid_invoices' => 2,
            'services.crypto_direct.unique_max' => 4,
            'services.crypto_direct.recovery_hours' => 24,
            'services.crypto_direct.networks.usdtbsc.address' => '0x1111111111111111111111111111111111111111',
            'services.crypto_direct.networks.usdcbsc.address' => '0x2222222222222222222222222222222222222222',
            'services.binance.pay.enabled' => true,
            'services.binance.pay.pay_id' => 'capacity-test-payee',
            'services.binance.pay.api_key' => 'capacity-test-key',
            'services.binance.pay.api_secret' => 'capacity-test-secret',
            'services.binance.pay.max_unpaid_invoices' => 2,
            'services.binance.pay.unique_max' => 4,
            'services.binance.pay.recovery_hours' => 24,
        ]);
        $this->mock(BinancePayOrderVerifier::class)->shouldReceive('verify')
            ->andReturn(['status' => 'pending']);
    }

    public static function methods(): array
    {
        return [['crypto'], ['binance_pay']];
    }

    #[DataProvider('methods')]
    public function test_retry_budget_preserves_the_original_and_leaves_capacity_for_another_buyer(string $method): void
    {
        [$product, $package] = $this->catalog(2);
        $customer = User::factory()->create();
        $first = $this->payment($method, $customer, $product, $package);
        $firstKey = $first->payment_match_key;

        $this->actingAs($customer)->postJson('/pay-again/'.$first->id)->assertOk();
        $current = Order::findOrFail($first->fresh()->replaced_by);
        $currentKey = $current->payment_match_key;
        $this->postJson('/pay-again/'.$current->id)->assertStatus(429)
            ->assertJsonPath('redirect_url', url('/orders'));

        $this->assertSame('pending', $current->fresh()->status);
        $this->assertSame($currentKey, $current->fresh()->payment_match_key);
        $this->assertNull($current->fresh()->replaced_by);
        $this->assertSame($firstKey, $first->fresh()->payment_match_key);
        $this->assertSame(2, Order::where('user_id', $customer->id)->count());
        $this->assertSame(1, LicenseStock::where('reserved_order_id', $current->id)->count());
        $other = $this->payment($method, User::factory()->create(), $product, $package);
        $this->assertNotSame($currentKey, $other->payment_match_key);
        Http::assertNothingSent();
    }

    #[DataProvider('methods')]
    public function test_direct_dedicated_cart_and_service_paths_share_the_retained_budget(string $method): void
    {
        config([$this->setting($method, 'max_unpaid_invoices') => 1]);
        [$product, $package] = $this->catalog();
        $customer = User::factory()->create();
        $held = $this->payment($method, $customer, $product, $package);
        $this->cancelFixture($held);
        $input = $this->input($method, $package);

        $this->actingAs($customer)->postJson($this->endpoint($method, $product), $input)->assertStatus(429);
        $this->postJson(route('checkout.product.process', $product), $input + [
            'payment_method' => $method, 'quantity' => 1,
            'review_signature' => app(DirectCheckoutReview::class)->signature($customer, $product, $package, 1),
        ])->assertStatus(429);
        CartItem::create(['user_id' => $customer->id, 'product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]);
        $cart = app(CartService::class);
        $this->postJson(route('cart.checkout'), $input + [
            'payment_method' => $method, 'cart_signature' => $cart->signature($cart->items($customer)),
        ])->assertStatus(429);

        $this->assertSame(1, Order::where('user_id', $customer->id)->whereNotNull('payment_match_key')->count());
        $this->assertSame(1, CartItem::where('user_id', $customer->id)->count());
        $this->assertSame(1, LicenseStock::available()->count());
        $this->expectException(UnpaidInvoiceLimitException::class);
        if ($method === 'crypto') {
            app(PaymentService::class)->createCrypto($customer, $product->id, $package->id, 'usdcbsc');
        } else {
            app(PaymentService::class)->createBinancePayPayment($customer, $product->id, $package->id, selectedToken: 'USDC');
        }
    }

    #[DataProvider('methods')]
    public function test_existing_invoice_can_be_reinitialized_in_place_at_capacity(string $method): void
    {
        config([$this->setting($method, 'max_unpaid_invoices') => 1]);
        [$product, $package] = $this->catalog();
        $customer = User::factory()->create();
        $held = $this->payment($method, $customer, $product, $package);
        $reused = $this->payment($method, $customer, $product, $package, $held);

        $this->assertSame($held->id, $reused->id);
        $this->assertSame('pending', $reused->status);
        $this->assertSame(1, Order::where('user_id', $customer->id)->count());
        $this->assertSame(1, LicenseStock::where('reserved_order_id', $reused->id)->count());
    }

    public static function reusePaths(): array
    {
        return [['crypto', false], ['crypto', true], ['binance_pay', false], ['binance_pay', true]];
    }

    #[DataProvider('reusePaths')]
    public function test_lowering_the_budget_does_not_cancel_an_existing_invoice_on_reuse(string $method, bool $cart): void
    {
        [$product, $package] = $this->catalog();
        $customer = User::factory()->create();
        $previous = $this->payment($method, $customer, $product, $package);
        $this->cancelFixture($previous);
        $held = $this->payment($method, $customer, $product, $package);
        $key = $held->payment_match_key;
        config([$this->setting($method, 'max_unpaid_invoices') => 1]);

        if ($cart) {
            $service = app(PaymentService::class);
            $items = $held->items()->with(['product', 'package'])->get();
            $reused = ($method === 'crypto'
                ? $service->createCartCryptoPayment($customer, $items, 'usdtbsc', $held)
                : $service->createCartBinancePayPayment($customer, $items, 'USDT', $held))['order'];
        } else {
            $reused = $this->payment($method, $customer, $product, $package, $held);
        }

        $this->assertSame($held->id, $reused->id);
        $this->assertSame('pending', $held->fresh()->status);
        $this->assertSame($key, $reused->payment_match_key);
        $this->assertSame((float) $reused->price, (float) $reused->payment_payload['amount']);
        $this->assertSame(2, Order::where('user_id', $customer->id)->count());
        $this->assertSame(1, LicenseStock::where('reserved_order_id', $reused->id)->count());
    }

    #[DataProvider('methods')]
    public function test_paid_and_expired_cancelled_keys_release_capacity_at_the_recovery_deadline(string $method): void
    {
        config([$this->setting($method, 'max_unpaid_invoices') => 1]);
        [$product, $package] = $this->catalog();
        $customer = User::factory()->create();
        $paid = $this->payment($method, $customer, $product, $package);
        $paid->update(['status' => 'paid']);
        app(StockReservationService::class)->release($paid);
        $expired = $this->payment($method, $customer, $product, $package);
        $this->cancelFixture($expired);
        $expired->update(['expired_at' => now()->subHours(24)]);
        $next = $this->payment($method, $customer, $product, $package);

        $this->assertNull($paid->fresh()->payment_match_key);
        $this->assertNull($expired->fresh()->payment_match_key);
        $this->assertNotNull($next->payment_match_key);
    }

    #[DataProvider('methods')]
    public function test_old_pending_and_legacy_keys_still_consume_capacity(string $method): void
    {
        config([$this->setting($method, 'max_unpaid_invoices') => 1]);
        [$product, $package] = $this->catalog();
        $customer = User::factory()->create();
        Order::create([
            'order_id' => 'ORDER-OLD-PENDING', 'user_id' => $customer->id,
            'product_id' => $product->id, 'package_id' => $package->id,
            'price' => 1, 'status' => 'pending', 'payment_method' => $method,
            'payment_match_key' => hash('sha256', 'legacy-capacity-fixture'),
            'payment_payload' => null, 'expired_at' => now()->subHours(100),
        ]);

        $this->expectException(UnpaidInvoiceLimitException::class);
        $this->payment($method, $customer, $product, $package);
    }

    #[DataProvider('methods')]
    public function test_cancelled_keys_without_expiry_use_the_created_time_recovery_boundary(string $method): void
    {
        config([$this->setting($method, 'max_unpaid_invoices') => 1]);
        [$product, $package] = $this->catalog();
        $customer = User::factory()->create();
        $held = $this->payment($method, $customer, $product, $package);
        $this->cancelFixture($held);
        $held->forceFill(['expired_at' => null, 'created_at' => now()->subHours(25)->addSecond()])->save();
        try {
            $this->payment($method, $customer, $product, $package);
            $this->fail('A retained key inside the recovery boundary must consume capacity.');
        } catch (UnpaidInvoiceLimitException) {
            $this->assertNotNull($held->fresh()->payment_match_key);
        }
        $held->forceFill(['created_at' => now()->subHours(25)])->save();
        $this->assertNotNull($this->payment($method, $customer, $product, $package)->payment_match_key);
        $this->assertNull($held->fresh()->payment_match_key);
    }

    #[DataProvider('methods')]
    public function test_small_pool_keeps_one_slot_for_another_buyer(string $method): void
    {
        config([$this->setting($method, 'unique_max') => 2, $this->setting($method, 'max_unpaid_invoices') => 50]);
        [$product, $package] = $this->catalog(2);
        $customer = User::factory()->create();
        $held = $this->payment($method, $customer, $product, $package);
        $this->actingAs($customer)->postJson('/pay-again/'.$held->id)->assertStatus(429);
        $other = $this->payment($method, User::factory()->create(), $product, $package);
        $this->assertNotSame($held->payment_match_key, $other->payment_match_key);
    }

    public function test_binance_retry_still_verifies_an_observed_payment_before_applying_the_budget(): void
    {
        config(['services.binance.pay.max_unpaid_invoices' => 1]);
        [$product, $package] = $this->catalog();
        $customer = User::factory()->create();
        $held = $this->payment('binance_pay', $customer, $product, $package);
        $this->mock(BinancePayOrderVerifier::class)->shouldReceive('verify')->once()
            ->andReturnUsing(function (Order $order): array {
                $order->update(['status' => 'paid', 'payment_match_key' => null]);

                return ['status' => 'paid'];
            });

        $this->actingAs($customer)->postJson('/pay-again/'.$held->id)->assertConflict();
        $this->assertSame('paid', $held->fresh()->status);
        $this->assertNull($held->fresh()->replaced_by);
        $this->assertSame(1, Order::count());
    }

    public function test_denied_binance_retry_preserves_verification_progress(): void
    {
        config(['services.binance.pay.max_unpaid_invoices' => 1]);
        [$product, $package] = $this->catalog();
        $customer = User::factory()->create();
        $held = $this->payment('binance_pay', $customer, $product, $package);
        $this->mock(BinancePayOrderVerifier::class)->shouldReceive('verify')->once()
            ->andReturnUsing(function (Order $order): array {
                $order->update(['payment_payload' => $order->payment_payload + ['capacity_test_checked' => true]]);

                return ['status' => 'pending'];
            });

        $this->actingAs($customer)->postJson('/pay-again/'.$held->id)->assertStatus(429);
        $this->assertTrue($held->fresh()->payment_payload['capacity_test_checked']);
        $this->assertSame('pending', $held->fresh()->status);
        $this->assertNull($held->fresh()->replaced_by);
    }

    public function test_binance_verification_does_not_inherit_the_quota_transaction(): void
    {
        config(['services.binance.pay.max_unpaid_invoices' => 1]);
        [$product, $package] = $this->catalog();
        $customer = User::factory()->create();
        $held = $this->payment('binance_pay', $customer, $product, $package);
        $initialLevel = DB::transactionLevel();
        $this->mock(BinancePayOrderVerifier::class)->shouldReceive('verify')->once()
            ->andReturnUsing(function () use ($initialLevel): array {
                $this->assertSame($initialLevel, DB::transactionLevel());

                return ['status' => 'pending'];
            });

        $this->actingAs($customer)->postJson('/pay-again/'.$held->id)->assertStatus(429);
        $this->assertSame('pending', $held->fresh()->status);
    }

    #[DataProvider('methods')]
    public function test_single_slot_configuration_still_allows_first_checkout_and_in_place_reuse(string $method): void
    {
        config([$this->setting($method, 'unique_max') => 1]);
        [$product, $package] = $this->catalog();
        $customer = User::factory()->create();
        $held = $this->payment($method, $customer, $product, $package);
        $this->assertSame($held->id, $this->payment($method, $customer, $product, $package, $held)->id);
    }

    public static function budgetSettings(): array
    {
        return [
            ['crypto', 20, 20], ['binance_pay', 20, 20],
            ['crypto', 500, 50], ['binance_pay', 500, 50],
            ['crypto', 0, 1], ['binance_pay', 0, 1],
        ];
    }

    #[DataProvider('budgetSettings')]
    public function test_default_maximum_and_zero_budgets_do_not_allow_unbounded_accumulation(string $method, int $configured, int $allowed): void
    {
        config([$this->setting($method, 'unique_max') => 9999, $this->setting($method, 'max_unpaid_invoices') => $configured]);
        [$product, $package] = $this->catalog();
        $customer = User::factory()->create();
        for ($i = 1; $i < $allowed; $i++) {
            Order::create([
                'order_id' => 'ORDER-RETAINED-'.$i, 'user_id' => $customer->id,
                'product_id' => $product->id, 'package_id' => $package->id,
                'price' => 1, 'status' => 'cancelled', 'payment_method' => $method,
                'payment_match_key' => hash('sha256', 'retained-budget-'.$i),
                'expired_at' => now()->addMinutes(10),
            ]);
        }
        $last = $this->payment($method, $customer, $product, $package);
        $this->cancelFixture($last);
        $this->assertSame($allowed, Order::where('user_id', $customer->id)->whereNotNull('payment_match_key')->count());
        $this->expectException(UnpaidInvoiceLimitException::class);
        $this->payment($method, $customer, $product, $package);
    }

    private function setting(string $method, string $key): string
    {
        return ($method === 'crypto' ? 'services.crypto_direct.' : 'services.binance.pay.').$key;
    }

    private function input(string $method, Package $package): array
    {
        return ['package_id' => $package->id] + ($method === 'crypto' ? ['coin' => 'usdtbsc'] : ['token' => 'usdt']);
    }

    private function endpoint(string $method, Product $product): string
    {
        return ($method === 'crypto' ? '/pay-crypto/' : '/pay-binance/').$product->id;
    }

    private function payment(string $method, User $customer, Product $product, Package $package, ?Order $order = null): Order
    {
        $service = app(PaymentService::class);

        return ($method === 'crypto'
            ? $service->createCryptoPayment($customer, $product->id, $package->id, 'usdtbsc', $order)
            : $service->createBinancePayPayment($customer, $product->id, $package->id, $order))['order'];
    }

    private function cancelFixture(Order $order): void
    {
        $order->update(['status' => 'cancelled']);
        app(StockReservationService::class)->release($order);
    }

    private function catalog(int $count = 1): array
    {
        $category = Category::create(['name' => 'Invoice capacity', 'slug' => 'invoice-capacity']);
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Invoice capacity fixture', 'slug' => 'invoice-capacity',
            'description' => 'Synthetic invoice capacity fixture.', 'status' => Product::STATUS_READY, 'is_visible' => true,
        ]);
        $package = Package::create(['product_id' => $product->id, 'name' => '1 Day', 'price' => 15000, 'price_usdt' => 1]);
        for ($i = 0; $i < $count; $i++) {
            LicenseStock::create(['product_id' => $product->id, 'package_id' => $package->id, 'license_key' => 'SYNTHETIC-CAPACITY-'.$i, 'is_sold' => false]);
        }

        return [$product, $package];
    }
}
